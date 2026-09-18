<?php

namespace Keel\App\Controllers\App;

use Keel\App\Models\DriverLocation;
use Keel\App\Models\Order;
use Keel\App\Models\OrderItem;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Models\Refund;
use Keel\App\Models\Restaurant;
use Keel\App\Models\TipAdjustment;
use Keel\App\Services\Payments\PaymentService;
use Keel\App\Services\Pricing\Breakdown;
use Keel\Core\Env;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * Orders: the one in front of you, and every one before it.
 *
 * Two rules run through all of it.
 *
 * A customer sees their own orders and nothing else. Every method here loads by
 * (order id, customer id) rather than by id, so there is no path that reads a
 * row and then decides whether it was allowed to — the query either finds their
 * order or finds nothing.
 *
 * The driver's position is not part of an order. It is a live fact about a
 * person who is out working, and it is legible only while they are carrying
 * this customer's food: from the moment it is picked up to the moment it is
 * handed over. Before that the endpoint answers "nothing yet" however often it
 * is asked, and afterwards it stops answering at all.
 */
class OrdersController extends CustomerController
{
    /**
     * The milestones a customer is shown, in order, and the column each one
     * stamps.
     *
     * Not every order status appears: driver_assigned and arrived_at_restaurant
     * are real and are recorded, but "a driver has been found" and "the driver
     * is standing at the counter" are the same step to somebody waiting at home,
     * so they share one.
     */
    public const STEPS = [
        ['key' => 'placed', 'label' => 'Order placed', 'columns' => ['placed_at']],
        ['key' => 'accepted', 'label' => 'Kitchen accepted', 'columns' => ['accepted_at']],
        ['key' => 'ready', 'label' => 'Driver on the way to the kitchen', 'columns' => ['driver_assigned_at', 'ready_at']],
        ['key' => 'picked_up', 'label' => 'Picked up', 'columns' => ['picked_up_at']],
        ['key' => 'delivered', 'label' => 'Delivered', 'columns' => ['delivered_at']],
    ];

    /** How often the tracking page asks again, per the spec. */
    public const POLL_SECONDS = 10;

    public function index(Request $request): void
    {
        $orders = Order::forCustomer($this->userId());
        $rows = [];

        foreach ($orders as $order) {
            $restaurant = Restaurant::find((int) $order['restaurant_id']);

            $rows[] = [
                'order' => $order,
                'restaurant' => $restaurant,
                'breakdown' => $this->latestBreakdown((int) $order['id']),
                'item_count' => $this->itemCount((int) $order['id']),
            ];
        }

        $this->view('app.orders', array_merge(
            $this->shell('orders', 'Your orders'),
            ['rows' => $rows]
        ));
    }

    public function show(Request $request, string $id): void
    {
        $order = $this->ownedOrder((int) $id);

        $this->view('app.order', array_merge(
            $this->shell('orders', 'Order #' . (int) $order['id']),
            $this->trackingData($order),
            $this->receiptData($order),
            [
                'items' => $this->itemsWithOptions((int) $order['id']),
                'mapsKey' => trim((string) Env::get('GOOGLE_MAPS_KEY', '')),
                'pollSeconds' => self::POLL_SECONDS,
            ]
        ));
    }

    /**
     * The stepper again, for the poll.
     *
     * The page swaps in whatever comes back rather than reading a status and
     * deciding what that looks like, so there is one description of a step and
     * it lives in a view file.
     */
    public function status(Request $request, string $id): void
    {
        $order = $this->ownedOrder((int) $id);
        $data = $this->trackingData($order);

        $this->json([
            'status' => (string) $order['status'],
            'trackable' => $data['trackable'],
            'signature' => md5((string) $order['status'] . '|' . (string) ($order['driver_id'] ?? '')),
            'html' => $this->renderToString('app.partials.tracking', $data),
        ]);
    }

    /**
     * Where the driver is, if the customer is allowed to know.
     *
     * Ownership is checked first and answered with a flat 403: this is the one
     * endpoint in the customer app that says "not yours" out loud, because a
     * 404 here would have somebody's live position one guessed id away from
     * looking like a bug rather than a refusal.
     */
    public function driverLocation(Request $request, string $id): never
    {
        $order = Order::forCustomerAndId($this->userId(), (int) $id);

        if ($order === null) {
            Response::json(['error' => 'Forbidden.'], 403);
        }

        if (!Order::isTrackable($order) || $order['driver_id'] === null) {
            Response::json([
                'available' => false,
                'status' => (string) $order['status'],
                'poll_seconds' => self::POLL_SECONDS,
            ]);
        }

        $location = DriverLocation::latestForOrder((int) $order['id']);

        if ($location === null) {
            Response::json([
                'available' => false,
                'status' => (string) $order['status'],
                'poll_seconds' => self::POLL_SECONDS,
            ]);
        }

        Response::json([
            'available' => true,
            'status' => (string) $order['status'],
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
            'recorded_at' => (string) $location['recorded_at'],
            'poll_seconds' => self::POLL_SECONDS,
        ]);
    }

    /**
     * Puts a past order back in the cart, and says what has moved since.
     */
    public function reorder(Request $request, string $id): void
    {
        $order = $this->ownedOrder((int) $id);
        $result = $this->cart()->reorder($this->userId(), $order);

        if ($result['added'] === 0) {
            $this->back(
                '/app/orders/' . (int) $order['id'],
                'Nothing from that order can be ordered right now. ' . implode(' ', $result['warnings']),
                'bad'
            );
        }

        if ($result['warnings'] !== []) {
            $this->back('/app/cart', 'Some things changed: ' . implode(' ', $result['warnings']), 'warn');
        }

        $this->back('/app/cart', 'Your cart is ready.');
    }

