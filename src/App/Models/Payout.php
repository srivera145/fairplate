<?php

namespace Keel\App\Models;

class Payout extends Model
{
    protected const TABLE = 'payouts';

    protected const COLUMNS = [
        'order_id', 'type', 'recipient_account', 'amount_cents',
        'stripe_transfer_id', 'status', 'attempts',
    ];

    public const TYPE_RESTAURANT = 'restaurant';
    public const TYPE_DRIVER = 'driver';
    public const TYPE_DRIVER_TIP_ADJUST = 'driver_tip_adjust';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    public static function forOrder(int $orderId): array
    {
        return self::allBy('order_id', $orderId, 'id ASC');
    }

    public static function forOrderAndType(int $orderId, string $type): ?array
    {
        return self::queryOne(
            'SELECT * FROM payouts WHERE order_id = ? AND type = ? ORDER BY id DESC LIMIT 1',
            [$orderId, $type]
        );
    }

    public static function pending(): array
    {
        return self::allBy('status', self::STATUS_PENDING, 'id ASC');
    }
}
