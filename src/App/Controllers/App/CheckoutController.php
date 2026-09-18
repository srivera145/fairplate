<?php

namespace Keel\App\Controllers\App;

use Keel\App\Models\Address;
use Keel\App\Models\Cart;
use Keel\App\Models\CheckoutIntent;
use Keel\App\Services\CheckoutService;
use Keel\App\Services\MembershipService;
use Keel\App\Services\Pricing\Breakdown;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Pricing\PricingException;
use Keel\App\Services\StripeClientFactory;
use Keel\Core\Request;

/**
 * Checkout: the breakdown, the tip, the card, and the wait.
 *
 * Everything on this screen is a number the server worked out. The tip control
 * posts a *choice* — a percentage or a typed amount — and gets the whole
 * breakdown back as markup, so the page never adds anything up and there is no
 * arithmetic in a script that could disagree with the charge. That is also why
 * the quote endpoint returns rendered HTML rather than a bag of cents: the same
 * view renders it on first paint and on every tip change.
 *
 * The button says what the card will be held for, and the line beneath it says
 * what the worst case is. Those are the two Breakdowns a quote produces: the
 * estimate has no wait pay because the driver has not waited, and the
 * authorization has it at the cap because that is the most this order can ever
 * become. Nothing in between is ever charged.
 *
 * Pressing pay confirms with Stripe and comes back to complete(), which is a
 * waiting room and not a receipt. The order is written by the webhook.
 */
class CheckoutController extends CustomerController
{
    /** How long complete() keeps waiting for the webhook before offering help. */
    private const WAIT_SECONDS = 20;

    public function index(Request $request): void
    {
        $userId = $this->userId();
        $prepared = $this->prepare($userId);

        if ($prepared['summary']['lines'] === []) {
            $this->back('/app/cart', 'Your cart is empty.', 'bad');
        }

        $this->view('app.checkout', array_merge(
            $this->shell('cart', 'Checkout'),
            $this->screen($prepared),
            [
                'addresses' => Address::forUser($userId),
                'publishableKey' => StripeClientFactory::publishableKey(),
            ]
        ));
    }

    /**
     * Re-price after a tip change, and move the authorization with it.
     *
     * Returns the same markup the page was rendered with, so a tip change and a
     * fresh page load can never show different breakdowns.
     */
    public function quote(Request $request): void
    {
        $userId = $this->userId();
        $this->applyTip($request, $userId);

        $prepared = $this->prepare($userId);
        $screen = $this->screen($prepared);

        $this->json([
            'ok' => $prepared['ok'],
            'errors' => $prepared['errors'],
            'client_secret' => $prepared['client_secret'],
            'estimate_cents' => $screen['estimateTotal'],
            'authorized_cents' => $screen['authorizedTotal'],
            'html' => $this->renderToString('app.partials.breakdown', $screen),
        ]);
    }

    /**
     * The tip the customer picked, recorded as the choice it was.
     */
    private function applyTip(Request $request, int $userId): void
    {
        $mode = (string) $request->input('tip_mode', Cart::TIP_PERCENT);

        if ($mode === Cart::TIP_CUSTOM) {
            try {
                $cents = Money::fromDollars((string) $request->input('tip_dollars', '0'));
            } catch (PricingException $exception) {
                $cents = 0;
            }

            $this->cart()->setTip($userId, Cart::TIP_CUSTOM, $cents);

            return;
        }

        $this->cart()->setTip($userId, Cart::TIP_PERCENT, (int) $request->input('tip_basis_points', Cart::DEFAULT_TIP_BASIS_POINTS));
    }

    /**
     * Back from Stripe, before the webhook has necessarily landed.
     *
     * This page cannot create the order and does not try. It looks for the one
     * the webhook wrote and, while there is none, waits — a meta refresh, so
     * the waiting works with no JavaScript at all. A customer who closes the tab
     * here still gets their dinner, which is the entire reason the order is not
     * created on this request.
     */
    public function complete(Request $request): void
    {
        $userId = $this->userId();
        $paymentIntentId = trim((string) $request->input('payment_intent', ''));
        $intent = $paymentIntentId === ''
            ? CheckoutIntent::pendingForUser($userId)
            : CheckoutIntent::forUserAndPaymentIntent($userId, $paymentIntentId);

        if ($intent === null) {
            $this->back('/app/orders', 'We could not find that payment.', 'bad');
        }

        if ($intent['order_id'] !== null) {
            $this->redirect('/app/orders/' . (int) $intent['order_id']);
        }

        $waited = max(0, (int) $request->input('waited', 0));

        $this->view('app.checkout-complete', array_merge(
            $this->shell('cart', 'Confirming your order'),
            [
                'paymentIntentId' => (string) $intent['stripe_payment_intent_id'],
                'authorizedCents' => (int) $intent['authorized_cents'],
                'waited' => $waited,
                'giveUp' => $waited >= self::WAIT_SECONDS,
            ]
        ));
    }

