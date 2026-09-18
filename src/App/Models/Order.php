<?php

namespace Keel\App\Models;

class Order extends Model
{
    protected const TABLE = 'orders';

    protected const COLUMNS = [
        'customer_id', 'restaurant_id', 'driver_id', 'address_snapshot', 'status',
        'route_miles', 'prep_minutes', 'placed_at', 'accepted_at', 'rejected_at', 'ready_at',
        'driver_assigned_at', 'driver_eta_at', 'arrived_at_restaurant_at', 'picked_up_at',
        'arrived_at_customer_at', 'delivered_at', 'cancelled_at', 'needs_attention_at',
        'authorized_cents', 'captured_cents', 'stripe_payment_intent_id',
        'stripe_fee_cents', 'is_member_order', 'cancel_reason', 'reject_reason',
        'delivery_photo',
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

    /**
     * The statuses in which an order is in a driver's hands.
     *
     * Dispatch has finished by the time an order is in this list, so these are
     * also exactly the statuses whose screen the driver app shows: one run, four
     * taps, nothing else on screen.
     */
    public const DRIVER_ACTIVE_STATUSES = [
        self::STATUS_DRIVER_ASSIGNED,
        self::STATUS_ARRIVED_AT_RESTAURANT,
        self::STATUS_PICKED_UP,
        self::STATUS_ARRIVED_AT_CUSTOMER,
    ];

    /**
     * The one run this driver is on, if any.
     *
     * A driver carries one order at a time — drivers.idle says so and dispatch
     * enforces it — so this is a single row rather than a list. Ordering by id
     * descending is belt and braces: if an earlier run were ever left open by a
     * crash, the current one still wins.
     */
    public static function activeForDriver(int $driverId): ?array
    {
        $placeholders = implode(', ', array_fill(0, count(self::DRIVER_ACTIVE_STATUSES), '?'));

        return self::queryOne(
            'SELECT * FROM orders
             WHERE driver_id = ? AND status IN (' . $placeholders . ')
             ORDER BY id DESC
             LIMIT 1',
            array_merge([$driverId], self::DRIVER_ACTIVE_STATUSES)
        );
    }

    /**
     * One of this driver's orders, loaded by both ids at once.
     *
     * Every driver route that carries an order id goes through here rather than
     * reading the row and then deciding whether it was allowed to: the query
     * either finds their order or finds nothing.
     */
    public static function forDriverAndId(int $driverId, int $orderId): ?array
    {
        return self::queryOne(
            'SELECT * FROM orders WHERE id = ? AND driver_id = ? LIMIT 1',
            [$orderId, $driverId]
        );
    }

    /**
     * An order with everything the delivery screen needs about the far ends of
     * the run: where the food is, and who it is going to.
     *
     * The customer's name and phone ride along because the screen needs them the
     * moment the food is in the car and a second query at that point would be a
     * second query on a pavement. What the *view* shows, and when, is the
     * view's rule, not this query's: nothing here is rendered before pickup.
     */
    public static function withEndsForDriver(int $driverId, int $orderId): ?array
    {
        return self::queryOne(
            'SELECT o.*,
                    r.name AS restaurant_name, r.phone AS restaurant_phone,
                    r.line1 AS restaurant_line1, r.line2 AS restaurant_line2,
                    r.city AS restaurant_city, r.state AS restaurant_state,
                    r.zip AS restaurant_zip, r.lat AS restaurant_lat, r.lng AS restaurant_lng,
                    c.name AS customer_name, c.phone AS customer_phone
             FROM orders o
             INNER JOIN restaurants r ON r.id = o.restaurant_id
             INNER JOIN users c ON c.id = o.customer_id
             WHERE o.id = ? AND o.driver_id = ?
             LIMIT 1',
            [$orderId, $driverId]
        );
    }

    /**
     * The same joins, for an order a driver has only been offered.
     *
     * An offer is not an assignment, so this matches on the order id alone and
     * the caller is the one that has checked the offer belongs to this driver.
     * It deliberately selects no customer row: an offer card shows a restaurant,
     * two distances and a payout, and the person waiting at the other end is
     * none of a driver's business until they have said yes.
     */
    public static function withRestaurant(int $orderId): ?array
    {
        return self::queryOne(
            'SELECT o.*,
                    r.name AS restaurant_name,
                    r.line1 AS restaurant_line1, r.city AS restaurant_city,
                    r.lat AS restaurant_lat, r.lng AS restaurant_lng
             FROM orders o
             INNER JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.id = ?
             LIMIT 1',
            [$orderId]
        );
    }

    /**
     * This driver's delivered runs in a window, newest first, with the final
     * breakdown attached.
     *
     * The breakdown is joined rather than fetched per row because the earnings
     * screen is a list of thirty of them and a driver opens it on a phone in a
     * car park. Only the final stage is joined: an estimate is what somebody was
     * shown, and this screen is about what was earned.
     */
    public static function driverEarnings(int $driverId, string $startUtc, string $endUtc): array
    {
        return self::query(
            'SELECT o.id, o.delivered_at, o.route_miles,
                    r.name AS restaurant_name,
                    b.driver_base_cents, b.driver_mileage_cents, b.driver_guaranteed_cents,
                    b.wait_pay_cents, b.tip_cents
             FROM orders o
             INNER JOIN restaurants r ON r.id = o.restaurant_id
             INNER JOIN order_price_breakdown b ON b.order_id = o.id AND b.stage = ?
             WHERE o.driver_id = ?
               AND o.status = ?
               AND o.delivered_at >= ?
               AND o.delivered_at < ?
             ORDER BY o.delivered_at DESC, o.id DESC',
            [OrderPriceBreakdown::STAGE_FINAL, $driverId, self::STATUS_DELIVERED, $startUtc, $endUtc]
        );
    }

    /**
     * Does this drop-off need a photograph?
     *
     * Only when the customer asked for the food to be left. Somebody who opens
     * the door has seen the driver and the driver has seen them; a bag on a
     * doorstep has nobody to say it arrived, which is the whole reason the spec
     * makes the photo mandatory in exactly this case and optional everywhere
     * else.
     *
     * The pattern is deliberately loose. Customers write "leave at door", "leave
     * it at the door" and "please just leave by the front door", and a driver
     * whose phone insists on a photo they did not expect is a smaller problem
     * than a doorstep drop with no proof.
     */
    public static function requiresDeliveryPhoto(array $order): bool
    {
        $instructions = trim((string) (self::addressSnapshot($order)['instructions'] ?? ''));

        if ($instructions === '') {
            return false;
        }

        return preg_match('/\bleave\b.{0,24}\bdoor\b/is', $instructions) === 1;
    }

    public static function withStatus(string $status): array
    {
        return self::allBy('status', $status, 'created_at ASC, id ASC');
    }

    /** The statuses the kitchen board's three columns are built from. */
    public const BOARD_STATUSES = [
        self::STATUS_PLACED,
        self::STATUS_ACCEPTED,
        self::STATUS_READY,
        self::STATUS_DRIVER_ASSIGNED,
    ];

    /**
     * Everything live on one restaurant's board, oldest first.
     *
     * The customer's and driver's names come along, because the card shows both
     * and fetching them per card would make a five-second poll a few dozen
     * queries. The view shows only the customer's first name — a kitchen
     * calling out an order has no business with the rest.
     */
    public static function boardForRestaurant(int $restaurantId): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::BOARD_STATUSES), '?'));

        return self::query(
            'SELECT o.*, c.name AS customer_name, du.name AS driver_name
             FROM orders o
             INNER JOIN users c ON c.id = o.customer_id
             LEFT JOIN drivers d ON d.id = o.driver_id
             LEFT JOIN users du ON du.id = d.user_id
             WHERE o.restaurant_id = ? AND o.status IN (' . $placeholders . ')
             ORDER BY o.placed_at ASC, o.id ASC',
            array_merge([$restaurantId], self::BOARD_STATUSES)
        );
    }

    /**
     * The restaurant an order belongs to, for the scope check, without pulling
     * the whole row.
     */
    public static function restaurantIdFor(int $orderId): ?int
    {
        $row = self::queryOne('SELECT restaurant_id FROM orders WHERE id = ? LIMIT 1', [$orderId]);

        return $row === null ? null : (int) $row['restaurant_id'];
    }

    /**
     * One customer's orders in an America/New_York calendar month, ignoring the
     * ones nobody was charged for.
     *
     * The month boundary is resolved against the real zone in PHP and compared
     * against stored UTC, the same way the restaurant's monthly count is, so the
     * two never disagree about which month an order fell in.
     */
    public static function forCustomerInPeriod(int $customerId, string $period): array
    {
        [$startUtc, $endUtc] = self::periodBoundsUtc($period);

        return self::query(
            'SELECT * FROM orders
             WHERE customer_id = ?
               AND placed_at >= ?
               AND placed_at < ?
               AND status NOT IN (?, ?)
             ORDER BY placed_at ASC, id ASC',
            [$customerId, $startUtc, $endUtc, self::STATUS_CANCELLED, self::STATUS_REJECTED]
        );
    }

    /**
     * An order with the driver's first name and vehicle attached, which is all
     * the tracking page is allowed to show about whoever is carrying the food.
     */
    public static function withDriverForCustomer(int $customerId, int $orderId): ?array
    {
        return self::queryOne(
            'SELECT o.*, du.name AS driver_name, d.vehicle_make, d.vehicle_model,
                    d.vehicle_color, d.last_lat AS driver_last_lat, d.last_lng AS driver_last_lng
             FROM orders o
             LEFT JOIN drivers d ON d.id = o.driver_id
             LEFT JOIN users du ON du.id = d.user_id
             WHERE o.id = ? AND o.customer_id = ?
             LIMIT 1',
            [$orderId, $customerId]
        );
    }

    /**
     * The statuses during which a customer may watch the driver move.
     *
     * Before pickup there is nothing of theirs to follow, and after delivery the
     * driver's whereabouts stop being any of the customer's business.
     */
    public const TRACKABLE_STATUSES = [
        self::STATUS_PICKED_UP,
        self::STATUS_ARRIVED_AT_CUSTOMER,
    ];

    public static function isTrackable(array $order): bool
    {
        return in_array((string) ($order['status'] ?? ''), self::TRACKABLE_STATUSES, true);
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

    /**
     * The only part of the customer's name the kitchen sees.
     */
    public static function customerFirstName(array $order): string
    {
        $name = trim((string) ($order['customer_name'] ?? ''));

        if ($name === '') {
            return 'Customer';
        }

        return explode(' ', $name)[0];
    }
}
