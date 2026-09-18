<?php

namespace Keel\App\Models;

class DispatchOffer extends Model
{
    protected const TABLE = 'dispatch_offers';

    protected const COLUMNS = [
        'order_id', 'driver_id', 'round', 'offered_at', 'expires_at',
        'response', 'responded_at',
    ];

    public const RESPONSE_PENDING = 'pending';
    public const RESPONSE_ACCEPTED = 'accepted';
    public const RESPONSE_DECLINED = 'declined';
    public const RESPONSE_EXPIRED = 'expired';

    public static function forOrder(int $orderId): array
    {
        return self::allBy('order_id', $orderId, '`round` ASC, id ASC');
    }

    public static function forDriver(int $driverId): array
    {
        return self::allBy('driver_id', $driverId, 'id DESC');
    }

    /**
     * Live offers a driver still has time to answer.
     */
    public static function openForDriver(int $driverId): array
    {
        return self::query(
            'SELECT * FROM dispatch_offers
             WHERE driver_id = ? AND response = ? AND expires_at > UTC_TIMESTAMP()
             ORDER BY expires_at ASC',
            [$driverId, self::RESPONSE_PENDING]
        );
    }

    public static function acceptedForOrder(int $orderId): ?array
    {
        return self::queryOne(
            'SELECT * FROM dispatch_offers WHERE order_id = ? AND response = ? LIMIT 1',
            [$orderId, self::RESPONSE_ACCEPTED]
        );
    }

    public static function latestRound(int $orderId): int
    {
        $row = self::queryOne(
            'SELECT COALESCE(MAX(`round`), 0) AS latest FROM dispatch_offers WHERE order_id = ?',
            [$orderId]
        );

        return (int) ($row['latest'] ?? 0);
    }

    /**
     * Sweeps offers whose clock ran out. Returns how many were closed.
     */
    public static function expireStale(): int
    {
        $statement = \Keel\Core\Database::connection()->prepare(
            'UPDATE dispatch_offers
             SET response = ?, responded_at = UTC_TIMESTAMP()
             WHERE response = ? AND expires_at <= UTC_TIMESTAMP()'
        );
        $statement->execute([self::RESPONSE_EXPIRED, self::RESPONSE_PENDING]);

        return $statement->rowCount();
    }
}
