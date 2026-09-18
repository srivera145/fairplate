<?php

namespace Keel\App\Models;

class OrderPriceBreakdown extends Model
{
    protected const TABLE = 'order_price_breakdown';

    protected const COLUMNS = [
        'order_id', 'stage', 'subtotal_cents', 'tax_cents', 'driver_base_cents',
        'driver_mileage_cents', 'driver_guaranteed_cents', 'wait_pay_cents',
        'tip_cents', 'platform_fee_cents', 'service_fee_cents', 'total_cents',
        'settings_snapshot',
    ];

    public const STAGE_ESTIMATE = 'estimate';
    public const STAGE_AUTHORIZED = 'authorized';
    public const STAGE_FINAL = 'final';

    public static function forOrder(int $orderId): array
    {
        return self::allBy('order_id', $orderId, 'id ASC');
    }

    public static function forStage(int $orderId, string $stage): ?array
    {
        return self::queryOne(
            'SELECT * FROM order_price_breakdown WHERE order_id = ? AND stage = ? LIMIT 1',
            [$orderId, $stage]
        );
    }

    /**
     * The pricing settings frozen onto this row. Later admin edits never reach
     * back into an order that already has a breakdown.
     */
    public static function settingsSnapshot(array $breakdown): array
    {
        $snapshot = $breakdown['settings_snapshot'] ?? null;

        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        return is_array($snapshot) ? $snapshot : [];
    }
}
