<?php

namespace Keel\App\Models;

class Restaurant extends Model
{
    protected const TABLE = 'restaurants';

    protected const COLUMNS = [
        'name', 'slug', 'phone', 'line1', 'line2', 'city', 'state', 'zip',
        'lat', 'lng', 'delivery_zone_id', 'tax_rate', 'hours', 'paused',
        'paused_until', 'status', 'stripe_account_id', 'stripe_customer_id',
        'founding_discount_pct', 'custom_fee_cents', 'logo', 'cover',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    /** How long the Pause Orders switch can hold, in minutes. */
    public const PAUSE_MINUTES = [15, 30, 60];

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
     *
     * A timed pause whose deadline has passed is already over, so the condition
     * asks the same question isPaused() does rather than trusting the flag.
     */
    public static function orderable(): array
    {
        return self::query(
            'SELECT * FROM restaurants
             WHERE status = ?
               AND (paused = 0 OR (paused_until IS NOT NULL AND paused_until <= UTC_TIMESTAMP()))
             ORDER BY name ASC',
            [self::STATUS_ACTIVE]
        );
    }

    /**
     * Is this restaurant currently refusing orders?
     *
     * A timed pause is a stored deadline, not a scheduled job. There is no
     * worker to run at the fifteen-minute mark, and a tablet that went to sleep
     * cannot be relied on to send the resume, so "paused" is a question asked
     * at read time and the answer flips on its own when the deadline passes.
     */
    public static function isPaused(array $restaurant): bool
    {
        if ((int) ($restaurant['paused'] ?? 0) !== 1) {
            return false;
        }

        $until = $restaurant['paused_until'] ?? null;

        if ($until === null || $until === '') {
            return true;
        }

        return strtotime((string) $until . ' UTC') > time();
    }

    /**
     * Minutes left on a timed pause, or null when the pause has no deadline or
     * the restaurant is not paused at all.
     */
    public static function pauseMinutesLeft(array $restaurant): ?int
    {
        if (!self::isPaused($restaurant)) {
            return null;
        }

        $until = $restaurant['paused_until'] ?? null;

        if ($until === null || $until === '') {
            return null;
        }

        return (int) ceil((strtotime((string) $until . ' UTC') - time()) / 60);
    }

    /**
     * Clears a timed pause whose deadline has passed and hands back the current
     * row. Call it wherever the kitchen reads its own restaurant, so the stored
     * flag catches up with the answer isPaused() has been giving since the
     * deadline.
     */
    public static function resumeIfExpired(array $restaurant): array
    {
        $id = (int) ($restaurant['id'] ?? 0);

        if ($id === 0 || (int) ($restaurant['paused'] ?? 0) !== 1) {
            return $restaurant;
        }

        if (self::isPaused($restaurant)) {
            return $restaurant;
        }

        self::update($id, ['paused' => 0, 'paused_until' => null]);

        return self::find($id) ?? $restaurant;
    }

    /**
     * Stops orders, either for a set number of minutes or until someone says
     * otherwise.
     */
    public static function pause(int $restaurantId, ?int $minutes): bool
    {
        if ($minutes !== null && !in_array($minutes, self::PAUSE_MINUTES, true)) {
            throw new \InvalidArgumentException("\"{$minutes}\" is not an offered pause length.");
        }

        return self::update($restaurantId, [
            'paused' => 1,
            'paused_until' => $minutes === null ? null : gmdate('Y-m-d H:i:s', time() + ($minutes * 60)),
        ]);
    }

    public static function resume(int $restaurantId): bool
    {
        return self::update($restaurantId, ['paused' => 0, 'paused_until' => null]);
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
