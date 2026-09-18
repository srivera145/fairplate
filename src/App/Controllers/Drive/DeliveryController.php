<?php

namespace Keel\App\Controllers\Drive;

use Keel\App\Models\Address;
use Keel\App\Models\Order;
use Keel\App\Models\OrderItem;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\ImageService;
use Keel\App\Services\OrderLifecycle;
use Keel\App\Services\OrderLifecycleException;
use Keel\App\Services\Pricing\PricingService;
use Keel\Core\Request;

/**
 * The run: four taps, and the screen between them.
 *
 * Each step is one button, big enough to hit without looking, and each one
 * changes exactly one thing. There is no back: a driver who taps "Picked up"
 * before the bag is in their hand does not need an undo, they need the next
 * thirty seconds, and the kitchen already knows.
 *
 * Two rules from the spec shape what is on screen, and both are about the
 * customer rather than the driver.
 *
 * The customer's phone number and address are not shown before pickup. Until
 * the food is in the car there is nothing a driver needs either for — they are
 * going to a restaurant — and an address on screen for twenty minutes before it
 * is needed is an address on screen for twenty minutes.
 *
 * The wait-pay counter starts only once the free minutes are gone. A number
 * ticking from zero would be a promise of nothing for ten minutes; a number
 * that appears when it starts costing is the truth about when the driver
 * started being paid to stand there.
 */
class DeliveryController extends DriverController
{
    /**
     * The run, in order. Each step's column is the one its transition stamps,
     * which is how the screen knows where it is without a second state machine.
     */
    public const STEPS = [
        [
            'action' => 'arrived-restaurant',
            'status' => Order::STATUS_ARRIVED_AT_RESTAURANT,
            'column' => 'arrived_at_restaurant_at',
            'label' => 'At the restaurant',
            'cta' => "I'm at the restaurant",
            'navigate' => 'restaurant',
        ],
        [
            'action' => 'picked-up',
            'status' => Order::STATUS_PICKED_UP,
            'column' => 'picked_up_at',
            'label' => 'Picked up',
            'cta' => 'Picked up',
            'navigate' => 'restaurant',
        ],
        [
            'action' => 'arrived-customer',
            'status' => Order::STATUS_ARRIVED_AT_CUSTOMER,
            'column' => 'arrived_at_customer_at',
            'label' => 'At the address',
            'cta' => "I'm at the address",
            'navigate' => 'customer',
        ],
        [
            'action' => 'delivered',
            'status' => Order::STATUS_DELIVERED,
            'column' => 'delivered_at',
            'label' => 'Delivered',
            'cta' => 'Delivered',
            'navigate' => 'customer',
        ],
    ];

    public function show(Request $request, string $id): void
    {
        // Ownership, not approval. Approval gates taking new work; an order this
        // driver is already carrying has to be finishable whatever an admin does
        // to their account mid-run, because the food is in the car either way.
        $driver = $this->requireDriverProfile();
        $driverId = (int) $driver['id'];

        // Loaded by both ids at once. There is no path here that reads an order
        // and then works out whether this driver was allowed to see it.
        $order = Order::withEndsForDriver($driverId, (int) $id);

        if ($order === null) {
            $this->forbidden();
        }

        $this->view('drive.delivery', array_merge(
            $this->shell($driver, 'home', 'Delivery'),
            $this->deliveryData($order),
            ['activePingSeconds' => $this->pingSeconds('location_ping_active_seconds')]
        ));
    }

    public function arrivedAtRestaurant(Request $request, string $id): void
    {
        $this->step(
            (int) $id,
            static fn (int $orderId) => OrderLifecycle::arriveAtRestaurant($orderId),
            'At the restaurant. The wait clock is running.'
        );
    }

    public function pickedUp(Request $request, string $id): void
    {
        $this->step(
            (int) $id,
            static fn (int $orderId) => OrderLifecycle::pickUp($orderId),
            'Got it. Head to the customer.'
        );
    }

    public function arrivedAtCustomer(Request $request, string $id): void
    {
        $this->step(
            (int) $id,
            static fn (int $orderId) => OrderLifecycle::arriveAtCustomer($orderId),
            'At the address.'
        );
    }

    /**
     * Handed over, and priced for the last time.
     *
     * The photo is taken first and the delivery recorded second, so a failed
     * upload leaves an order that can be delivered again rather than one that is
     * delivered with no proof. Where the customer asked for the food to be left
     * the photo is the only evidence it arrived, so a missing one stops the tap
     * rather than being noted and ignored.
     */
    public function delivered(Request $request, string $id): void
    {
        $driver = $this->requireDriverProfile();
        $driverId = (int) $driver['id'];
        $orderId = (int) $id;
        $order = $this->ownedOrder($driverId, $orderId);

        $file = $_FILES['photo'] ?? null;
        $hasUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $required = Order::requiresDeliveryPhoto($order);

        if ($required && !$hasUpload) {
            $this->back(
                '/drive/orders/' . $orderId,
                'This one is a leave-at-door. A photo is needed before it can be marked delivered.',
                'bad'
            );
        }

        $attributes = [];

        if ($hasUpload) {
            try {
                $attributes['delivery_photo'] = ImageService::storeUpload($file, 'deliveries/' . $orderId);
            } catch (\RuntimeException $exception) {
                $this->back('/drive/orders/' . $orderId, $exception->getMessage(), 'bad');
            }
        }

        try {
            OrderLifecycle::deliver($orderId, $attributes);
        } catch (OrderLifecycleException $exception) {
            $this->back('/drive/orders/' . $orderId, $this->staleMessage($exception), 'bad');
        }

        $this->back('/drive', 'Delivered. Nice one.');
    }

