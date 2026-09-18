<?php

namespace Keel\App\Services\Payments;

use Keel\App\Models\CheckoutIntent;
use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\Payout;
use Keel\App\Models\Refund;
use Keel\App\Models\Restaurant;
use Keel\App\Services\OrderLifecycle;
use Keel\App\Services\OrderLifecycleException;
use Keel\Core\Activity;

/**
 * What Stripe tells us afterwards.
 *
 * Everything this application does to money it does on purpose, so in the happy
 * case these events say nothing it did not already know. They are here for the
 * other case: a refund issued from the Stripe dashboard, an account whose
 * payouts were disabled overnight, a transfer reversed by a dispute, an
 * authorization cancelled by something other than a rejection. Those are all
 * real, none of them starts in this codebase, and without these handlers the
 * database would quietly disagree with the money.
 *
 * Every handler is written to be run twice. The webhook controller's event log
 * already stops the same event id being worked twice, but Stripe can describe
 * the same fact in two different events, and a handler that only works because
 * something upstream deduplicated it is one delivery away from being wrong. So
 * each one asks what the row already says before writing.
 */
class PaymentWebhooks
{
    /**
     * A hold that is no longer held.
     *
     * Usually our own release, arriving back seconds after we asked for it, in
     * which case the order is already rejected or cancelled and there is
     * nothing to do. The case worth handling is the other one: an intent
     * cancelled from the dashboard or by Stripe's own expiry, leaving an order
     * still on somebody's screen with no money behind it.
     *
     * @param array<string, mixed> $object the payment_intent
     */
    public function paymentIntentCanceled(array $object): void
    {
        $paymentIntentId = trim((string) ($object['id'] ?? ''));

        if ($paymentIntentId === '') {
            return;
        }

        $order = Order::findByPaymentIntent($paymentIntentId);

        if ($order === null) {
            // No order was ever placed against it. The checkout that reserved it
            // is over either way.
            $intent = CheckoutIntent::findByPaymentIntent($paymentIntentId);

            if ($intent !== null && (string) $intent['status'] === CheckoutIntent::STATUS_PENDING) {
                CheckoutIntent::update((int) $intent['id'], ['status' => CheckoutIntent::STATUS_ABANDONED]);
            }

            return;
        }

        $orderId = (int) $order['id'];

        if (Order::isTerminal($order)) {
            return;
        }

        if (Order::isCaptured($order)) {
            // Stripe says the hold is gone and our own row says the money was
            // taken. One of those is wrong and neither is safe to act on.
            AdminAlert::raise('payment_intent.canceled_after_capture', sprintf(
                'Order %d is captured but its payment intent reports cancelled.',
                $orderId
            ), ['order_id' => $orderId, 'payment_intent' => $paymentIntentId]);

            return;
        }

        try {
            OrderLifecycle::transition($orderId, Order::STATUS_CANCELLED, [
                'cancel_reason' => 'The payment authorization was cancelled.',
            ]);
        } catch (OrderLifecycleException $exception) {
            AdminAlert::raise('payment_intent.canceled_mid_run', sprintf(
                'Order %d lost its authorization and could not be cancelled: %s',
                $orderId,
                $exception->getMessage()
            ), ['order_id' => $orderId, 'status' => (string) $order['status']]);
        }
    }

    /**
     * Money went back to a customer.
     *
     * A refund this application issued is already recorded, matched here on the
     * Stripe refund id. One issued from the dashboard is not, and the row is
     * written now so the receipt, the reconciliation and the absorbed
     * processing all agree with the card statement.
     *
     * A dashboard refund is never treated as a restaurant error. That is a
     * judgement an admin makes deliberately, and defaulting to it would reverse
     * a kitchen's money because somebody clicked Refund in Stripe.
     *
     * @param array<string, mixed> $object the charge
     */
    public function chargeRefunded(array $object): void
    {
        $chargeId = trim((string) ($object['id'] ?? ''));
        $order = $chargeId === '' ? null : Order::findByCharge($chargeId);

        if ($order === null) {
            return;
        }

        $orderId = (int) $order['id'];
        $refunds = (array) ($object['refunds']['data'] ?? []);

        foreach ($refunds as $refund) {
            $refundId = trim((string) ($refund['id'] ?? ''));
            $amountCents = (int) ($refund['amount'] ?? 0);

            if ($refundId === '' || $amountCents <= 0) {
                continue;
            }

            if (Refund::findByStripeRefund($refundId) !== null) {
                continue;
            }

            Refund::create([
                'order_id' => $orderId,
                'idempotency_key' => 'fp_refund_stripe_' . $refundId,
                'amount_cents' => $amountCents,
                'reason' => 'Refunded in Stripe: ' . (string) ($refund['reason'] ?? 'no reason given'),
                'restaurant_error' => false,
                'processing_absorbed_cents' => max(
                    0,
                    (int) ($order['stripe_fee_cents'] ?? 0) - Refund::absorbedForOrderCents($orderId)
                ),
                'stripe_refund_id' => $refundId,
            ]);

            Activity::log('order.refund_observed', 'Order', $orderId, [
                'stripe_refund_id' => $refundId,
                'amount_cents' => $amountCents,
            ]);
        }
    }

