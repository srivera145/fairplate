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
     * Active specials for a whole page of restaurants, keyed by restaurant id.
     *
     * The browse screen shows a badge on every card that has one running, and
     * asking per card would make a twenty-restaurant page twenty queries for a
     * dot.
     *
     * @param list<int> $restaurantIds
     * @return array<int, list<array<string, mixed>>>
     */
    public static function activeForRestaurants(array $restaurantIds): array
    {
        $restaurantIds = array_values(array_unique(array_map('intval', $restaurantIds)));

        if ($restaurantIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($restaurantIds), '?'));

        $rows = self::query(
            'SELECT * FROM specials
             WHERE active = 1 AND restaurant_id IN (' . $placeholders . ')
             ORDER BY restaurant_id ASC, id ASC',
            $restaurantIds
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['restaurant_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * The ones from a list that are running at this local time.
     *
     * @param list<array<string, mixed>> $specials
     * @return list<array<string, mixed>>
     */
    public static function runningNow(array $specials, ?\DateTimeImmutable $at = null): array
    {
        $local = ($at ?? new \DateTimeImmutable('now'))
            ->setTimezone(new \DateTimeZone('America/New_York'));

        $weekday = (int) $local->format('N');
        $time = $local->format('H:i:s');

        return array_values(array_filter(
            $specials,
            static fn (array $special): bool => self::runsAt($special, $weekday, $time)
        ));
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
