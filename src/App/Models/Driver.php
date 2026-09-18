<?php

namespace Keel\App\Models;

class Driver extends Model
{
    protected const TABLE = 'drivers';

    protected const COLUMNS = [
        'user_id', 'vehicle_make', 'vehicle_model', 'vehicle_color', 'plate',
        'approved', 'online', 'idle', 'last_lat', 'last_lng', 'last_seen_at',
        'stripe_account_id',
    ];

    public static function forUser(int $userId): ?array
    {
        return self::firstBy('user_id', $userId);
    }

    public static function all(): array
    {
        return self::query('SELECT * FROM drivers ORDER BY id ASC');
    }

    public static function approved(): array
    {
        return self::query('SELECT * FROM drivers WHERE approved = 1 ORDER BY id ASC');
    }

    /**
     * Who dispatch may offer to: approved, online, and not already on a run.
     */
    public static function dispatchable(): array
    {
        return self::query(
            'SELECT * FROM drivers WHERE approved = 1 AND online = 1 AND idle = 1 ORDER BY last_seen_at DESC'
        );
    }

    public static function recordPing(int $driverId, float $lat, float $lng): bool
    {
        return self::update($driverId, [
            'last_lat' => (string) $lat,
            'last_lng' => (string) $lng,
            'last_seen_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function user(array $driver): ?array
    {
        return User::find((int) $driver['user_id']);
    }
}
