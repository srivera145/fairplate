<?php

namespace Keel\App\Jobs;

use Keel\App\Models\Order;
use Keel\App\Models\Payout;
use Keel\App\Models\TipAdjustment;
use Keel\App\Services\Payments\AdminAlert;
use Keel\App\Services\Payments\PayoutService;
use Keel\App\Services\StripeClientFactory;
use Keel\Core\Activity;
use Keel\Core\Queue;

/**
 * Sends one transfer, or explains why it did not.
 *
 * One job per payout row rather than one per order, because the restaurant and
 * the driver fail independently: a kitchen whose Stripe account is fine should
 * be paid this minute even if the driver who carried the food has not finished
 * their onboarding.
 *
 * Three things make it safe to run twice, which it will be:
 *
 *   The row is claimed before the job is queued, so the job's only input is an
 *   id. A replayed webhook re-claims the same row and queues nothing.
 *
 *   A row already marked paid returns immediately. That covers the case where
 *   the transfer succeeded and the process died before the row was updated —
 *   the next attempt asks Stripe with the same idempotency key and Stripe hands
 *   back the transfer it already made rather than making a second.
 *
 *   The idempotency key lives on the row. It is the same string every attempt,
 *   for the life of the row, and it is the same string Stripe was given the
 *   first time.
 *
 * Retries are the job's own rather than the worker's. The worker would retry
 * anything that throws, which is right for most jobs and wrong for this one:
 * the difference between "Stripe timed out" and "this account cannot be paid"
 * is the difference between trying again in a minute and stopping until a
 * person does something, and only this code knows which it is looking at.
 */
class TransferPayoutJob implements Job
{
    /** Attempts before a payout stops trying and becomes somebody's morning. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Seconds to wait before attempt n+1. Doubling, from half a minute to eight.
     */
    public const BACKOFF_SECONDS = [30, 60, 120, 240];

    public function handle(array $data): void
    {
        $payoutId = (int) ($data['payout_id'] ?? 0);

        if ($payoutId <= 0) {
            throw new \RuntimeException('TransferPayoutJob needs a payout_id.');
        }

        $payout = Payout::find($payoutId);

        if ($payout === null) {
            return;
        }

        $status = (string) $payout['status'];

        // Paid is done. Held is waiting on a person or on account.updated, and
        // re-running it here would only re-alert about something already
        // reported.
        if ($status === Payout::STATUS_PAID || $status === Payout::STATUS_HELD) {
            return;
        }

        $order = Order::find((int) $payout['order_id']);

        if ($order === null) {
            $this->hold($payout, 'The order this payout belongs to has gone.');

            return;
        }

        $recipient = (new PayoutService())->recipientFor($payout);

        if (!$recipient['enabled']) {
            $this->hold($payout, (string) $recipient['reason']);

            return;
        }

        // The attempt is counted before anything can fail, not inside the work.
        // Counting it in the transfer would mean a failure that happened before
        // the counter — the database being unreachable, say — re-queued forever
        // against an attempts column that never moved.
        $attempts = (int) $payout['attempts'] + 1;
        Payout::update((int) $payout['id'], [
            'attempts' => $attempts,
            'recipient_account' => (string) $recipient['account'],
        ]);

        try {
            $this->transfer($payout, $order, (string) $recipient['account'], $attempts);
        } catch (\Throwable $exception) {
            $this->retryOrFail($payout, $attempts, $exception);
        }
    }

    /**
     * The transfer itself.
     *
     * transfer_group is the order id, so the capture and both transfers appear
     * as one thing in the Stripe dashboard rather than as three unrelated
     * movements. source_transaction is the charge, which is what makes the money
     * come out of this order's own funds — without it a transfer draws on the
     * platform balance and a busy Friday can outrun what has settled.
     *
     * @param array<string, mixed> $payout
     * @param array<string, mixed> $order
     */
    private function transfer(array $payout, array $order, string $account, int $attempts): void
    {
        $payoutId = (int) $payout['id'];
        $orderId = (int) $order['id'];
        $amountCents = (int) $payout['amount_cents'];

        $parameters = [
            'amount' => $amountCents,
            'currency' => 'usd',
            'destination' => $account,
            'transfer_group' => self::transferGroup($orderId),
            'metadata' => [
                'order_id' => (string) $orderId,
                'payout_id' => (string) $payoutId,
                'type' => (string) $payout['type'],
            ],
        ];

        $sourceTransaction = $this->sourceTransaction($payout, $order);

        if ($sourceTransaction !== null) {
            $parameters['source_transaction'] = $sourceTransaction;
        }

        $transfer = StripeClientFactory::make()->transfers->create(
            $parameters,
            ['idempotency_key' => (string) $payout['idempotency_key']]
        );

        Payout::update($payoutId, [
            'status' => Payout::STATUS_PAID,
            'stripe_transfer_id' => (string) $transfer->id,
            'last_error' => null,
        ]);

        Activity::log('payout.paid', 'Order', $orderId, [
            'payout_id' => $payoutId,
            'type' => (string) $payout['type'],
            'amount_cents' => $amountCents,
            'transfer_id' => (string) $transfer->id,
            'attempts' => $attempts,
        ]);
    }

