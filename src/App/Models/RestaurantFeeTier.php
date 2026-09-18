<?php

namespace Keel\App\Models;

class RestaurantFeeTier extends Model
{
    protected const TABLE = 'restaurant_fee_tiers';

    protected const COLUMNS = ['min_orders', 'max_orders', 'fee_cents', 'is_custom', 'sort'];

    public static function all(): array
    {
        return self::query('SELECT * FROM restaurant_fee_tiers ORDER BY sort ASC, min_orders ASC');
    }

    /**
     * The tier a completed-order count lands in. A NULL max_orders is
     * open-ended, which is the custom tier at the top.
     */
    public static function forOrderCount(int $orders): ?array
    {
        return self::queryOne(
            'SELECT * FROM restaurant_fee_tiers
             WHERE min_orders <= ? AND (max_orders IS NULL OR max_orders >= ?)
             ORDER BY sort ASC, min_orders ASC
             LIMIT 1',
            [$orders, $orders]
        );
    }
}
