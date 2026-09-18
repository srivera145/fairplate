<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class DriverLocation extends Model
{
    protected const TABLE = 'driver_locations';

    protected const COLUMNS = ['driver_id', 'order_id', 'lat', 'lng', 'recorded_at'];

    /** How long a trail is kept before PurgeDriverLocationsJob removes it. */
    public const RETENTION_DAYS = 30;

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

    /**
     * One ping.
     *
     * order_id is nullable and usually null: most pings come from a driver who
     * is online and waiting, and belong to nobody's order. The ones that do
     * carry an order id are the ones a customer is allowed to watch, which is
     * what makes "only during their own active delivery" a property of the row
     * rather than a promise the reader has to keep.
     *
     * Coordinates are bound as strings so they reach DECIMAL(10,7) exactly as
     * the phone reported them.
     */
    public static function record(int $driverId, float $lat, float $lng, ?int $orderId = null): int
    {
        return self::create([
            'driver_id' => $driverId,
            'order_id' => $orderId,
            'lat' => (string) $lat,
            'lng' => (string) $lng,
            'recorded_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Drops everything older than the retention window. Returns rows removed.
     *
     * This is the one table in FairPlate that grows with time rather than with
     * business, at four pings a minute per driver on shift. Thirty days is long
     * enough to answer "where was the driver" about any order anybody is still
     * arguing over, and short enough that a fleet does not leave a permanent
     * record of everywhere its drivers have ever been.
     */
    public static function purgeOlderThan(int $days = self::RETENTION_DAYS): int
    {
        $statement = Database::connection()->prepare(
            'DELETE FROM driver_locations WHERE recorded_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)'
        );
        $statement->bindValue(1, max(1, $days), \PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }
}
