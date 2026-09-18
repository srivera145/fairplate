<?php

namespace Keel\App\Models;

class RestaurantMonthlyStatement extends Model
{
    protected const TABLE = 'restaurant_monthly_statements';

    protected const COLUMNS = [
        'restaurant_id', 'period', 'orders', 'tier_id', 'fee_cents', 'discount_cents',
        'commission_equiv_cents', 'savings_cents', 'stripe_invoice_id', 'status',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_AWAITING_CUSTOM_FEE = 'awaiting_custom_fee';
    public const STATUS_INVOICED = 'invoiced';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';

    public static function forRestaurant(int $restaurantId): array
    {
        return self::allBy('restaurant_id', $restaurantId, 'period DESC');
    }

    public static function forPeriod(int $restaurantId, string $period): ?array
    {
        return self::queryOne(
            'SELECT * FROM restaurant_monthly_statements WHERE restaurant_id = ? AND period = ? LIMIT 1',
            [$restaurantId, $period]
        );
    }

    public static function inPeriod(string $period): array
    {
        return self::allBy('period', $period, 'restaurant_id ASC');
    }
}