    /**
     * One step, with the two checks every step shares: this driver is cleared to
     * work, and this order is theirs.
     */
    private function step(int $orderId, callable $move, string $done): never
    {
        $driver = $this->requireApprovedDriver();
        $this->ownedOrder((int) $driver['id'], $orderId);

        try {
            $move($orderId);
        } catch (OrderLifecycleException $exception) {
            $this->back('/drive/orders/' . $orderId, $this->staleMessage($exception), 'bad');
        }

        $this->back('/drive/orders/' . $orderId, $done);
    }

    /**
     * Everything the delivery screen reads.
     *
     * @return array<string, mixed>
     */
    private function deliveryData(array $order): array
    {
        $orderId = (int) $order['id'];
        $status = (string) $order['status'];
        $pickedUp = ($order['picked_up_at'] ?? null) !== null;
        $row = $this->pricedRow($orderId);
        $address = Order::addressSnapshot($order);

        return [
            'order' => $order,
            'items' => $this->itemsWithOptions($orderId),
            'address' => $address,
            'steps' => $this->steps($order),
            // The spec's rule, in one boolean the view reads twice. Before the
            // food is in the car a driver is going to a restaurant, and the
            // person at the other end is not yet any of their business.
            'showCustomer' => $pickedUp,
            'requiresPhoto' => Order::requiresDeliveryPhoto($order),
            'pay' => $row === null ? null : (new PricingService())->driverOffer($row),
            'wait' => $this->waitTerms($order, $row),
            'restaurantAddressLine' => $this->restaurantAddress($order),
            'restaurantNavigateUrl' => $this->navigateUrl(
                $order['restaurant_lat'] ?? null,
                $order['restaurant_lng'] ?? null,
                $this->restaurantAddress($order)
            ),
            'customerNavigateUrl' => $pickedUp ? $this->navigateUrl(
                $address['lat'] ?? null,
                $address['lng'] ?? null,
                Address::oneLine($address)
            ) : null,
            'isFinished' => in_array($status, Order::TERMINAL_STATUSES, true),
        ];
    }

    /**
     * The wait-pay terms this order was priced against, and what has been earned
     * so far.
     *
     * The terms come off the order's frozen snapshot rather than from live
     * settings: a driver standing in a restaurant is owed what the order
     * promised, not what an admin changed the rate to while they were waiting.
     * arrived_at goes out as a UTC timestamp so the live counter on the page
     * counts from the server's clock rather than the phone's.
     *
     * @return array<string, mixed>|null
     */
    private function waitTerms(array $order, ?array $row): ?array
    {
        if ($row === null || ($order['arrived_at_restaurant_at'] ?? null) === null) {
            return null;
        }

        $snapshot = OrderPriceBreakdown::settingsSnapshot($row);

        if ($snapshot === []) {
            return null;
        }

        $pricing = new PricingService();
        $minutes = OrderLifecycle::waitMinutes($order);

        return [
            'arrived_at' => (string) $order['arrived_at_restaurant_at'],
            'picked_up_at' => $order['picked_up_at'] ?? null,
            'free_minutes' => (int) $snapshot['driver_wait_free_minutes'],
            'per_min_cents' => (int) $snapshot['driver_wait_per_min_cents'],
            'cap_cents' => (int) $snapshot['driver_wait_cap_cents'],
            'minutes' => $minutes,
            'earned_cents' => $pricing->waitPay($minutes, $snapshot),
        ];
    }

    /**
     * The authorized breakdown, falling back to the estimate. What every number
     * on this screen is computed from.
     */
    private function pricedRow(int $orderId): ?array
    {
        return OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL)
            ?? OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_AUTHORIZED)
            ?? OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_ESTIMATE);
    }

    /**
     * Which steps are behind this order, which one it is on, and what each one's
     * button says.
     *
     * @return list<array<string, mixed>>
     */
    private function steps(array $order): array
    {
        $steps = [];
        $currentFound = false;

        foreach (self::STEPS as $step) {
            $at = $order[$step['column']] ?? null;
            $done = $at !== null;
            $current = !$done && !$currentFound;
            $currentFound = $currentFound || $current;

            $steps[] = $step + [
                'at' => $at === null ? null : (string) $at,
                'done' => $done,
                'current' => $current,
            ];
        }

        return $steps;
    }

    /**
     * A Google Maps deep link.
     *
     * Coordinates when we have them, because an address string is a search and a
     * search can land a driver at a different branch of the same chain. The
     * address is the fallback and also what the link shows, so a phone with no
     * Maps app still opens something useful.
     */
    private function navigateUrl(mixed $lat, mixed $lng, string $address): string
    {
        $destination = ($lat !== null && $lng !== null)
            ? ((float) $lat) . ',' . ((float) $lng)
            : $address;

        return 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination='
            . rawurlencode($destination);
    }

    private function restaurantAddress(array $order): string
    {
        return Address::oneLine([
            'line1' => $order['restaurant_line1'] ?? '',
            'line2' => $order['restaurant_line2'] ?? '',
            'city' => $order['restaurant_city'] ?? '',
            'state' => $order['restaurant_state'] ?? '',
            'zip' => $order['restaurant_zip'] ?? '',
        ]);
    }

    private function itemsWithOptions(int $orderId): array
    {
        $items = OrderItem::forOrder($orderId);

        foreach ($items as $index => $item) {
            $items[$index]['options'] = OrderItem::options((int) $item['id']);
        }

        return $items;
    }

    /**
     * An illegal transition from a button means this phone was showing something
     * that is no longer true — usually a tap that landed twice.
     */
    private function staleMessage(OrderLifecycleException $exception): string
    {
        error_log('[FairPlate] Driver step refused: ' . $exception->getMessage());

        return 'That order had already moved on. The screen has been refreshed.';
    }
}
