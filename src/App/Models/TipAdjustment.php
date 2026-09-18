<?php

namespace Keel\App\Models;

class TipAdjustment extends Model
{
    protected const TABLE = 'tip_adjustments';

    protected const COLUMNS = [
        'order_id', 'delta_cents', 'service_fee_cents', 'stripe_charge_id', 'status',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public static function forOrder(int $orderId): array
    {
        return self::allBy('order_id', $orderId, 'id ASC');
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
}
