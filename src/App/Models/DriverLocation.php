<?php

namespace Keel\App\Models;

class DriverLocation extends Model
{
    protected const TABLE = 'driver_locations';

    protected const COLUMNS = ['driver_id', 'order_id', 'lat', 'lng', 'recorded_at'];

    public static function latestForDriver(int $driverId): ?array
    {
        return self::queryOne(
            'SELECT * FROM driver_locations WHERE driver_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1',
            [$driverId]
        );
    }

    /**
     * The trail for one order. Customers may read this only while their own
     * delivery is active, which the route enforces, not this model.
     */
    public static function forOrder(int $orderId): array
    {
        return self::allBy('order_id', $orderId, 'recorded_at ASC, id ASC');
    }

    public static function latestForOrder(int $orderId): ?array
    {
        return self::queryOne(
            'SELECT * FROM driver_locations WHERE order_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1',
            [$orderId]
        );
    }
}
