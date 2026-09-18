<?php

namespace Keel\App\Controllers\Webhooks;

use Keel\App\Models\WebhookEvent;
use Keel\App\Services\BillingService;
use Keel\App\Services\CheckoutService;
use Keel\App\Services\MembershipService;
use Keel\App\Services\Payments\PaymentWebhooks;
use Keel\Core\Controller;
use Keel\Core\Database;
use Keel\Core\Env;
use Keel\Core\Request;
use Keel\Core\Response;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeObject;
use Stripe\Subscription;
use Stripe\Webhook;

/**
 * The one door Stripe knocks on.
 *
 * Three things happen before any handler runs, in this order, and none of them
 * is optional:
 *
 *   1. The signature is verified against the endpoint secret. An unsigned body
 *      is not a webhook, it is a stranger posting JSON at a public URL.
 *   2. The event id is claimed. Stripe retries anything it does not get a quick
 *      200 for, so the same event arrives more than once as a matter of course;
 *      the unique index on webhook_events decides which delivery does the work
 *      and the rest are answered 200 and dropped. This is what stops a retry
 *      becoming a second order.
 *   3. The handler runs, and only then is the claim marked handled. A handler
 *      that throws releases its claim and answers 500, so the retry that
 *      follows is allowed to try again rather than being told it is a duplicate
 *      of something that never happened.
 *
 * payment_intent.amount_capturable_updated is where an order is born: the
 * customer's card is now holding the authorization, which is the first moment
 * anything is true enough to write down, and it is deliberately not the client
 * redirect — a closed tab must not cost somebody their dinner.
 * customer.subscription.* keeps the membership table honest. PAYMENT_EVENTS
 * covers the four ways an order's money can change without this application
 * having asked.
 *
 * Everything else still goes to Keel's BillingService, which owns the starter's
 * own subscriptions and is not FairPlate's business.
 */
class StripeController extends Controller
{
    /**
     * The events FairPlate's money handling reacts to, and what handles each.
     *
     * A table rather than a chain of ifs, because the list is the interesting
     * part: these four are exactly the ways the truth about an order's money
     * can change without this application having asked for it, and anything
     * added here should have to justify itself against that sentence.
     *
     * @var array<string, string>
     */
    private const PAYMENT_EVENTS = [
        // A hold released by something other than our own rejection.
        'payment_intent.canceled' => 'paymentIntentCanceled',
        // Money given back, including from the Stripe dashboard.
        'charge.refunded' => 'chargeRefunded',
        // A restaurant or driver Stripe will no longer pay out to, or will again.
        'account.updated' => 'accountUpdated',
        // A transfer pulled back, by us or by a dispute.
        'transfer.reversed' => 'transferReversed',
    ];

    public function handle(Request $request): never
    {
        $payload = $request->rawBody();
        $signature = $request->headers['Stripe-Signature'] ?? $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $webhookSecret = trim((string) Env::get('STRIPE_WEBHOOK_SECRET', ''));

        if ($payload === '' || $signature === '' || $webhookSecret === '') {
            Response::json(['error' => 'Invalid webhook payload.'], 400);
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch (\UnexpectedValueException|SignatureVerificationException $exception) {
            Response::json(['error' => 'Webhook signature verification failed.'], 400);
        }

        $eventId = trim((string) $event->id);
        $type = (string) $event->type;

        if ($eventId === '') {
            // Nothing Stripe sends is missing an id, and an event that cannot be
            // identified cannot be made idempotent, so it is refused rather than
            // guessed at.
            Response::json(['error' => 'Webhook event has no id.'], 400);
        }

        if (!WebhookEvent::claim($eventId, $type)) {
            Response::json(['received' => true, 'duplicate' => true]);
        }

        try {
            $this->dispatch($event);
        } catch (\Throwable $exception) {
            $this->releaseClaim($eventId);
            error_log('[FairPlate] Stripe webhook ' . $type . ' failed: ' . $exception->getMessage());

            Response::json(['error' => 'Webhook handler failed.'], 500);
        }

        WebhookEvent::markHandled($eventId);

        Response::json(['received' => true]);
    }

    /**
     * Routes one verified, claimed event to whatever owns it.
     */
    private function dispatch(Event $event): void
    {
        $type = (string) $event->type;
        $object = $event->data->object;

        if ($type === 'payment_intent.amount_capturable_updated') {
            if ($object instanceof PaymentIntent) {
                (new CheckoutService())->placeFromPaymentIntent($object);
            }

            return;
        }

        if (isset(self::PAYMENT_EVENTS[$type])) {
            $method = self::PAYMENT_EVENTS[$type];
            (new PaymentWebhooks())->$method($this->asArray($object));

            return;
        }

        if (in_array($type, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ], true)) {
            $this->subscription($event, $object);

            return;
        }

        (new BillingService())->syncSubscriptionFromWebhook($event);
    }

    /**
     * A subscription event belongs to exactly one of the two systems sharing
     * this Stripe account, and the subscription itself says which.
     */
    private function subscription(Event $event, mixed $object): void
    {
        if (!$object instanceof Subscription) {
            return;
        }

        if (MembershipService::isMembershipSubscription($object)) {
            (new MembershipService())->syncFromSubscription($object);

            return;
        }

        // Keel's own plans. `created` is new here: BillingService learns about
        // those through checkout.session.completed, so there is nothing for it
        // to do and handing it one would only resolve the same user twice.
        if ((string) $event->type === 'customer.subscription.created') {
            return;
        }

        (new BillingService())->syncSubscriptionFromWebhook($event);
    }

    /**
     * A Stripe object as the plain array the payment handlers read.
     *
     * They work from arrays rather than typed objects because the four events
     * they answer arrive as four different classes, and the handlers want the
     * same two or three keys out of each. toArray() is Stripe's own recursive
     * conversion, so a nested refunds list or requirements array survives it.
     *
     * @return array<string, mixed>
     */
    private function asArray(mixed $object): array
    {
        if ($object instanceof StripeObject) {
            return $object->toArray();
        }

        return is_array($object) ? $object : [];
    }

    /**
     * Hands the event id back, so Stripe's retry is not mistaken for a replay of
     * something that succeeded.
     */
    private function releaseClaim(string $eventId): void
    {
        try {
            $statement = Database::connection()->prepare(
                'DELETE FROM webhook_events WHERE provider = ? AND event_id = ? AND handled_at IS NULL'
            );
            $statement->execute([WebhookEvent::PROVIDER_STRIPE, $eventId]);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Could not release webhook claim ' . $eventId . ': ' . $exception->getMessage());
        }
    }
}
