<?php

namespace Keel\App\Services\Payments;

use Keel\App\Jobs\TransferPayoutJob;
use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Models\Payout;
use Keel\App\Models\Refund;
use Keel\App\Models\Restaurant;
use Keel\App\Models\TipAdjustment;
use Keel\Core\Activity;
use Keel\Core\Queue;

/**
 * Who is owed what, and the row that remembers it.
 *
 * Every transfer amount FairPlate ever makes is decided here, from the final
 * breakdown and nothing else. That is the spec's rule — no transfer amount is
 * computed outside PricingService or this class — and it is also what makes the
 * money add up: the breakdown balances by construction, so
 *
 *     restaurant + driver + platform fee + service fee = the captured total
 *
 * falls out of the arithmetic rather than being checked after the fact.
 *
 * The two shares are the spec's, verbatim:
 *
 *   The restaurant gets subtotal + tax, in full. Nothing is deducted — no
 *   commission, no percentage, no share of processing. A restaurant's payout
 *   does not know the platform exists.
 *
 *   The driver gets driver_guaranteed + wait_pay + tip. The guarantee is the
 *   one they accepted, the wait pay is what the clock actually said, and the
 *   tip is whole.
 *
 * Between deciding the amount and sending it there is a row. queue() writes the
 * payouts rows and hands them to a job; nothing here talks to Stripe. That
 * split is what makes a replayed webhook harmless: claiming a row on its
 * idempotency key either finds the work already recorded or records it once,
 * and the transfer that follows carries the same key to Stripe.
 */
class PayoutService
{
    /**
     * Guarded against the offer card: the guarantee a driver accepted may not
     * move by more than this, which is to say at all.
     */
    public const GUARANTEE_TOLERANCE_CENTS = 0;

    // -----------------------------------------------------------------
    // The amounts
    // -----------------------------------------------------------------

    /**
     * subtotal + tax, in full.
     *
     * @param array<string, mixed> $finalRow an order_price_breakdown row
     */
    public function restaurantAmountCents(array $finalRow): int
    {
        return (int) ($finalRow['subtotal_cents'] ?? 0) + (int) ($finalRow['tax_cents'] ?? 0);
    }

    /**
     * driver_guaranteed + wait_pay + tip.
     *
     * @param array<string, mixed> $finalRow an order_price_breakdown row
     */
    public function driverAmountCents(array $finalRow): int
    {
        return (int) ($finalRow['driver_guaranteed_cents'] ?? 0)
            + (int) ($finalRow['wait_pay_cents'] ?? 0)
            + (int) ($finalRow['tip_cents'] ?? 0);
    }

    /**
     * What FairPlate keeps: the platform fee, plus the service fee that covers
     * the card cost.
     *
     * Not transferred anywhere — it is what is left in the platform balance
     * once the two transfers have gone out — but it is computed here so that a
     * reconciliation screen and a test can ask for it rather than subtracting
     * by hand and getting the definition slightly wrong.
     *
     * @param array<string, mixed> $finalRow
     */
    public function platformRetainedCents(array $finalRow): int
    {
        return (int) ($finalRow['platform_fee_cents'] ?? 0) + (int) ($finalRow['service_fee_cents'] ?? 0);
    }

    /**
     * The whole tip delta. Tips go 100% to the driver, and a raise is a tip.
     */
    public function tipAdjustmentAmountCents(int $deltaCents): int
    {
        if ($deltaCents <= 0) {
            throw new PaymentException('A tip adjustment payout needs a positive delta.');
        }

        return $deltaCents;
    }

    /**
     * How much of a refund comes back out of the restaurant's transfer.
     *
     * Only a refund an admin marked a restaurant error reaches here at all; the
     * platform absorbs every other one, which is the spec's default and the
     * reason a kitchen never sees a deduction it did not cause.
     *
     * Capped twice: at what was actually transferred, because a reversal cannot
     * exceed a transfer, and at what has not already been pulled back, because
     * two partial refunds on one order must not reverse the same money twice.
     */
    public function restaurantReversalCents(int $orderId, int $refundAmountCents): int
    {
        $paid = Payout::paidCents($orderId, Payout::TYPE_RESTAURANT);
        $alreadyReversed = Refund::reversedRestaurantCents($orderId);

        return max(0, min($refundAmountCents, $paid - $alreadyReversed));
    }

    // -----------------------------------------------------------------
    // Queueing the work
    // -----------------------------------------------------------------

