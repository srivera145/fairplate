<?php

namespace Keel\App\Services;

use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\Delivery\DeliveryProvider;
use Keel\App\Services\Delivery\InHouseDriverProvider;
use Keel\App\Services\Payments\AdminAlert;
use Keel\App\Services\Payments\PaymentService;
use Keel\App\Services\Payments\PayoutService;
use Keel\App\Services\Pricing\Breakdown;
use Keel\App\Services\Pricing\PricingException;
use Keel\App\Services\Pricing\PricingService;
use Keel\Core\Activity;
use Keel\Core\Database;

/**
 * The only place an order's status is allowed to change.
 *
 * Four apps touch the same order — the customer's, the kitchen's, the driver's
 * and the admin's — and each has a screen that can be a few seconds stale.
 * Spreading `UPDATE orders SET status` across those four means the first race
 * writes a status nobody's code expects to read. So there is one door.
 *
 * TRANSITIONS is the whole rule set. Anything not listed is refused, which is
 * why the terminal statuses appear with an empty list rather than being left
 * out: "delivered goes nowhere" is a decision, not an omission.
 *
 * The spec allows ready and driver_assigned in either order, so both directions
 * appear and arrived_at_restaurant is reachable from either.
 *
 * Money moves on the transition that earned it, not on a screen somebody has to
 * remember to visit. The two hooks at the bottom are where that happens: a
 * delivery captures and pays out, and a rejection or a cancellation gives the
 * money back. Neither of them throws — the status has already changed and the
 * customer's screen already agrees, so a Stripe outage raises an alert rather
 * than rolling back a delivery that really did happen.
 */
class OrderLifecycle
{
    /** The status an order is created in. Nothing transitions into it. */
    public const INITIAL_STATUS = Order::STATUS_PLACED;

    /** Prep times the kitchen may promise when it accepts, in minutes. */
    public const PREP_MINUTES = [10, 15, 20, 30, 45];

    /** Why a kitchen turned an order down. */
    public const REJECT_REASONS = [
        'closing_soon' => 'Closing soon',
        'too_busy' => 'Too busy',
        'item_unavailable' => 'Item unavailable',
        'other' => 'Other',
    ];

