<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class DispatchOffer extends Model
{
    protected const TABLE = 'dispatch_offers';

    protected const COLUMNS = [
        'order_id', 'driver_id', 'round', 'guaranteed_cents', 'offered_at',
        'expires_at', 'response', 'responded_at',
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

    /**
     * The one offer a driver is being shown, if any.
     *
     * Dispatch sends one at a time, so this is a single row; ordering by the
     * soonest deadline means that if a stale row ever survived a worker outage
     * the driver is still shown the one that is actually running out.
     */
    public static function currentForDriver(int $driverId): ?array
    {
        return self::queryOne(
            'SELECT * FROM dispatch_offers
             WHERE driver_id = ? AND response = ? AND expires_at > UTC_TIMESTAMP()
             ORDER BY expires_at ASC, id ASC
             LIMIT 1',
            [$driverId, self::RESPONSE_PENDING]
        );
    }

    /**
     * One offer, loaded by both ids at once.
     *
     * The driver routes that carry an offer id go through here, so there is no
     * path that reads somebody else's offer and then decides whether it should
     * have: the query either finds theirs or finds nothing.
     */
    public static function forDriverAndId(int $driverId, int $offerId): ?array
    {
        return self::queryOne(
            'SELECT * FROM dispatch_offers WHERE id = ? AND driver_id = ? LIMIT 1',
            [$offerId, $driverId]
        );
    }

    /**
     * The offer this order is currently waiting on, if its clock is still
     * running. What "only one pending offer per order at a time" is checked
     * against.
     */
    public static function pendingForOrder(int $orderId): ?array
    {
        return self::queryOne(
            'SELECT * FROM dispatch_offers
             WHERE order_id = ? AND response = ? AND expires_at > UTC_TIMESTAMP()
             ORDER BY id DESC
             LIMIT 1',
            [$orderId, self::RESPONSE_PENDING]
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
     * Closes this one order's offers whose clock ran out. Returns how many.
     *
     * Dispatch calls this before it looks for the next driver, so an order whose
     * timeout job never ran still advances the moment anything touches it. It is
     * scoped to one order on purpose: sweeping the whole table here would mark
     * other orders' offers expired without anybody then dispatching their next
     * round, which is worse than leaving them for their own job.
     */
    public static function expireStaleForOrder(int $orderId): int
    {
        $statement = Database::connection()->prepare(
            'UPDATE dispatch_offers
             SET response = ?, responded_at = UTC_TIMESTAMP()
             WHERE order_id = ? AND response = ? AND expires_at <= UTC_TIMESTAMP()'
        );
        $statement->execute([self::RESPONSE_EXPIRED, $orderId, self::RESPONSE_PENDING]);

        return $statement->rowCount();
    }

    /**
     * Closes every offer still open on this order, for the round that ended with
     * somebody accepting.
     */
    public static function expireOthersForOrder(int $orderId, int $keepOfferId): int
    {
        $statement = Database::connection()->prepare(
            'UPDATE dispatch_offers
             SET response = ?, responded_at = UTC_TIMESTAMP()
             WHERE order_id = ? AND id <> ? AND response = ?'
        );
        $statement->execute([self::RESPONSE_EXPIRED, $orderId, $keepOfferId, self::RESPONSE_PENDING]);

        return $statement->rowCount();
    }

    /**
     * Sweeps offers whose clock ran out. Returns how many were closed.
     */
    public static function expireStale(): int
    {
        $statement = Database::connection()->prepare(
            'UPDATE dispatch_offers
             SET response = ?, responded_at = UTC_TIMESTAMP()
             WHERE response = ? AND expires_at <= UTC_TIMESTAMP()'
        );
        $statement->execute([self::RESPONSE_EXPIRED, self::RESPONSE_PENDING]);

        return $statement->rowCount();
    }

    /**
     * Seconds left on an offer, never negative.
     */
    public static function secondsLeft(array $offer, ?int $now = null): int
    {
        $expires = strtotime((string) ($offer['expires_at'] ?? '') . ' UTC');

        if ($expires === false) {
            return 0;
        }

        return max(0, $expires - ($now ?? time()));
    }
}