    /**
     * Records both payouts for a delivered order and queues the transfers.
     *
     * Called once, from the capture. Called twice, it finds both rows already
     * claimed and queues nothing, which is what a replayed delivered event or a
     * retried job has to come to.
     *
     * @param array<string, mixed> $order
     * @return list<array<string, mixed>> the payout rows, whether new or not
     */
    public function queueForOrder(array $order): array
    {
        $orderId = (int) $order['id'];
        $final = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);

        if ($final === null) {
            throw new PaymentException("Order {$orderId} has no final breakdown to pay out from.");
        }

        $driverAmount = $this->driverAmountCents($final);
        $this->assertGuaranteeMatchesOffer($orderId, (int) ($final['driver_guaranteed_cents'] ?? 0));

        $rows = [];

        $rows[] = $this->claim(
            $orderId,
            Payout::TYPE_RESTAURANT,
            $this->restaurantKey($orderId),
            $this->restaurantAccount($order),
            $this->restaurantAmountCents($final)
        );

        $rows[] = $this->claim(
            $orderId,
            Payout::TYPE_DRIVER,
            $this->driverKey($orderId),
            $this->driverAccount($order),
            $driverAmount
        );

        return array_values(array_filter($rows));
    }

    /**
     * Records the driver's share of a tip raise and queues it.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $adjustment the settled tip_adjustments row
     */
    public function queueTipAdjustment(array $order, array $adjustment): ?array
    {
        $orderId = (int) $order['id'];
        $deltaCents = (int) $adjustment['delta_cents'];

        return $this->claim(
            $orderId,
            Payout::TYPE_DRIVER_TIP_ADJUST,
            $this->tipKey($orderId, (int) $adjustment['id']),
            $this->driverAccount($order),
            $this->tipAdjustmentAmountCents($deltaCents)
        );
    }

    /**
     * Puts a held payout back in the queue.
     *
     * What account.updated calls when Stripe says a recipient can be paid
     * again. The recipient account is re-read rather than trusted, because the
     * reason it was held may have been that there was no account at all.
     *
     * @param array<string, mixed> $payout
     */
    public function release(array $payout): bool
    {
        if ((string) $payout['status'] !== Payout::STATUS_HELD) {
            return false;
        }

        Payout::update((int) $payout['id'], [
            'status' => Payout::STATUS_PENDING,
            'attempts' => 0,
            'last_error' => null,
        ]);

        Queue::push(TransferPayoutJob::class, ['payout_id' => (int) $payout['id']]);

        Activity::log('payout.released', 'Order', (int) $payout['order_id'], [
            'payout_id' => (int) $payout['id'],
            'type' => (string) $payout['type'],
        ]);

        return true;
    }

    // -----------------------------------------------------------------
    // Recipients
    // -----------------------------------------------------------------

    /**
     * The connected account this payout is for, and whether Stripe will let it
     * be paid.
     *
     * Both halves matter and they fail differently: no account at all means
     * somebody never finished onboarding, and payouts disabled means Stripe has
     * stopped them. Either way the transfer is held rather than dropped, so the
     * caller gets the reason rather than a boolean.
     *
     * @param array<string, mixed> $payout
     * @return array{account: string, enabled: bool, reason: string|null}
     */
    public function recipientFor(array $payout): array
    {
        $order = Order::find((int) $payout['order_id']);

        if ($order === null) {
            return ['account' => '', 'enabled' => false, 'reason' => 'The order has gone.'];
        }

        if ((string) $payout['type'] === Payout::TYPE_RESTAURANT) {
            $restaurant = Restaurant::find((int) $order['restaurant_id']);

            return $this->recipient(
                $restaurant === null ? '' : (string) ($restaurant['stripe_account_id'] ?? ''),
                $restaurant !== null && (int) ($restaurant['payouts_enabled'] ?? 1) === 1,
                'restaurant'
            );
        }

        $driverId = $order['driver_id'] ?? null;
        $driver = $driverId === null ? null : Driver::find((int) $driverId);

        return $this->recipient(
            $driver === null ? '' : (string) ($driver['stripe_account_id'] ?? ''),
            $driver !== null && (int) ($driver['payouts_enabled'] ?? 1) === 1,
            'driver'
        );
    }

    // -----------------------------------------------------------------
    // The guarantee
    // -----------------------------------------------------------------

    /**
     * The driver is paid the guarantee the offer card showed, or nobody is paid.
     *
     * The card's number is stored on the offer at the moment it is made. This
     * compares the breakdown the capture settled against that promise, so a
     * setting edited mid-run, a breakdown rewritten by hand or a bug in the
     * recomputation shows up as a stopped payout and an alert rather than as a
     * driver quietly paid less than they agreed to.
     *
     * An order nobody was dispatched for — an admin finishing a run by hand —
     * has no promise to check against, so there is nothing to disagree with.
     */
    public function assertGuaranteeMatchesOffer(int $orderId, int $guaranteedCents): void
    {
        $offer = DispatchOffer::acceptedForOrder($orderId);

        if ($offer === null || ($offer['guaranteed_cents'] ?? null) === null) {
            return;
        }

        $promised = (int) $offer['guaranteed_cents'];

        if (abs($promised - $guaranteedCents) <= self::GUARANTEE_TOLERANCE_CENTS) {
            return;
        }

        AdminAlert::raise('payout.guarantee_mismatch', sprintf(
            'Order %d would pay a driver %d against a promised %d.',
            $orderId,
            $guaranteedCents,
            $promised
        ), [
            'order_id' => $orderId,
            'promised_cents' => $promised,
            'computed_cents' => $guaranteedCents,
        ]);

        throw new PaymentException(sprintf(
            'Order %d promised the driver %d and the final breakdown says %d.',
            $orderId,
            $promised,
            $guaranteedCents
        ));
    }

    // -----------------------------------------------------------------
    // Idempotency keys
    // -----------------------------------------------------------------

    /**
     * The key for the restaurant's transfer on this order.
     *
     * One per order per recipient, derived rather than random, so the second
     * caller computes the same string and both the unique index and Stripe
     * recognise it.
     */
    public function restaurantKey(int $orderId): string
    {
        return 'fp_payout_' . $orderId . '_restaurant';
    }

    public function driverKey(int $orderId): string
    {
        return 'fp_payout_' . $orderId . '_driver';
    }

    /**
     * A tip raise is its own payout, so the key carries the adjustment: a
     * customer may raise the tip twice inside the window and those are two
     * transfers, not one retried.
     */
    public function tipKey(int $orderId, int $adjustmentId): string
    {
        return 'fp_payout_' . $orderId . '_tip_' . $adjustmentId;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Records one payout and queues its transfer, unless it is already there.
     *
     * @return array<string, mixed>|null the row, or null when there was nothing
     *         to pay
     */
    private function claim(
        int $orderId,
        string $type,
        string $idempotencyKey,
        string $account,
        int $amountCents
    ): ?array {
        if ($amountCents <= 0) {
            return null;
        }

        [$row, $created] = Payout::claim($idempotencyKey, [
            'order_id' => $orderId,
            'type' => $type,
            'recipient_account' => $account,
            'amount_cents' => $amountCents,
        ]);

        if (!$created) {
            return $row;
        }

        Queue::push(TransferPayoutJob::class, ['payout_id' => (int) $row['id']]);

        Activity::log('payout.queued', 'Order', $orderId, [
            'payout_id' => (int) $row['id'],
            'type' => $type,
            'amount_cents' => $amountCents,
        ]);

        return $row;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function restaurantAccount(array $order): string
    {
        $restaurant = Restaurant::find((int) $order['restaurant_id']);

        return $restaurant === null ? '' : trim((string) ($restaurant['stripe_account_id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $order
     */
    private function driverAccount(array $order): string
    {
        $driverId = $order['driver_id'] ?? null;
        $driver = $driverId === null ? null : Driver::find((int) $driverId);

        return $driver === null ? '' : trim((string) ($driver['stripe_account_id'] ?? ''));
    }

    /**
     * @return array{account: string, enabled: bool, reason: string|null}
     */
    private function recipient(string $account, bool $enabled, string $who): array
    {
        $account = trim($account);

        if ($account === '') {
            return [
                'account' => '',
                'enabled' => false,
                'reason' => 'The ' . $who . ' has no connected Stripe account.',
            ];
        }

        if (!$enabled) {
            return [
                'account' => $account,
                'enabled' => false,
                'reason' => 'Stripe has payouts disabled for this ' . $who . '.',
            ];
        }

        return ['account' => $account, 'enabled' => true, 'reason' => null];
    }

    /**
     * The tip a receipt should show as already paid on, for screens that read
     * the order rather than the adjustments.
     */
    public function settledTipAdjustmentCents(int $orderId): int
    {
        return TipAdjustment::settledDeltaCents($orderId);
    }
}
