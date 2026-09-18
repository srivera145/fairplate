<?php

namespace Keel\App\Models;

class TipAdjustment extends Model
{
    protected const TABLE = 'tip_adjustments';

    protected const COLUMNS = [
        'order_id', 'idempotency_key', 'delta_cents', 'service_fee_cents', 'charge_cents',
        'stripe_charge_id', 'stripe_payment_intent_id', 'status',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public static function forOrder(int $orderId): array
    {
        return self::allBy('order_id', $orderId, 'id ASC');
    }

    /**
     * The adjustments a receipt shows: the ones that were actually charged.
     */
    public static function settledForOrder(int $orderId): array
    {
        return self::query(
            'SELECT * FROM tip_adjustments WHERE order_id = ? AND status = ? ORDER BY id ASC',
            [$orderId, self::STATUS_SUCCEEDED]
        );
    }

    /**
     * Tip actually collected on top of the order, in cents. Only settled
     * adjustments count; the whole delta goes to the driver.
     */
    public static function settledDeltaCents(int $orderId): int
    {
        $row = self::queryOne(
            'SELECT COALESCE(SUM(delta_cents), 0) AS total
             FROM tip_adjustments
             WHERE order_id = ? AND status = ?',
            [$orderId, self::STATUS_SUCCEEDED]
        );

        return (int) ($row['total'] ?? 0);
    }

    public static function findByIdempotencyKey(string $key): ?array
    {
        return trim($key) === '' ? null : self::firstBy('idempotency_key', $key);
    }

    /**
     * How many raises this order has had, settled or not.
     *
     * The count is what makes the next one's idempotency key distinct: a
     * customer may raise a tip twice inside the window, and those are two
     * charges rather than one retried.
     */
    public static function countForOrder(int $orderId): int
    {
        $row = self::queryOne('SELECT COUNT(*) AS total FROM tip_adjustments WHERE order_id = ?', [$orderId]);

        return (int) ($row['total'] ?? 0);
    }
}