    /**
     * Prices the cart, and does not let a Stripe outage hide the breakdown.
     *
     * A checkout that cannot reach Stripe still has a true total to show and a
     * reason the button is off. Rendering a blank page instead would be a worse
     * answer to the same problem.
     *
     * @return array<string, mixed>
     */
    private function prepare(int $userId): array
    {
        $checkout = new CheckoutService();

        try {
            return $checkout->prepare($userId);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Could not ready a payment: ' . $exception->getMessage());
        }

        try {
            $prepared = $checkout->prepare($userId, false);
            $prepared['errors'][] = 'Card payments are unavailable right now. Your cart is saved.';
            $prepared['ok'] = false;

            return $prepared;
        } catch (\Throwable $exception) {
            // The pricing itself failed — a missing setting, a restaurant with no
            // tax rate. There is no true total to show, and a 500 on the one page
            // holding somebody's dinner is the worst possible answer.
            error_log('[FairPlate] Could not price a checkout: ' . $exception->getMessage());

            return [
                'ok' => false,
                'errors' => ['We could not price this order right now. Your cart is saved — please try again shortly.'],
                'summary' => $this->cart()->summary($userId),
                'address' => null,
                'quote' => null,
                'intent' => null,
                'client_secret' => null,
            ];
        }
    }

    /**
     * Everything the checkout view and the breakdown partial read.
     *
     * @param array<string, mixed> $prepared
     * @return array<string, mixed>
     */
    private function screen(array $prepared): array
    {
        $summary = $prepared['summary'];
        $quote = $prepared['quote'];
        $cart = $summary['cart'];

        /** @var Breakdown|null $estimate */
        $estimate = $quote['estimate'] ?? null;
        /** @var Breakdown|null $authorization */
        $authorization = $quote['authorization'] ?? null;

        $snapshot = $estimate === null ? [] : $estimate->settingsSnapshot();

        return [
            'summary' => $summary,
            'address' => $prepared['address'],
            'errors' => $prepared['errors'],
            'payable' => $prepared['ok'],
            'clientSecret' => $prepared['client_secret'],
            'estimate' => $estimate,
            'authorization' => $authorization,
            'estimateTotal' => $estimate === null ? 0 : $estimate->total(),
            'authorizedTotal' => $authorization === null ? 0 : $authorization->total(),
            'waitCapCents' => (int) ($snapshot['driver_wait_cap_cents'] ?? 0),
            'routeMiles' => (float) ($quote['route_miles'] ?? 0.0),
            'tipMode' => (string) ($cart['tip_mode'] ?? Cart::TIP_PERCENT),
            'tipBasisPoints' => (int) ($cart['tip_basis_points'] ?? Cart::DEFAULT_TIP_BASIS_POINTS),
            'tipCents' => $estimate === null ? 0 : $estimate->line(Breakdown::TIP),
            'isMember' => (bool) ($quote['is_member'] ?? false),
            'memberNudgeCents' => $this->nudgeCents($estimate, (bool) ($quote['is_member'] ?? false)),
        ];
    }

    /**
     * What to put in the non-member nudge, or zero for no nudge.
     *
     * The spec allows the claim only when it is true, so the number is what a
     * membership would actually have been worth this month — this order
     * included — after its own price is taken off. A non-positive answer means
     * there is nothing honest to say, and nothing is said.
     */
    private function nudgeCents(?Breakdown $estimate, bool $isMember): int
    {
        if ($isMember || $estimate === null) {
            return 0;
        }

        try {
            $savings = (new MembershipService())->savingsThisMonthCents($this->userId(), $estimate);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Could not work out membership savings: ' . $exception->getMessage());

            return 0;
        }

        return max(0, $savings);
    }
}
