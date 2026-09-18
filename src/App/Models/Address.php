<?php

namespace Keel\App\Models;

class Address extends Model
{
    protected const TABLE = 'addresses';

    protected const COLUMNS = [
        'user_id', 'label', 'line1', 'line2', 'city', 'state', 'zip',
        'lat', 'lng', 'instructions',
    ];

    public static function forUser(int $userId): array
    {
        return self::allBy('user_id', $userId, 'id ASC');
    }

    public static function forUserAndId(int $userId, int $addressId): ?array
    {
        return self::queryOne(
            'SELECT * FROM addresses WHERE id = ? AND user_id = ? LIMIT 1',
            [$addressId, $userId]
        );
    }

    /**
     * The frozen copy written onto an order at checkout.
     */
    public static function snapshot(array $address): array
    {
        return [
            'label' => $address['label'] ?? null,
            'line1' => $address['line1'] ?? null,
            'line2' => $address['line2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'zip' => $address['zip'] ?? null,
            'lat' => $address['lat'] ?? null,
            'lng' => $address['lng'] ?? null,
            'instructions' => $address['instructions'] ?? null,
        ];
    }
}
