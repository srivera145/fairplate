<?php

namespace Keel\App\Models;

class Refund extends Model
{
    protected const TABLE = 'refunds';

    protected const COLUMNS = [
        'order_id', 'amount_cents', 'reason', 'restaurant_error',
        'processing_absorbed_cents', 'stripe_refund_id',
    ];

    public static function forOrder(int $orderId): array
    {
        return self::allBy('order_id', $orderId, 'id ASC');
    }

    public static function totalForOrderCents(int $orderId): int
    {
        $row = self::queryOne(
            'SELECT COALESCE(SUM(amount_cents), 0) AS total FROM refunds WHERE order_id = ?',
            [$orderId]
        );

        return (int) ($row['total'] ?? 0);
    }
}
