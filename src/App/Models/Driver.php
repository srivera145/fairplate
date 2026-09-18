<?php

namespace Keel\App\Models;

class Driver extends Model
{
    protected const TABLE = 'drivers';

    protected const COLUMNS = [
        'user_id', 'vehicle_make', 'vehicle_model', 'vehicle_color', 'plate',
        'approved', 'online', 'idle', 'last_lat', 'last_lng', 'last_seen_at',
        'stripe_account_id', 'payouts_enabled',
    ];

    /**
     * How stale a driver's last ping may be and still count as out there.
     *
     * Two minutes, per the spec. Online is a switch somebody flipped; this is
     * the evidence that they are still holding the phone. A driver who parked,
     * went inside and left the app open is online and unreachable, and an offer
     * sent to them is forty-five seconds nobody gets the food.
     */
    public const SEEN_WITHIN_SECONDS = 120;

    public static function forUser(int $userId): ?array
    {
        return self::firstBy('user_id', $userId);
    }

    /**
     * The driver behind a connected account, for webhooks that arrive carrying
     * only the account id.
     */
    public static function findByStripeAccount(string $accountId): ?array
    {
        return trim($accountId) === '' ? null : self::firstBy('stripe_account_id', $accountId);
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
     * Drivers who have finished onboarding and are waiting on an admin.
     */
    public static function awaitingApproval(): array
    {
        return self::query(
            'SELECT d.*, u.name AS user_name, u.phone AS user_phone
             FROM drivers d
             INNER JOIN users u ON u.id = d.user_id
             WHERE d.approved = 0
             ORDER BY d.created_at ASC, d.id ASC'
        );
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

    /**
     * Who this order may still be offered to.
     *
     * Four filters, and each one is a way an offer would otherwise be wasted:
     *
     *   - dispatchable() is the standing test — approved, online, idle;
     *   - a position, recent enough to be believed, because an offer is ranked
     *     by distance and a driver with no coordinates cannot be ranked at all;
     *   - nobody who already said no to this order or let it run out, which the
     *     spec requires and which also stops a two-driver town from offering the
     *     same order back and forth until the clock kills it;
     *   - nobody holding a live offer for something else. One at a time is the
     *     rule, and a driver deciding between two cards is a driver about to
     *     lose both.
     *
     * A pending offer whose clock has already run out does not block: the
     * timeout job closes those, and a worker that fell over must not be able to
     * take the whole fleet out of circulation with it.
     */
    public static function candidatesForOrder(int $orderId, int $seenWithinSeconds = self::SEEN_WITHIN_SECONDS): array
    {
        return self::query(
            'SELECT d.* FROM drivers d
             WHERE d.approved = 1
               AND d.online = 1
               AND d.idle = 1
               AND d.last_lat IS NOT NULL
               AND d.last_lng IS NOT NULL
               AND d.last_seen_at IS NOT NULL
               AND d.last_seen_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)
               AND d.id NOT IN (
                   SELECT o.driver_id FROM dispatch_offers o
                   WHERE o.order_id = ? AND o.response IN (?, ?)
               )
               AND d.id NOT IN (
                   SELECT p.driver_id FROM dispatch_offers p
                   WHERE p.response = ? AND p.expires_at > UTC_TIMESTAMP()
               )
             ORDER BY d.id ASC',
            [
                $seenWithinSeconds,
                $orderId,
                DispatchOffer::RESPONSE_DECLINED,
                DispatchOffer::RESPONSE_EXPIRED,
                DispatchOffer::RESPONSE_PENDING,
            ]
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

    public static function isApproved(?array $driver): bool
    {
        return $driver !== null && (int) ($driver['approved'] ?? 0) === 1;
    }

    public static function isOnline(?array $driver): bool
    {
        return $driver !== null && (int) ($driver['online'] ?? 0) === 1;
    }

    /**
     * Has this driver told us what they drive?
     *
     * The vehicle is the part of onboarding a customer sees — it is how they
     * pick the car out of the street — so a profile without one is not finished
     * however much else has been filled in.
     */
    public static function hasVehicle(?array $driver): bool
    {
        return $driver !== null
            && trim((string) ($driver['vehicle_make'] ?? '')) !== ''
            && trim((string) ($driver['vehicle_model'] ?? '')) !== '';
    }

    /**
     * One line for a customer's tracking screen and the driver's own profile.
     */
    public static function vehicleLine(?array $driver): string
    {
        if ($driver === null) {
            return '';
        }

        return trim(implode(' ', array_filter([
            trim((string) ($driver['vehicle_color'] ?? '')),
            trim((string) ($driver['vehicle_make'] ?? '')),
            trim((string) ($driver['vehicle_model'] ?? '')),
        ], static fn (string $part): bool => $part !== '')));
    }
}