    /**
     * A connected account changed, and the only part of that we act on is
     * whether it can still be paid.
     *
     * Disabled payouts flip the flag and alert, so the next payout holds rather
     * than failing five times first. Re-enabled payouts flip it back and put
     * everything that was held back in the queue — which is the whole reason
     * held is a status rather than a failure: the work was never lost, it was
     * waiting for this event.
     *
     * @param array<string, mixed> $object the account
     */
    public function accountUpdated(array $object): void
    {
        $accountId = trim((string) ($object['id'] ?? ''));

        if ($accountId === '') {
            return;
        }

        $enabled = (bool) ($object['payouts_enabled'] ?? false);
        $owner = $this->ownerFor($accountId);

        if ($owner === null) {
            return;
        }

        [$kind, $row] = $owner;
        $was = (int) ($row['payouts_enabled'] ?? 1) === 1;

        if ($was !== $enabled) {
            $model = $kind === 'restaurant' ? Restaurant::class : Driver::class;
            $model::update((int) $row['id'], ['payouts_enabled' => $enabled ? 1 : 0]);

            Activity::log(
                'connect.payouts_' . ($enabled ? 'enabled' : 'disabled'),
                ucfirst($kind),
                (int) $row['id'],
                ['stripe_account_id' => $accountId]
            );

            if (!$enabled) {
                AdminAlert::raise('connect.payouts_disabled', sprintf(
                    'Stripe has disabled payouts for %s %d. Their transfers will hold.',
                    $kind,
                    (int) $row['id']
                ), [
                    'kind' => $kind,
                    'id' => (int) $row['id'],
                    'stripe_account_id' => $accountId,
                    'requirements' => implode(', ', array_map(
                        'strval',
                        (array) ($object['requirements']['currently_due'] ?? [])
                    )),
                ]);
            }
        }

        if (!$enabled) {
            return;
        }

        // The release is attempted whether or not the flag moved. An account
        // updated for some other reason — a bank account added, a requirement
        // cleared — can be the event that makes a payout held for a different
        // reason sendable, and held work that nobody puts back is held work
        // that was lost after all.
        $payouts = new PayoutService();
        $released = 0;

        foreach (Payout::heldForAccount($accountId) as $payout) {
            $released += $payouts->release($payout) ? 1 : 0;
        }

        if ($released > 0) {
            Activity::log('payout.released_batch', ucfirst($kind), (int) $row['id'], [
                'stripe_account_id' => $accountId,
                'released' => $released,
            ]);
        }
    }

    /**
     * A transfer came back.
     *
     * Ours, when an admin marked an order a restaurant error — already recorded
     * by then and matched on the amount. Somebody else's when Stripe reverses a
     * transfer on its own, which means a payout this application believes was
     * made is not money the recipient still has, and the payout row has to say
     * so or every earnings screen built on it is wrong.
     *
     * @param array<string, mixed> $object the transfer
     */
    public function transferReversed(array $object): void
    {
        $transferId = trim((string) ($object['id'] ?? ''));
        $payout = $transferId === '' ? null : Payout::findByTransfer($transferId);

        if ($payout === null) {
            return;
        }

        $reversedCents = (int) ($object['amount_reversed'] ?? 0);

        if ($reversedCents === (int) $payout['reversed_cents']) {
            return;
        }

        Payout::update((int) $payout['id'], ['reversed_cents' => $reversedCents]);

        Activity::log('payout.reversed', 'Order', (int) $payout['order_id'], [
            'payout_id' => (int) $payout['id'],
            'type' => (string) $payout['type'],
            'amount_cents' => (int) $payout['amount_cents'],
            'reversed_cents' => $reversedCents,
        ]);

        // A driver's pay being pulled back is not something this application
        // ever does. Somebody needs to know why it happened.
        if ((string) $payout['type'] !== Payout::TYPE_RESTAURANT) {
            AdminAlert::raise('payout.reversed', sprintf(
                'A %s transfer of %d on order %d was reversed by %d.',
                (string) $payout['type'],
                (int) $payout['amount_cents'],
                (int) $payout['order_id'],
                $reversedCents
            ), [
                'order_id' => (int) $payout['order_id'],
                'payout_id' => (int) $payout['id'],
                'transfer_id' => $transferId,
                'reversed_cents' => $reversedCents,
            ]);
        }
    }

    /**
     * Which side of the marketplace a connected account belongs to.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function ownerFor(string $accountId): ?array
    {
        $restaurant = Restaurant::findByStripeAccount($accountId);

        if ($restaurant !== null) {
            return ['restaurant', $restaurant];
        }

        $driver = Driver::findByStripeAccount($accountId);

        return $driver === null ? null : ['driver', $driver];
    }
}