    /**
     * Everything the tracking view and its partial read.
     *
     * @return array<string, mixed>
     */
    private function trackingData(array $order): array
    {
        $orderId = (int) $order['id'];
        $withDriver = Order::withDriverForCustomer($this->userId(), $orderId) ?? $order;
        $final = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);
        $authorized = $this->latestBreakdown($orderId);

        return [
            'order' => $withDriver,
            'restaurant' => Restaurant::find((int) $order['restaurant_id']),
            'steps' => $this->steps($withDriver),
            'trackable' => Order::isTrackable($withDriver),
            'driver' => $this->driver($withDriver),
            'breakdown' => $authorized === null ? null : Breakdown::fromRow($authorized),
            'breakdownStage' => (string) ($authorized['stage'] ?? ''),
            'finalCents' => $final === null ? null : (int) $final['total_cents'],
            'authorizedCents' => $order['authorized_cents'] === null ? null : (int) $order['authorized_cents'],
            'address' => Order::addressSnapshot($order),
        ];
    }

    /**
     * Everything the receipt shows beyond the charge lines.
     *
     * Three things happen to an order's money after the capture and a customer
     * is entitled to see all of them: a tip they raised, money they were given
     * back, and what the two add up to against what was charged. They are read
     * from their own tables rather than folded into the breakdown, because the
     * breakdown is what was charged at delivery and rewriting it afterwards
     * would make a receipt that no longer matches the card statement.
     *
     * @return array<string, mixed>
     */
    private function receiptData(array $order): array
    {
        $orderId = (int) $order['id'];
        $final = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);
        $tipAdjustments = TipAdjustment::settledForOrder($orderId);
        $refunds = Refund::forOrder($orderId);

        $tipTotal = 0;

        foreach ($tipAdjustments as $adjustment) {
            $tipTotal += (int) $adjustment['charge_cents'];
        }

        return [
            'finalBreakdown' => $final === null ? null : Breakdown::fromRow($final),
            'tipAdjustments' => $tipAdjustments,
            'tipAdjustmentChargedCents' => $tipTotal,
            'refunds' => $refunds,
            'refundedCents' => Refund::totalForOrderCents($orderId),
            'capturedCents' => $order['captured_cents'] === null ? null : (int) $order['captured_cents'],
            'tipWindow' => (new PaymentService())->tipWindow($order),
        ];
    }

    /**
     * The stepper: which milestones are behind this order, which one it is on,
     * and when each happened.
     *
     * A step's time is the first of its columns that is set, so "driver on the
     * way" is stamped by whichever of ready and driver_assigned happened first —
     * the spec allows them in either order.
     *
     * @return list<array<string, mixed>>
     */
    private function steps(array $order): array
    {
        $status = (string) $order['status'];
        $steps = [];
        $currentFound = false;

        foreach (self::STEPS as $step) {
            $at = null;

            foreach ($step['columns'] as $column) {
                if (($order[$column] ?? null) !== null) {
                    $at = (string) $order[$column];
                    break;
                }
            }

            $done = $at !== null;
            $current = !$done && !$currentFound;
            $currentFound = $currentFound || $current;

            $steps[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'at' => $at,
                'done' => $done,
                'current' => $current && !in_array($status, Order::TERMINAL_STATUSES, true),
            ];
        }

        return $steps;
    }

    /**
     * The driver's first name and vehicle, and nothing else.
     *
     * A customer needs to recognise the car in the street. They do not need a
     * surname, a phone number or a photograph, so none is fetched.
     *
     * @return array{first_name: string, vehicle: string}|null
     */
    private function driver(array $order): ?array
    {
        if (($order['driver_id'] ?? null) === null) {
            return null;
        }

        $name = trim((string) ($order['driver_name'] ?? ''));
        $vehicle = trim(implode(' ', array_filter([
            (string) ($order['vehicle_color'] ?? ''),
            (string) ($order['vehicle_make'] ?? ''),
            (string) ($order['vehicle_model'] ?? ''),
        ])));

        return [
            'first_name' => $name === '' ? 'Your driver' : explode(' ', $name)[0],
            'vehicle' => $vehicle,
        ];
    }

    /**
     * The order, or a 404 that does not say whether it exists.
     */
    private function ownedOrder(int $orderId): array
    {
        $order = Order::forCustomerAndId($this->userId(), $orderId);

        if ($order === null) {
            $this->notFound();
        }

        return $order;
    }

    /**
     * The most authoritative breakdown an order has: what was charged if it is
     * settled, what was authorized if it is not.
     */
    private function latestBreakdown(int $orderId): ?array
    {
        foreach ([
            OrderPriceBreakdown::STAGE_FINAL,
            OrderPriceBreakdown::STAGE_AUTHORIZED,
            OrderPriceBreakdown::STAGE_ESTIMATE,
        ] as $stage) {
            $row = OrderPriceBreakdown::forStage($orderId, $stage);

            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    private function itemsWithOptions(int $orderId): array
    {
        $items = OrderItem::forOrder($orderId);

        foreach ($items as $index => $item) {
            $items[$index]['options'] = OrderItem::options((int) $item['id']);
        }

        return $items;
    }

    private function itemCount(int $orderId): int
    {
        $count = 0;

        foreach (OrderItem::forOrder($orderId) as $item) {
            $count += (int) $item['quantity'];
        }

        return $count;
    }
}