    /**
     * The charge this transfer comes out of.
     *
     * A tip raise is its own charge, so its payout draws on that one rather than
     * on the order's original capture, which has already been spent on the
     * restaurant and the driver.
     *
     * @param array<string, mixed> $payout
     * @param array<string, mixed> $order
     */
    private function sourceTransaction(array $payout, array $order): ?string
    {
        if ((string) $payout['type'] !== Payout::TYPE_DRIVER_TIP_ADJUST) {
            $chargeId = trim((string) ($order['stripe_charge_id'] ?? ''));

            return $chargeId === '' ? null : $chargeId;
        }

        $adjustment = $this->tipAdjustmentFor($payout);
        $chargeId = $adjustment === null ? '' : trim((string) ($adjustment['stripe_charge_id'] ?? ''));

        return $chargeId === '' ? null : $chargeId;
    }

    /**
     * @param array<string, mixed> $payout
     * @return array<string, mixed>|null
     */
    private function tipAdjustmentFor(array $payout): ?array
    {
        // The payout's key ends in the adjustment id it was made for.
        $parts = explode('_tip_', (string) $payout['idempotency_key']);

        if (count($parts) !== 2) {
            return null;
        }

        return TipAdjustment::find((int) $parts[1]);
    }

    /**
     * Puts the payout aside for a person, without ever losing it.
     *
     * Held is not failed. The money is still owed and the row still says so; it
     * is only that sending it now would be refused. account.updated puts it back
     * the moment Stripe says the recipient can be paid.
     *
     * @param array<string, mixed> $payout
     */
    private function hold(array $payout, string $reason): void
    {
        Payout::update((int) $payout['id'], [
            'status' => Payout::STATUS_HELD,
            'last_error' => $reason,
        ]);

        AdminAlert::raise('payout.held', sprintf(
            'Payout %d for order %d is held: %s',
            (int) $payout['id'],
            (int) $payout['order_id'],
            $reason
        ), [
            'order_id' => (int) $payout['order_id'],
            'payout_id' => (int) $payout['id'],
            'type' => (string) $payout['type'],
            'amount_cents' => (int) $payout['amount_cents'],
            'recipient_account' => (string) $payout['recipient_account'],
        ]);
    }

    /**
     * Another go, or a person.
     *
     * @param array<string, mixed> $payout
     */
    private function retryOrFail(array $payout, int $attempts, \Throwable $exception): void
    {
        $payoutId = (int) $payout['id'];
        $message = $exception::class . ': ' . $exception->getMessage();

        if ($attempts < self::MAX_ATTEMPTS) {
            $delay = self::BACKOFF_SECONDS[min($attempts, count(self::BACKOFF_SECONDS)) - 1];

            Payout::update($payoutId, ['last_error' => $message]);
            Queue::push(self::class, ['payout_id' => $payoutId], 'default', $delay);

            Activity::log('payout.retry', 'Order', (int) $payout['order_id'], [
                'payout_id' => $payoutId,
                'attempts' => $attempts,
                'retry_in_seconds' => $delay,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        Payout::update($payoutId, [
            'status' => Payout::STATUS_FAILED,
            'last_error' => $message,
        ]);

        AdminAlert::raise('payout.failed', sprintf(
            'Payout %d for order %d gave up after %d attempts: %s',
            $payoutId,
            (int) $payout['order_id'],
            $attempts,
            $exception->getMessage()
        ), [
            'order_id' => (int) $payout['order_id'],
            'payout_id' => $payoutId,
            'type' => (string) $payout['type'],
            'amount_cents' => (int) $payout['amount_cents'],
            'attempts' => $attempts,
        ]);
    }

    /**
     * The string that ties a capture and its transfers together in Stripe.
     */
    public static function transferGroup(int $orderId): string
    {
        return 'order_' . $orderId;
    }
}
