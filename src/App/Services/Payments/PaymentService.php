<?php

namespace Keel\App\Services\Payments;

use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Models\Payout;
use Keel\App\Models\Refund;
use Keel\App\Models\TipAdjustment;
use Keel\App\Models\User;
use Keel\App\Services\Pricing\PricingException;
use Keel\App\Services\Pricing\PricingService;
use Keel\App\Services\Settings;
use Keel\App\Services\StripeClientFactory;
use Keel\Core\Activity;
use Stripe\StripeClient;

/**
 * Every call this application makes that moves a customer's money.
 *
 * Four operations, and the order they may happen in is the whole design. The
 * card is held at checkout and nothing is taken. Delivery captures the real
 * total, which can never exceed the hold. A cancellation before pickup releases
 * the hold and nobody is charged anything. Everything after that is a refund
 * against a charge that exists.
 *
 * Two rules run through all four:
 *
 * Every write carries an idempotency key, and the key is derived from the order
 * rather than generated. A retried job, a replayed webhook and a person
 * clicking twice all compute the same string, so Stripe returns the original
 * result rather than doing it again. The keys are not secrets and they are not
 * random: `fp_capture_812` is the capture of order 812, once, forever.
 *
 * Nothing here decides an amount. The capture takes the final breakdown's
 * total, the tip charge takes PricingService's gross-up, the transfers take
 * PayoutService's shares. The spec allows money arithmetic in two places and
 * this is neither of them.
 *
 * The Stripe fee is read back rather than estimated. The service fee is a
 * gross-up computed from settings, and the only way to know it actually covered
 * the card is to look at what Stripe took — so the balance transaction behind
 * every capture is fetched and stored, and a service fee that ever came out
 * below the real fee is then a query rather than a guess.
 */
class PaymentService
{
    /** Stripe's own limit on an idempotency key, which none of ours approach. */
    private const MAX_KEY_LENGTH = 255;

    public function __construct(
        private readonly ?StripeClient $stripe = null,
        private readonly ?PayoutService $payouts = null,
        private readonly ?PricingService $pricing = null,
    ) {
    }

    // -----------------------------------------------------------------
    // Capture
    // -----------------------------------------------------------------

    /**
     * Takes the final total from the hold.
     *
     * The amount is the final breakdown's total and nothing else. It is checked
     * against the authorization first, here as well as in PricingService,
     * because this is the last place before the money moves and a capture above
     * its hold is the one failure Stripe would accept happily by capturing less
     * than we thought we asked for.
     *
     * Already captured is not an error. The delivered transition can be
     * replayed, the job behind it can be retried, and both must come out at one
     * capture — so an order that already has captured_cents is reported as it
     * stands.
     *
     * @param array<string, mixed> $order
     * @return array{captured_cents: int, charge_id: string|null, stripe_fee_cents: int|null, already: bool}
     */
    public function capture(array $order): array
    {
        $orderId = (int) $order['id'];

        if (Order::isCaptured($order)) {
            return [
                'captured_cents' => (int) $order['captured_cents'],
                'charge_id' => $order['stripe_charge_id'] ?? null,
                'stripe_fee_cents' => $order['stripe_fee_cents'] === null ? null : (int) $order['stripe_fee_cents'],
                'already' => true,
            ];
        }

        $paymentIntentId = trim((string) ($order['stripe_payment_intent_id'] ?? ''));

        if ($paymentIntentId === '') {
            throw new PaymentException("Order {$orderId} has no payment intent to capture.");
        }

        $final = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);

        if ($final === null) {
            throw new PaymentException("Order {$orderId} has no final breakdown to capture.");
        }

        $total = (int) $final['total_cents'];
        $authorized = (int) ($order['authorized_cents'] ?? 0);

        if ($total > $authorized) {
            throw PaymentException::aboveAuthorization($orderId, $total, $authorized);
        }

        $intent = $this->stripe()->paymentIntents->capture(
            $paymentIntentId,
            ['amount_to_capture' => $total],
            ['idempotency_key' => $this->key('capture', $orderId)]
        );

