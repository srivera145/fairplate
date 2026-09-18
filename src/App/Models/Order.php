<?php

namespace Keel\App\Models;

class Order extends Model
{
    protected const TABLE = 'orders';

    protected const COLUMNS = [
        'customer_id', 'restaurant_id', 'driver_id', 'address_snapshot', 'status',
        'route_miles', 'placed_at', 'accepted_at', 'rejected_at', 'ready_at',
        'driver_assigned_at', 'arrived_at_restaurant_at', 'picked_up_at',
        'arrived_at_customer_at', 'delivered_at', 'cancelled_at', 'needs_attention_at',
        'authorized_cents', 'captured_cents', 'stripe_payment_intent_id',
        'stripe_fee_cents', 'is_member_order', 'cancel_reason', 'reject_reason',
    ];

    public const STATUS_PLACED = 'placed';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_READY = 'ready';
    public const STATUS_DRIVER_ASSIGNED = 'driver_assigned';
    public const STATUS_ARRIVED_AT_RESTAURANT = 'arrived_at_restaurant';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_ARRIVED_AT_CUSTOMER = 'arrived_at_customer';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    /** The timestamp column each status stamps when an order reaches it. */
    public const STATUS_TIMESTAMPS = [
        self::STATUS_PLACED => 'placed_at',
        self::STATUS_ACCEPTED => 'accepted_at',
        self::STATUS_REJECTED => 'rejected_at',
        self::STATUS_READY => 'ready_at',
        self::STATUS_DRIVER_ASSIGNED => 'driver_assigned_at',
        self::STATUS_ARRIVED_AT_RESTAURANT => 'arrived_at_restaurant_at',
        self::STATUS_PICKED_UP => 'picked_up_at',
        self::STATUS_ARRIVED_AT_CUSTOMER => 'arrived_at_customer_at',
        self::STATUS_DELIVERED => 'delivered_at',
        self::STATUS_CANCELLED => 'cancelled_at',
        self::STATUS_NEEDS_ATTENTION => 'needs_attention_at',
    ];

    /** Statuses where the order is done and nothing further will happen. */
    public const TERMINAL_STATUSES = [
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
        self::STATUS_REJECTED,
    ];

    public static function forCustomer(int $customerId): array
    {
        return self::allBy('customer_id', $customerId, 'created_at DESC, id DESC');
    }

    public static function forRestaurant(int $restaurantId): array
    {
        return self::allBy('restaurant_id', $restaurantId, 'created_at DESC, id DESC');
    }

    public static function forDriver(int $driverId): array
    {
        return self::allBy('driver_id', $driverId, 'created_at DESC, id DESC');
    }

    public static function withStatus(string $status): array
    {
        return self::allBy('status', $status, 'created_at ASC, id ASC');
    }

    public static function forCustomerAndId(int $customerId, int $orderId): ?array
    {
        return self::queryOne(
            'SELECT * FROM orders WHERE id = ? AND customer_id = ? LIMIT 1',
            [$orderId, $customerId]
        );
    }

    /**
     * Completed orders in one America/New_York calendar month, which is what
     * the monthly tier fee is billed against.
     *
     * The month boundary is resolved in PHP against the real zone and the query
     * compares stored UTC. Doing it in SQL would mean a fixed offset, which
     * silently misfiles the orders either side of a daylight-saving change.
     */
    public static function completedCountForPeriod(int $restaurantId, string $period): int
    {
        [$startUtc, $endUtc] = self::periodBoundsUtc($period);

        $row = self::queryOne(
            'SELECT COUNT(*) AS completed
             FROM orders
             WHERE restaurant_id = ?
               AND status = ?
               AND delivered_at >= ?
               AND delivered_at < ?',
            [$restaurantId, self::STATUS_DELIVERED, $startUtc, $endUtc]
        );

        return (int) ($row['completed'] ?? 0);
    }

    /**
     * The UTC half-open bounds of a YYYY-MM billing period in America/New_York.
     *
     * @return array{0: string, 1: string}
     */
    public static function periodBoundsUtc(string $period): array
    {
        $billingZone = new \DateTimeZone('America/New_York');
        $utc = new \DateTimeZone('UTC');

        $start = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $period . '-01 00:00:00',
            $billingZone
        );

        if ($start === false) {
            throw new \InvalidArgumentException("Billing period \"{$period}\" is not a valid YYYY-MM month.");
        }

        $end = $start->modify('+1 month');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    public static function items(int $orderId): array
    {
        return OrderItem::forOrder($orderId);
    }

    public static function breakdown(int $orderId, string $stage): ?array
    {
        return OrderPriceBreakdown::forStage($orderId, $stage);
    }

    public static function addressSnapshot(array $order): array
    {
        $snapshot = $order['address_snapshot'] ?? null;

        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        return is_array($snapshot) ? $snapshot : [];
    }

    public static function isTerminal(array $order): bool
    {
        return in_array((string) ($order['status'] ?? ''), self::TERMINAL_STATUSES, true);
    }
}