    /**
     * from => the statuses it may move to. The whole rule book.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        Order::STATUS_PLACED => [
            Order::STATUS_ACCEPTED,
            Order::STATUS_REJECTED,
            Order::STATUS_CANCELLED,
            Order::STATUS_NEEDS_ATTENTION,
        ],
        Order::STATUS_ACCEPTED => [
            Order::STATUS_READY,
            Order::STATUS_DRIVER_ASSIGNED,
            Order::STATUS_CANCELLED,
            Order::STATUS_NEEDS_ATTENTION,
        ],
        Order::STATUS_READY => [
            Order::STATUS_DRIVER_ASSIGNED,
            Order::STATUS_ARRIVED_AT_RESTAURANT,
            Order::STATUS_CANCELLED,
            Order::STATUS_NEEDS_ATTENTION,
        ],
        Order::STATUS_DRIVER_ASSIGNED => [
            Order::STATUS_READY,
            Order::STATUS_ARRIVED_AT_RESTAURANT,
            Order::STATUS_CANCELLED,
            Order::STATUS_NEEDS_ATTENTION,
        ],
        Order::STATUS_ARRIVED_AT_RESTAURANT => [
            Order::STATUS_PICKED_UP,
            Order::STATUS_CANCELLED,
            Order::STATUS_NEEDS_ATTENTION,
        ],
        Order::STATUS_PICKED_UP => [
            Order::STATUS_ARRIVED_AT_CUSTOMER,
            Order::STATUS_DELIVERED,
            Order::STATUS_NEEDS_ATTENTION,
        ],
        Order::STATUS_ARRIVED_AT_CUSTOMER => [
            Order::STATUS_DELIVERED,
            Order::STATUS_NEEDS_ATTENTION,
        ],
        // An order an admin pulled aside is either finished by hand or written
        // off; it never rejoins the normal flow behind the customer's back.
        Order::STATUS_NEEDS_ATTENTION => [
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
        ],
        Order::STATUS_DELIVERED => [],
        Order::STATUS_REJECTED => [],
        Order::STATUS_CANCELLED => [],
    ];

    /**
     * True when this move is in the rule book. Screens use it to decide what to
     * offer; it is not a substitute for transition(), which re-checks.
     */
    public static function allows(string $from, string $to): bool
    {
        if (!isset(self::TRANSITIONS[$from])) {
            throw OrderLifecycleException::unknownStatus($from);
        }

        if (!isset(self::TRANSITIONS[$to])) {
            throw OrderLifecycleException::unknownStatus($to);
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * The kitchen takes the order and promises a prep time.
     *
     * This is also the moment dispatch begins. The two belong together: an
     * accepted order nobody was asked to carry is the one failure mode that
     * looks fine on every screen until the food is cold, so the request for a
     * courier hangs off the same transition rather than off a screen somebody
     * has to remember to visit.
     */
    public static function accept(int $orderId, int $prepMinutes): array
    {
        if (!in_array($prepMinutes, self::PREP_MINUTES, true)) {
            throw new OrderLifecycleException("\"{$prepMinutes}\" is not an offered prep time.");
        }

        $order = self::transition($orderId, Order::STATUS_ACCEPTED, [
            'prep_minutes' => $prepMinutes,
        ]);

        self::requestDelivery($order);

        return Order::find($orderId) ?? $order;
    }

    /**
     * Asks a provider to find somebody to carry this order.
     *
     * A provider that will not take it is not an exception — an address that
     * slipped outside a redrawn zone is a real thing that happens — but it is
     * also not something to leave sitting in accepted, because nothing else will
     * ever look at it again. So it goes straight to a person.
     */
    public static function requestDelivery(array $order): void
    {
        $orderId = (int) $order['id'];
        $provider = new InHouseDriverProvider();
        $result = $provider->request($order);

        if ($result['status'] !== DeliveryProvider::STATUS_UNAVAILABLE) {
            return;
        }

        error_log(sprintf(
            '[FairPlate] %s could not take order %d: %s',
            $provider->key(),
            $orderId,
            $result['message']
        ));

        try {
            self::transition($orderId, Order::STATUS_NEEDS_ATTENTION);
        } catch (OrderLifecycleException $exception) {
            error_log('[FairPlate] Order ' . $orderId . ' could not be escalated: ' . $exception->getMessage());
        }
    }

    /**
     * The kitchen turns the order down.
     *
     * The release hangs off this transition rather than off a screen, so the
     * hold on somebody's card comes down at the moment the kitchen says no
     * rather than whenever the next person looks.
     */
    public static function reject(int $orderId, string $reasonKey, string $note = ''): array
    {
        if (!isset(self::REJECT_REASONS[$reasonKey])) {
            throw new OrderLifecycleException("\"{$reasonKey}\" is not a rejection reason.");
        }

        $reason = self::REJECT_REASONS[$reasonKey];
        $note = trim($note);

        if ($note !== '') {
            $reason .= ': ' . $note;
        }

        $order = self::transition($orderId, Order::STATUS_REJECTED, [
            'reject_reason' => $reason,
        ]);

        // Whatever is still counting down on a driver's screen is for food that
        // is not being cooked. Take the card down before releasing the money.
        (new InHouseDriverProvider())->cancel($order, $reason);

        self::releaseAuthorization($order);

        return $order;
    }

    /**
     * The order is called off before the food is in a car.
     *
     * The rule book already refuses a cancellation after pickup — picked_up
     * goes only to arrived_at_customer, delivered or needs_attention — so
     * "before pickup" is enforced by TRANSITIONS rather than re-checked here.
     * What this adds on top of a bare transition is the same two endings a
     * rejection has: the courier's card comes down, and the money goes back.
     */
    public static function cancel(int $orderId, string $reason = ''): array
    {
        $reason = trim($reason);

        $order = self::transition($orderId, Order::STATUS_CANCELLED, [
            'cancel_reason' => $reason === '' ? null : $reason,
        ]);

        (new InHouseDriverProvider())->cancel($order, $reason);

        self::releaseAuthorization($order);

        return $order;
    }

    /**
     * The food is on the pass.
     */
    public static function markReady(int $orderId): array
    {
        return self::transition($orderId, Order::STATUS_READY);
    }

    /**
     * The driver is at the counter. The wait-pay clock starts here.
     */
    public static function arriveAtRestaurant(int $orderId): array
    {
        return self::transition($orderId, Order::STATUS_ARRIVED_AT_RESTAURANT);
    }

    /**
     * The food is in the car, and the wait-pay clock stops.
     */
    public static function pickUp(int $orderId): array
    {
        return self::transition($orderId, Order::STATUS_PICKED_UP);
    }

    /**
     * The driver is outside the customer's address.
     */
    public static function arriveAtCustomer(int $orderId): array
    {
        return self::transition($orderId, Order::STATUS_ARRIVED_AT_CUSTOMER);
    }

    /**
     * It is handed over. The order is priced for the last time here.
     *
     * Three things happen, in this order, and the order is the point:
     *
     * The final breakdown is computed and written first. It is the only thing
     * that can fail — a wait that somehow priced above the authorization would
     * throw — and failing before the status moves leaves an order that is still
     * picked_up and can be tried again, rather than one that is delivered with
     * nothing to charge against.
     *
     * Then the status moves, which stamps delivered_at.
     *
     * Then the driver goes idle and is dispatchable again, which is the one
     * side effect a driver would notice immediately if it were missed.
     *
     * Only the wait pay is recomputed. Everything else is read back exactly as
     * authorized, because the guarantee a driver accepted must not move and the
     * rest was agreed to at checkout.
     *
     * @param array<string, mixed> $attributes columns this delivery carries —
     *        the proof-of-delivery photo is the only one so far
     */
    public static function deliver(int $orderId, array $attributes = []): array
    {
        $order = Order::find($orderId);

        if ($order === null) {
            throw OrderLifecycleException::missingOrder($orderId);
        }

        $waitMinutes = self::waitMinutes($order);

        try {
            $final = (new PricingService())->finalize($order, $waitMinutes);
        } catch (PricingException $exception) {
            throw new OrderLifecycleException(
                "Order {$orderId} could not be priced for delivery: " . $exception->getMessage()
            );
        }

        self::writeFinalBreakdown($orderId, $final->toRow($orderId, OrderPriceBreakdown::STAGE_FINAL));

        $delivered = self::transition($orderId, Order::STATUS_DELIVERED, $attributes);

        if (($delivered['driver_id'] ?? null) !== null) {
            Driver::update((int) $delivered['driver_id'], ['idle' => 1]);
        }

        Activity::log('order.delivered.priced', 'Order', $orderId, [
            'wait_minutes' => $waitMinutes,
            'wait_pay_cents' => $final->line(Breakdown::WAIT_PAY),
            'final_total_cents' => $final->total(),
        ]);

        self::captureAndTransfer($delivered);

        return $delivered;
    }

    /**
     * Whole minutes from the driver reaching the restaurant to the food being
     * in the car.
     *
     * Whole minutes, floored, because that is what the spec's formula takes and
     * because a driver who waited eleven minutes and fifty seconds is owed the
     * same as one who waited eleven — rounding up would pay for a minute nobody
     * spent, and the free window is what covers the rounding either way.
     *
     * Zero until both ends exist, so a live screen asking mid-wait gets a
     * number it can show rather than a guess about a wait still running.
     */
    public static function waitMinutes(array $order): int
    {
        $arrived = $order['arrived_at_restaurant_at'] ?? null;
        $pickedUp = $order['picked_up_at'] ?? null;

        if ($arrived === null || $pickedUp === null) {
            return 0;
        }

        $from = strtotime((string) $arrived . ' UTC');
        $to = strtotime((string) $pickedUp . ' UTC');

        if ($from === false || $to === false || $to <= $from) {
            return 0;
        }

        return intdiv($to - $from, 60);
    }

    /**
     * Writes the final stage, replacing one already there.
     *
     * The table holds one row per stage, so a delivery that had to be retried
     * updates rather than collides. The stage is fixed by the caller and the
     * columns come from Breakdown, so nothing a request sent reaches the SQL.
     *
     * @param array<string, mixed> $row
     */
    private static function writeFinalBreakdown(int $orderId, array $row): void
    {
        $existing = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);

        if ($existing === null) {
            OrderPriceBreakdown::create($row);

            return;
        }

        OrderPriceBreakdown::update((int) $existing['id'], $row);
    }

    /**
     * Move an order, or refuse to.
     *
     * $attributes carries the columns that only make sense alongside this
     * particular move — the prep time on an accept, the reason on a reject. The
     * status column is set here and cannot be passed in.
     *
     * @param array<string, mixed> $attributes
     * @return array the order as it now stands
     */
    public static function transition(int $orderId, string $to, array $attributes = []): array
    {
        $order = Order::find($orderId);

        if ($order === null) {
            throw OrderLifecycleException::missingOrder($orderId);
        }

        $from = (string) $order['status'];

        if (!self::allows($from, $to)) {
            throw OrderLifecycleException::illegalTransition($orderId, $from, $to);
        }

        unset($attributes['status']);

        $timestampColumn = Order::STATUS_TIMESTAMPS[$to] ?? null;

        if ($timestampColumn !== null) {
            $attributes[$timestampColumn] = gmdate('Y-m-d H:i:s');
        }

        $assignments = ['`status` = :status'];
        $bindings = ['status' => $to, 'id' => $orderId, 'from' => $from];

        foreach ($attributes as $column => $value) {
            self::assertWritable((string) $column);

            $assignments[] = '`' . $column . '` = :' . $column;
            $bindings[$column] = $value;
        }

        // One statement, naming the status it expects to replace. A second
        // tablet that tapped Accept a moment earlier has already moved the row,
        // so this matches nothing and the loser is told, rather than
        // overwriting the winner.
        $statement = Database::connection()->prepare(
            'UPDATE orders SET ' . implode(', ', $assignments) . ' WHERE id = :id AND status = :from'
        );
        $statement->execute($bindings);

        if ($statement->rowCount() === 0) {
            $current = Order::find($orderId);
            $currentStatus = (string) ($current['status'] ?? $from);

            // Finding the status already where we wanted it means two tablets
            // tapped the same button. That is not an error.
            if ($currentStatus !== $to) {
                throw OrderLifecycleException::illegalTransition($orderId, $currentStatus, $to);
            }
        }

        Activity::log('order.' . $to, 'Order', $orderId, ['from' => $from, 'to' => $to]);

        return Order::find($orderId) ?? [];
    }

    /**
     * The order is called off. Give the money back, whichever way that means.
     *
     * Called on rejection and on a cancellation. Which of the two things it
     * does is decided by the order, not the caller: an order that has never
     * been captured has a hold to release and nobody has been charged
     * anything; one that has been captured needs a refund, and the driver's
     * transfer stands either way because they drove.
     *
     * Nothing here throws. This runs on a transition that has already happened
     * — the kitchen's tablet has already said "rejected" and the customer's
     * screen already agrees — and a Stripe outage must not roll that back or
     * leave the kitchen unable to turn down the next one. A release that fails
     * is an authorization that expires on its own in a week, and
     * AuthorizationExpiryCheckJob finds it long before then.
     */
    public static function releaseAuthorization(array $order): void
    {
        $orderId = (int) $order['id'];

        try {
            $payments = new PaymentService();

            if (!Order::isCaptured($order)) {
                $payments->release($order);

                return;
            }

            // Captured already: the customer is owed their money back, and the
            // platform absorbs it unless an admin later says it was the
            // restaurant's fault.
            $payments->refund(
                $order,
                (int) $order['captured_cents'],
                trim((string) ($order['reject_reason'] ?? $order['cancel_reason'] ?? 'Order cancelled')),
                false
            );
        } catch (\Throwable $exception) {
            AdminAlert::raise('order.release_failed', sprintf(
                'Order %d could not have its payment released: %s',
                $orderId,
                $exception->getMessage()
            ), [
                'order_id' => $orderId,
                'status' => (string) ($order['status'] ?? ''),
                'captured_cents' => $order['captured_cents'] ?? null,
            ]);
        }
    }

    /**
     * The food is handed over. Take the money, then pay everybody.
     *
     * The capture comes first and the transfers second, always. A transfer
     * names the charge it comes out of, so there is nothing to transfer from
     * until the capture exists; doing it the other way round would draw on the
     * platform's own balance, which is exactly the float this arrangement is
     * designed not to need.
     *
     * The transfers are queued rather than sent. A driver handing over a bag on
     * a pavement should not be waiting on two Stripe round trips, and a
     * transfer that fails deserves the backoff and the alerting that
     * TransferPayoutJob has rather than whatever a controller would do with the
     * exception.
     *
     * Failures do not throw, for the same reason the release does not: the
     * order is already delivered and nothing about that is going to be undone.
     * An uncaptured delivered order is exactly what the hourly authorization
     * check is for.
     */
    public static function captureAndTransfer(array $order): void
    {
        $orderId = (int) $order['id'];

        try {
            (new PaymentService())->capture($order);
        } catch (\Throwable $exception) {
            AdminAlert::raise('order.capture_failed', sprintf(
                'Order %d was delivered but could not be captured: %s',
                $orderId,
                $exception->getMessage()
            ), [
                'order_id' => $orderId,
                'authorized_cents' => $order['authorized_cents'] ?? null,
            ]);

            return;
        }

        try {
            // Re-read: the capture has just written the charge id the transfers
            // will name as their source.
            (new PayoutService())->queueForOrder(Order::find($orderId) ?? $order);
        } catch (\Throwable $exception) {
            AdminAlert::raise('order.payout_queue_failed', sprintf(
                'Order %d was captured but its payouts could not be queued: %s',
                $orderId,
                $exception->getMessage()
            ), ['order_id' => $orderId]);
        }
    }

    /**
     * Column names reach the SQL string itself, so they come from the model's
     * declared list and nowhere else.
     */
    private static function assertWritable(string $column): void
    {
        if (!in_array($column, Order::writableColumns(), true)) {
            throw new OrderLifecycleException("\"{$column}\" is not a column on orders.");
        }
    }
}