        $chargeId = $this->chargeIdFrom($intent);
        $feeCents = $chargeId === null ? null : $this->balanceTransactionFeeCents($chargeId);

        Order::update($orderId, array_filter([
            'captured_cents' => $total,
            'stripe_charge_id' => $chargeId,
            'stripe_fee_cents' => $feeCents,
        ], static fn (mixed $value): bool => $value !== null));

        Activity::log('order.captured', 'Order', $orderId, [
            'captured_cents' => $total,
            'authorized_cents' => $authorized,
            'stripe_fee_cents' => $feeCents,
            'charge_id' => $chargeId,
        ]);

        $this->warnIfFeeExceedsServiceFee($orderId, $feeCents, (int) $final['service_fee_cents']);

        return [
            'captured_cents' => $total,
            'charge_id' => $chargeId,
            'stripe_fee_cents' => $feeCents,
            'already' => false,
        ];
    }

    // -----------------------------------------------------------------
    // Release
    // -----------------------------------------------------------------

    /**
     * Cancels the hold. Nobody is charged and no transfer exists.
     *
     * The correct ending for a rejection or a cancellation before pickup. An
     * order that has already been captured cannot be released — that is a
     * refund, and the caller is told so rather than being quietly given the
     * wrong one.
     *
     * @param array<string, mixed> $order
     */
    public function release(array $order): bool
    {
        $orderId = (int) $order['id'];

        if (Order::isCaptured($order)) {
            throw new PaymentException("Order {$orderId} is captured; releasing it would be a refund.");
        }

        $paymentIntentId = trim((string) ($order['stripe_payment_intent_id'] ?? ''));

        if ($paymentIntentId === '') {
            return false;
        }

        $this->stripe()->paymentIntents->cancel(
            $paymentIntentId,
            [],
            ['idempotency_key' => $this->key('release', $orderId)]
        );

        Activity::log('order.authorization_released', 'Order', $orderId, [
            'payment_intent' => $paymentIntentId,
            'authorized_cents' => (int) ($order['authorized_cents'] ?? 0),
        ]);

        return true;
    }

    // -----------------------------------------------------------------
    // Refunds
    // -----------------------------------------------------------------

    /**
     * Gives money back, and decides who pays for it.
     *
     * The platform absorbs a refund by default. That is the spec's rule and it
     * is the point of the whole arrangement: a kitchen that cooked the food and
     * a driver who drove it are not made to fund somebody else's decision. Only
     * an admin marking the order a restaurant error reverses anything, and then
     * only the restaurant's share — the driver's transfer stands either way.
     *
     * Stripe keeps its fee on a refund whatever the amount, so the first refund
     * on an order writes the whole original fee down as absorbed and later ones
     * write nothing: the money was lost once, not once per refund.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed> the refunds row
     */
    public function refund(
        array $order,
        int $amountCents,
        string $reason = '',
        bool $restaurantError = false
    ): array {
        $orderId = (int) $order['id'];

        if (!Order::isCaptured($order)) {
            throw PaymentException::notCaptured($orderId);
        }

        if ($amountCents <= 0) {
            throw new PaymentException('A refund needs a positive amount.');
        }

        $captured = (int) $order['captured_cents'];
        $alreadyRefunded = Refund::totalForOrderCents($orderId);

        if ($alreadyRefunded + $amountCents > $captured) {
            throw new PaymentException(sprintf(
                'Order %d was charged %d and %d is already refunded; %d more would be too much.',
                $orderId,
                $captured,
                $alreadyRefunded,
                $amountCents
            ));
        }

        $chargeId = trim((string) ($order['stripe_charge_id'] ?? ''));

        if ($chargeId === '') {
            throw new PaymentException("Order {$orderId} has no charge to refund against.");
        }

        $key = $this->key('refund', $orderId, (string) (count(Refund::forOrder($orderId)) + 1));
        $existing = Refund::findByIdempotencyKey($key);

        if ($existing !== null) {
            return $existing;
        }

        $stripeRefund = $this->stripe()->refunds->create(
            [
                'charge' => $chargeId,
                'amount' => $amountCents,
                'metadata' => [
                    'order_id' => (string) $orderId,
                    'restaurant_error' => $restaurantError ? '1' : '0',
                ],
            ],
            ['idempotency_key' => $key]
        );

        $refundId = Refund::create([
            'order_id' => $orderId,
            'idempotency_key' => $key,
            'amount_cents' => $amountCents,
            'reason' => $reason === '' ? null : $reason,
            'restaurant_error' => $restaurantError,
            'processing_absorbed_cents' => $this->processingAbsorbedCents($order),
            'stripe_refund_id' => (string) $stripeRefund->id,
        ]);

        Activity::log('order.refunded', 'Order', $orderId, [
            'amount_cents' => $amountCents,
            'restaurant_error' => $restaurantError,
            'stripe_refund_id' => (string) $stripeRefund->id,
        ]);

        if ($restaurantError) {
            $this->reverseRestaurantShare($order, $refundId, $amountCents);
        }

        return Refund::find($refundId) ?? [];
    }

    // -----------------------------------------------------------------
    // Tip adjustment
    // -----------------------------------------------------------------

    /**
     * A separate, off-session charge for a tip the customer raised afterwards.
     *
     * The original hold was created with setup_future_usage, so the card is
     * still on the customer and can be charged without them being present. The
     * amount is the delta grossed up on its own, so the driver keeps the whole
     * increase and the processing on it is still covered — which is why this is
     * a second charge rather than an attempt to reopen the first.
     *
     * The adjustment row is written before the charge and settled after. A
     * charge that fails leaves a failed row, which is what a receipt should say
     * and what stops the same delta being retried as though nothing happened.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed> the tip_adjustments row
     */
    public function chargeTipAdjustment(array $order, int $deltaCents): array
    {
        $orderId = (int) $order['id'];

        if (!Order::isCaptured($order)) {
            throw PaymentException::notCaptured($orderId);
        }

        $window = $this->tipWindow($order);

        if (!$window['open']) {
            throw new PaymentException('That order is past the window for raising a tip.');
        }

        try {
            $breakdown = $this->pricing()->tipAdjustment($order, $deltaCents);
        } catch (PricingException $exception) {
            throw new PaymentException('That tip could not be priced: ' . $exception->getMessage());
        }

        $chargeCents = $breakdown->total();
        $key = $this->key('tip', $orderId, (string) (TipAdjustment::countForOrder($orderId) + 1));
        $existing = TipAdjustment::findByIdempotencyKey($key);

        if ($existing !== null) {
            return $existing;
        }

        $adjustmentId = TipAdjustment::create([
            'order_id' => $orderId,
            'idempotency_key' => $key,
            'delta_cents' => $deltaCents,
            'service_fee_cents' => $chargeCents - $deltaCents,
            'charge_cents' => $chargeCents,
            'status' => TipAdjustment::STATUS_PENDING,
        ]);

        try {
            $intent = $this->offSessionCharge($order, $chargeCents, $deltaCents, $key);
        } catch (\Throwable $exception) {
            TipAdjustment::update($adjustmentId, ['status' => TipAdjustment::STATUS_FAILED]);

            Activity::log('order.tip_adjust_failed', 'Order', $orderId, [
                'delta_cents' => $deltaCents,
                'error' => $exception->getMessage(),
            ]);

            throw new PaymentException('That card could not be charged: ' . $exception->getMessage());
        }

        TipAdjustment::update($adjustmentId, [
            'status' => TipAdjustment::STATUS_SUCCEEDED,
            'stripe_payment_intent_id' => (string) $intent->id,
            'stripe_charge_id' => $this->chargeIdFrom($intent),
        ]);

        $adjustment = TipAdjustment::find($adjustmentId) ?? [];

        Activity::log('order.tip_adjusted', 'Order', $orderId, [
            'delta_cents' => $deltaCents,
            'charge_cents' => $chargeCents,
            'payment_intent' => (string) $intent->id,
        ]);

        // The whole delta goes to the driver, on its own transfer, out of the
        // charge that just funded it.
        $this->payouts()->queueTipAdjustment($order, $adjustment);

        return $adjustment;
    }

    /**
     * How long is left to raise the tip on this order.
     *
     * One answer, read by the receipt that offers the form and by the charge
     * that honours it, so a button cannot appear a minute after the rule has
     * stopped allowing what it does.
     *
     * The window runs from delivery, because that is when somebody knows
     * whether they want to. An order that was never delivered has no window at
     * all rather than one that has not opened yet: there is nothing to tip for.
     *
     * The hours come from settings. An admin lengthening the window reaches
     * orders delivered yesterday, which is correct — unlike pricing, this is not
     * a term anybody was quoted, it is how long we are willing to keep a card on
     * file for a good reason.
     *
     * @param array<string, mixed> $order
     * @return array{open: bool, hours: int, closes_at: string|null, seconds_left: int}
     */
    public function tipWindow(array $order): array
    {
        $hours = Settings::int('tip_adjust_window_hours');
        $deliveredAt = $order['delivered_at'] ?? null;
        $closed = ['open' => false, 'hours' => $hours, 'closes_at' => null, 'seconds_left' => 0];

        if ((string) ($order['status'] ?? '') !== Order::STATUS_DELIVERED || $deliveredAt === null) {
            return $closed;
        }

        $delivered = strtotime((string) $deliveredAt . ' UTC');

        if ($delivered === false) {
            return $closed;
        }

        $closesAt = $delivered + ($hours * 3600);
        $secondsLeft = max(0, $closesAt - time());

        return [
            'open' => $secondsLeft > 0,
            'hours' => $hours,
            'closes_at' => gmdate('Y-m-d H:i:s', $closesAt),
            'seconds_left' => $secondsLeft,
        ];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Charges the saved card with nobody watching.
     */
    private function offSessionCharge(
        array $order,
        int $chargeCents,
        int $deltaCents,
        string $key
    ): \Stripe\PaymentIntent {
        $orderId = (int) $order['id'];
        $original = $this->stripe()->paymentIntents->retrieve(
            (string) $order['stripe_payment_intent_id'],
            []
        );

        $customerId = trim((string) ($original->customer ?? ''));
        $paymentMethodId = trim((string) ($original->payment_method ?? ''));

        if ($customerId === '') {
            $customerId = $this->customerIdFor($order);
        }

        if ($customerId === '' || $paymentMethodId === '') {
            throw new PaymentException("Order {$orderId} has no saved card to charge.");
        }

        return $this->stripe()->paymentIntents->create(
            [
                'amount' => $chargeCents,
                'currency' => 'usd',
                'customer' => $customerId,
                'payment_method' => $paymentMethodId,
                // Nobody is at the keyboard. A card that wants a challenge
                // declines here rather than hanging on a screen that is not open.
                'off_session' => true,
                'confirm' => true,
                'metadata' => [
                    'order_id' => (string) $orderId,
                    'kind' => 'tip_adjustment',
                    'tip_delta_cents' => (string) $deltaCents,
                ],
            ],
            ['idempotency_key' => $key]
        );
    }

    private function customerIdFor(array $order): string
    {
        $user = User::find((int) $order['customer_id']);

        return $user === null ? '' : trim((string) ($user['stripe_customer_id'] ?? ''));
    }

    /**
     * Pulls the restaurant's share of a refund back out of their transfer.
     */
    private function reverseRestaurantShare(array $order, int $refundId, int $amountCents): void
    {
        $orderId = (int) $order['id'];
        $reversalCents = $this->payouts()->restaurantReversalCents($orderId, $amountCents);

        if ($reversalCents <= 0) {
            return;
        }

        $payout = Payout::forOrderAndType($orderId, Payout::TYPE_RESTAURANT);
        $transferId = $payout === null ? '' : trim((string) ($payout['stripe_transfer_id'] ?? ''));

        if ($transferId === '') {
            AdminAlert::raise('refund.no_transfer_to_reverse', sprintf(
                'Order %d was marked a restaurant error but has no transfer to reverse.',
                $orderId
            ), ['order_id' => $orderId, 'amount_cents' => $reversalCents]);

            return;
        }

        $reversal = $this->stripe()->transfers->createReversal(
            $transferId,
            ['amount' => $reversalCents, 'metadata' => ['order_id' => (string) $orderId]],
            ['idempotency_key' => $this->key('reversal', $orderId, (string) $refundId)]
        );

        Refund::update($refundId, [
            'reversed_restaurant_cents' => $reversalCents,
            'stripe_transfer_reversal_id' => (string) $reversal->id,
        ]);

        Payout::update((int) $payout['id'], [
            'reversed_cents' => (int) ($payout['reversed_cents'] ?? 0) + $reversalCents,
        ]);

        Activity::log('order.restaurant_transfer_reversed', 'Order', $orderId, [
            'amount_cents' => $reversalCents,
            'transfer_id' => $transferId,
        ]);
    }

    /**
     * What Stripe kept on this charge that a refund will not give back.
     *
     * The whole fee on the first refund, nothing on the ones after it.
     */
    private function processingAbsorbedCents(array $order): int
    {
        $fee = (int) ($order['stripe_fee_cents'] ?? 0);
        $already = Refund::absorbedForOrderCents((int) $order['id']);

        return max(0, $fee - $already);
    }

    /**
     * The charge a PaymentIntent produced.
     *
     * latest_charge first, because that is what a modern intent carries; the
     * charges list is the fallback for an intent read back from an older API
     * version, which a webhook can still hand us.
     */
    private function chargeIdFrom(\Stripe\PaymentIntent $intent): ?string
    {
        $latest = $intent->latest_charge ?? null;

        if (is_string($latest) && $latest !== '') {
            return $latest;
        }

        if (is_object($latest) && isset($latest->id)) {
            return (string) $latest->id;
        }

        $charges = $intent->charges ?? null;
        $first = $charges?->data[0] ?? null;

        return $first === null ? null : (string) $first->id;
    }

    /**
     * What Stripe actually took, from the balance transaction behind the charge.
     *
     * Null rather than zero when it cannot be read: a fee of zero is a claim,
     * and the reconciliation that reads this column would believe it.
     */
    private function balanceTransactionFeeCents(string $chargeId): ?int
    {
        try {
            $charge = $this->stripe()->charges->retrieve($chargeId, ['expand' => ['balance_transaction']]);
            $transaction = $charge->balance_transaction ?? null;

            if (is_object($transaction) && isset($transaction->fee)) {
                return (int) $transaction->fee;
            }

            if (is_string($transaction) && $transaction !== '') {
                return (int) $this->stripe()->balanceTransactions->retrieve($transaction, [])->fee;
            }
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Could not read the Stripe fee for ' . $chargeId . ': ' . $exception->getMessage());
        }

        return null;
    }

    /**
     * The spec's promise, checked on every order rather than sampled.
     *
     * "No order ever costs FairPlate money" is arithmetic: the service fee is a
     * gross-up that ceilings, so it should always cover the card. If it ever
     * does not, the processing settings have drifted from what the processor
     * actually charges, and that is a pricing problem on every order from now
     * on rather than an oddity on this one.
     */
    private function warnIfFeeExceedsServiceFee(int $orderId, ?int $feeCents, int $serviceFeeCents): void
    {
        if ($feeCents === null || $feeCents <= $serviceFeeCents) {
            return;
        }

        AdminAlert::raise('capture.fee_above_service_fee', sprintf(
            'Order %d paid Stripe %d against a service fee of %d.',
            $orderId,
            $feeCents,
            $serviceFeeCents
        ), [
            'order_id' => $orderId,
            'stripe_fee_cents' => $feeCents,
            'service_fee_cents' => $serviceFeeCents,
        ]);
    }

    /**
     * An idempotency key: what was done, to which order, and which time.
     */
    private function key(string $operation, int $orderId, string $suffix = ''): string
    {
        $key = 'fp_' . $operation . '_' . $orderId . ($suffix === '' ? '' : '_' . $suffix);

        return substr($key, 0, self::MAX_KEY_LENGTH);
    }

    private function stripe(): StripeClient
    {
        return $this->stripe ?? StripeClientFactory::make();
    }

    private function payouts(): PayoutService
    {
        return $this->payouts ?? new PayoutService();
    }

    private function pricing(): PricingService
    {
        return $this->pricing ?? new PricingService();
    }
}
