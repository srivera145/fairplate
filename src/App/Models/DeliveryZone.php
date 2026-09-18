<?php

namespace Keel\App\Models;

class DeliveryZone extends Model
{
    protected const TABLE = 'delivery_zones';

    protected const COLUMNS = [
        'name', 'type', 'polygon', 'center_lat', 'center_lng', 'radius_m', 'active',
    ];

    public const TYPE_POLYGON = 'polygon';
    public const TYPE_RADIUS = 'radius';

    public static function active(): array
    {
        return self::query('SELECT * FROM delivery_zones WHERE active = 1 ORDER BY id ASC');
    }

    public static function all(): array
    {
        return self::query('SELECT * FROM delivery_zones ORDER BY id ASC');
    }

    public static function findByName(string $name): ?array
    {
        return self::firstBy('name', $name);
    }

    public static function restaurants(int $zoneId): array
    {
        return self::query(
            'SELECT * FROM restaurants WHERE delivery_zone_id = ? ORDER BY name ASC',
            [$zoneId]
        );
    }
}
