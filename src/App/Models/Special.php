<?php

namespace Keel\App\Models;

class Special extends Model
{
    protected const TABLE = 'specials';

    protected const COLUMNS = [
        'restaurant_id', 'title', 'description', 'type', 'value_pct', 'value_cents',
        'menu_item_id', 'days', 'start_time', 'end_time', 'active',
    ];

    public const TYPE_PERCENT = 'percent';
    public const TYPE_AMOUNT = 'amount';
    public const TYPE_PRICE = 'price';

    public static function forRestaurant(int $restaurantId): array
    {
        return self::allBy('restaurant_id', $restaurantId, 'id ASC');
    }

    public static function activeForRestaurant(int $restaurantId): array
    {
        return self::query(
            'SELECT * FROM specials WHERE restaurant_id = ? AND active = 1 ORDER BY id ASC',
            [$restaurantId]
        );
    }

    /**
     * True when the special runs on this ISO weekday (1 Monday - 7 Sunday) at
     * this local time. A NULL days list means every day; NULL times mean all
     * day. Display only: no pricing math happens here.
     */
    public static function runsAt(array $special, int $isoWeekday, string $localTime): bool
    {
        if ((int) ($special['active'] ?? 0) !== 1) {
            return false;
        }

        $days = $special['days'] ?? null;

        if (is_string($days)) {
            $days = json_decode($days, true);
        }

        if (is_array($days) && $days !== [] && !in_array($isoWeekday, array_map('intval', $days), true)) {
            return false;
        }

        $start = $special['start_time'] ?? null;
        $end = $special['end_time'] ?? null;

        if ($start !== null && $localTime < (string) $start) {
            return false;
        }

        if ($end !== null && $localTime > (string) $end) {
            return false;
        }

        return true;
    }
}
