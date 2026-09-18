<?php

namespace Keel\App\Models;

class RestaurantStaff extends Model
{
    protected const TABLE = 'restaurant_staff';

    protected const COLUMNS = ['restaurant_id', 'user_id', 'is_owner'];

    public static function forRestaurant(int $restaurantId): array
    {
        return self::query(
            'SELECT rs.*, u.name, u.phone, u.email
             FROM restaurant_staff rs
             INNER JOIN users u ON u.id = rs.user_id
             WHERE rs.restaurant_id = ?
             ORDER BY rs.is_owner DESC, u.name ASC',
            [$restaurantId]
        );
    }

    public static function forUser(int $userId): array
    {
        return self::allBy('user_id', $userId);
    }

    /**
     * The restaurants this user may act for. An empty list means the kitchen
     * routes have nothing to show them.
     */
    public static function restaurantsForUser(int $userId): array
    {
        return self::query(
            'SELECT r.*, rs.is_owner
             FROM restaurant_staff rs
             INNER JOIN restaurants r ON r.id = rs.restaurant_id
             WHERE rs.user_id = ?
             ORDER BY r.name ASC',
            [$userId]
        );
    }

    public static function isStaffOf(int $userId, int $restaurantId): bool
    {
        return self::queryOne(
            'SELECT id FROM restaurant_staff WHERE user_id = ? AND restaurant_id = ? LIMIT 1',
            [$userId, $restaurantId]
        ) !== null;
    }
}
