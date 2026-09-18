<?php

namespace Keel\App\Policies;

use Keel\App\Models\RestaurantStaff;
use Keel\Core\Database;

/**
 * Who may touch which restaurant's data.
 *
 * The rule is one sentence — a staff member may read and write their own
 * restaurant's records and nothing else — and the reason it lives here rather
 * than in the controllers is that there are roughly forty kitchen routes and
 * thirty-nine of them being right is the same as none of them being right.
 *
 * Most kitchen routes carry a record id, not a restaurant id: /kitchen/menu/
 * items/91, /kitchen/specials/4. Guessing another restaurant's id is trivial,
 * so every one of those ids is resolved back to the restaurant that owns it
 * before anything is read or written. OWNERS is that resolution: for each table
 * the kitchen exposes, the SQL that walks from a record id to a restaurant id.
 * An option is three joins from its restaurant, and that is exactly the kind of
 * chain a controller writes wrong once and then copies.
 *
 * A table that is not in OWNERS cannot be authorized at all, which is the
 * failure mode worth having: a new kitchen table added without a rule here
 * refuses everyone rather than admitting everyone.
 */
final class RestaurantPolicy
{
    /** @var array<string, string> table => SQL returning one restaurant_id column */
    private const OWNERS = [
        'orders' => 'SELECT restaurant_id FROM orders WHERE id = ?',
        'menu_categories' => 'SELECT restaurant_id FROM menu_categories WHERE id = ?',
        'menu_items' => 'SELECT restaurant_id FROM menu_items WHERE id = ?',
        'item_option_groups' =>
            'SELECT i.restaurant_id
             FROM item_option_groups g
             INNER JOIN menu_items i ON i.id = g.menu_item_id
             WHERE g.id = ?',
        'item_options' =>
            'SELECT i.restaurant_id
             FROM item_options o
             INNER JOIN item_option_groups g ON g.id = o.item_option_group_id
             INNER JOIN menu_items i ON i.id = g.menu_item_id
             WHERE o.id = ?',
        'specials' => 'SELECT restaurant_id FROM specials WHERE id = ?',
        'restaurant_staff' => 'SELECT restaurant_id FROM restaurant_staff WHERE id = ?',
    ];

    /**
     * May this user act for this restaurant at all?
     */
    public static function allowsRestaurant(int $userId, int $restaurantId): bool
    {
        if ($userId <= 0 || $restaurantId <= 0) {
            return false;
        }

        return RestaurantStaff::isStaffOf($userId, $restaurantId);
    }

    /**
     * May this user touch this particular record?
     *
     * False for a record that does not exist, which is deliberate: telling a
     * stranger apart from a deleted row is information they have not earned.
     */
    public static function allowsRecord(int $userId, string $table, int $recordId): bool
    {
        $restaurantId = self::restaurantIdFor($table, $recordId);

        return $restaurantId !== null && self::allowsRestaurant($userId, $restaurantId);
    }

    /**
     * The restaurant that owns a record, or null when the record is gone.
     */
    public static function restaurantIdFor(string $table, int $recordId): ?int
    {
        if (!isset(self::OWNERS[$table])) {
            throw new \InvalidArgumentException("No ownership rule is declared for \"{$table}\".");
        }

        if ($recordId <= 0) {
            return null;
        }

        $statement = Database::connection()->prepare(self::OWNERS[$table] . ' LIMIT 1');
        $statement->execute([$recordId]);
        $row = $statement->fetch();

        return $row === false ? null : (int) $row['restaurant_id'];
    }

    /**
     * Owners can invite and remove staff; staff cannot.
     */
    public static function allowsStaffAdmin(int $userId, int $restaurantId): bool
    {
        if (!self::allowsRestaurant($userId, $restaurantId)) {
            return false;
        }

        $statement = Database::connection()->prepare(
            'SELECT is_owner FROM restaurant_staff WHERE user_id = ? AND restaurant_id = ? LIMIT 1'
        );
        $statement->execute([$userId, $restaurantId]);
        $row = $statement->fetch();

        return $row !== false && (int) $row['is_owner'] === 1;
    }

    /** The tables this policy knows how to authorize. */
    public static function scopedTables(): array
    {
        return array_keys(self::OWNERS);
    }
}
