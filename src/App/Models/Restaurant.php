<?php

namespace Keel\App\Models;

class Restaurant extends Model
{
    protected const TABLE = 'restaurants';

    protected const COLUMNS = [
        'name', 'slug', 'phone', 'line1', 'line2', 'city', 'state', 'zip',
        'lat', 'lng', 'delivery_zone_id', 'tax_rate', 'hours', 'paused', 'status',
        'stripe_account_id', 'stripe_customer_id', 'founding_discount_pct',
        'custom_fee_cents', 'logo', 'cover',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public static function findBySlug(string $slug): ?array
    {
        return self::firstBy('slug', $slug);
    }

    public static function all(): array
    {
        return self::query('SELECT * FROM restaurants ORDER BY name ASC');
    }

    /**
     * Live and taking orders: active status, not paused.
     */
    public static function orderable(): array
    {
        return self::query(
            'SELECT * FROM restaurants WHERE status = ? AND paused = 0 ORDER BY name ASC',
            [self::STATUS_ACTIVE]
        );
    }

    public static function withStatus(string $status): array
    {
        return self::allBy('status', $status, 'name ASC');
    }

    public static function inZone(int $zoneId): array
    {
        return self::allBy('delivery_zone_id', $zoneId, 'name ASC');
    }

    public static function zone(array $restaurant): ?array
    {
        $zoneId = $restaurant['delivery_zone_id'] ?? null;

        return $zoneId === null ? null : DeliveryZone::find((int) $zoneId);
    }

    public static function categories(int $restaurantId): array
    {
        return MenuCategory::forRestaurant($restaurantId);
    }

    public static function staff(int $restaurantId): array
    {
        return RestaurantStaff::forRestaurant($restaurantId);
    }

    public static function specials(int $restaurantId): array
    {
        return Special::forRestaurant($restaurantId);
    }
}
