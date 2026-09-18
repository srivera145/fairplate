<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Cart;
use Keel\App\Models\DriverLocation;
use Keel\App\Models\MenuItem;
use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\CartService;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * Tracking, receipts and reordering.
 *
 * The driver's position is the sharp edge here. It is a live fact about a
 * person who is out working, and a customer may read it only while that person
 * is carrying their food. Both halves of that — whose order it is, and what
 * state the order is in — are enforced by the endpoint, not by the page that
 * calls it.
 */
class CustomerOrderTrackingFeatureTest extends TestCase
{
    use CustomerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPricingSettings();
    }

    protected function tearDown(): void
    {
        $this->restoreCollaborators();

        parent::tearDown();
    }

    public function testTheTrackingPageShowsTheStepperTheTimestampsAndThePrepTime(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $orderId = $this->createChargedOrder($userId, $shop['restaurant_id']);
        Order::update($orderId, [
            'accepted_at' => gmdate('Y-m-d H:i:s'),
            'prep_minutes' => 20,
        ]);
        $this->forceStatus($orderId, Order::STATUS_ACCEPTED);

        $response = $this->get('/app/orders/' . $orderId);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('stepper', $response->body);
        self::assertStringContainsString('Order placed', $response->body);
        self::assertStringContainsString('Kitchen accepted', $response->body);
        self::assertStringContainsString('The kitchen said <strong>20 minutes</strong>', $response->body);
        self::assertStringContainsString('is-done', $response->body, 'A step that happened is marked done.');
        self::assertStringContainsString('A driver is assigned once the food is nearly ready.', $response->body);
    }

    public function testTheDriversFirstNameAndVehicleAppearOnceOneIsAssignedAndNothingElseDoes(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $driverId = $this->createDriver('Dee Rowan Fitzgerald');
        \Keel\App\Models\Driver::update($driverId, [
            'vehicle_color' => 'Silver',
            'vehicle_make' => 'Honda',
            'vehicle_model' => 'Civic',
            'plate' => 'XYZ 123',
        ]);

        $orderId = $this->createChargedOrder($userId, $shop['restaurant_id']);
        Order::update($orderId, ['driver_id' => $driverId, 'driver_assigned_at' => gmdate('Y-m-d H:i:s')]);
        $this->forceStatus($orderId, Order::STATUS_DRIVER_ASSIGNED);

        $body = $this->get('/app/orders/' . $orderId)->body;

        self::assertStringContainsString('Dee is your driver', $body);
        self::assertStringContainsString('Silver Honda Civic', $body);
        self::assertStringNotContainsString('Fitzgerald', $body, 'A surname is not the customer\'s business.');
        self::assertStringNotContainsString('XYZ 123', $body, 'Neither is the plate.');
    }

    public function testDriverLocationReturnsNothingBeforeThePickup(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $driverId = $this->createDriver();
        $orderId = $this->createChargedOrder($userId, $shop['restaurant_id']);
        Order::update($orderId, ['driver_id' => $driverId]);
        $this->pingDriver($driverId, $orderId);

        foreach ([
            Order::STATUS_PLACED,
            Order::STATUS_ACCEPTED,
            Order::STATUS_READY,
            Order::STATUS_DRIVER_ASSIGNED,
            Order::STATUS_ARRIVED_AT_RESTAURANT,
        ] as $status) {
            $this->forceStatus($orderId, $status);

            $response = $this->getJson('/app/orders/' . $orderId . '/driver-location');

            self::assertSame(200, $response->status);
            self::assertFalse((bool) $response->json()['available'], "Nothing is readable at {$status}.");
            self::assertArrayNotHasKey('lat', $response->json());
        }
    }

    public function testDriverLocationIsReadableWhileTheFoodIsBeingCarriedAndNotAfter(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $driverId = $this->createDriver();
        $orderId = $this->createChargedOrder($userId, $shop['restaurant_id']);
        Order::update($orderId, ['driver_id' => $driverId]);
        $this->pingDriver($driverId, $orderId);

        foreach ([Order::STATUS_PICKED_UP, Order::STATUS_ARRIVED_AT_CUSTOMER] as $status) {
            $this->forceStatus($orderId, $status);

            $data = $this->getJson('/app/orders/' . $orderId . '/driver-location')->json();

            self::assertTrue((bool) $data['available'], "The position is readable at {$status}.");
            self::assertEqualsWithDelta(30.4400, (float) $data['lat'], 0.0001);
            self::assertSame(10, (int) $data['poll_seconds'], 'The spec asks for a ten second poll.');
        }

        $this->forceStatus($orderId, Order::STATUS_DELIVERED);

        self::assertFalse(
            (bool) $this->getJson('/app/orders/' . $orderId . '/driver-location')->json()['available'],
            'Once it is delivered, where the driver went is nobody else\'s business.'
        );
    }

    public function testDriverLocationIsForbiddenForSomebodyElsesOrder(): void
    {
        $shop = $this->createOrderableRestaurant();

        $stranger = $this->actingAsCustomer(['name' => 'Someone Else']);
        $driverId = $this->createDriver();
        $orderId = $this->createChargedOrder((int) $stranger['user']['id'], $shop['restaurant_id']);
        Order::update($orderId, ['driver_id' => $driverId]);
        $this->forceStatus($orderId, Order::STATUS_PICKED_UP);
        $this->pingDriver($driverId, $orderId);

        $this->actingAsCustomer(['name' => 'Marisol Vega']);

        $response = $this->getJson('/app/orders/' . $orderId . '/driver-location');

        self::assertSame(403, $response->status);
        self::assertSame('Forbidden.', $response->json()['error'] ?? null);
        self::assertArrayNotHasKey('lat', $response->json());
    }

    public function testACustomerCannotOpenAnotherCustomersOrderAtAll(): void
    {
        $shop = $this->createOrderableRestaurant();

        $stranger = $this->actingAsCustomer(['name' => 'Someone Else']);
        $orderId = $this->createChargedOrder((int) $stranger['user']['id'], $shop['restaurant_id']);

        $this->actingAsCustomer(['name' => 'Marisol Vega']);

        self::assertSame(404, $this->get('/app/orders/' . $orderId)->status);
        self::assertSame(404, $this->getJson('/app/orders/' . $orderId . '/status')->status);
        self::assertSame(
            404,
            $this->post('/app/orders/' . $orderId . '/reorder', ['_csrf' => $this->csrfToken()])->status
        );
    }

    /**
     * A receipt says "charged" only once something was charged.
     *
     * The two are written a moment apart — delivery prices the order, then the
     * capture takes it — and the gap is real: a capture that failed leaves a
     * delivered order with a final breakdown and nothing taken. Until
     * captured_cents exists the page shows the hold and says so, because
     * showing an authorization under the word "Charged" would be a lie about
     * somebody's card.
     */
    public function testADeliveredOrderShowsTheHoldUntilTheCaptureLands(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();

        $orderId = $this->createChargedOrder((int) $customer['user']['id'], $shop['restaurant_id']);
        Order::update($orderId, ['delivered_at' => gmdate('Y-m-d H:i:s')]);
        $this->forceStatus($orderId, Order::STATUS_DELIVERED);

        $body = $this->get('/app/orders/' . $orderId)->body;

        self::assertStringContainsString('Delivered', $body);
        self::assertStringContainsString('What is held', $body);
        self::assertStringContainsString('The final charge lands in a moment', $body);
        self::assertStringNotContainsString('Receipt', $body);

        // The delivery prices it, and the capture takes exactly that.
        OrderPriceBreakdown::create([
            'order_id' => $orderId,
            'stage' => OrderPriceBreakdown::STAGE_FINAL,
            'subtotal_cents' => 750,
            'tax_cents' => 56,
            'driver_guaranteed_cents' => 600,
            'wait_pay_cents' => 40,
            'tip_cents' => 135,
            'platform_fee_cents' => 199,
            'service_fee_cents' => 84,
            'total_cents' => 1864,
            'settings_snapshot' => (string) json_encode(['processing_pct' => '0.029', 'processing_fixed_cents' => 30]),
        ]);
        Order::update($orderId, ['captured_cents' => 1864]);

        $settled = $this->get('/app/orders/' . $orderId)->body;

        self::assertStringContainsString('Receipt', $settled);
        self::assertStringContainsString('$18.64', $settled);
        self::assertStringNotContainsString('The final charge lands in a moment', $settled);
    }

    public function testOrderHistoryListsOnlyThisCustomersOrders(): void
    {
        $shop = $this->createOrderableRestaurant('Taqueria Uno');
        $other = $this->createRestaurantWithOwner('Noodle Bar', $shop['zone_id'], ['hours' => $this->alwaysOpenHours()]);

        $stranger = $this->actingAsCustomer(['name' => 'Someone Else']);
        $this->createChargedOrder((int) $stranger['user']['id'], $other['restaurant_id']);

        $customer = $this->actingAsCustomer(['name' => 'Marisol Vega']);
        $this->createChargedOrder((int) $customer['user']['id'], $shop['restaurant_id']);

        $body = $this->get('/app/orders')->body;

        self::assertStringContainsString('Taqueria Uno', $body);
        self::assertStringNotContainsString('Noodle Bar', $body);
        self::assertStringContainsString('$18.23', $body);
        self::assertStringContainsString('held', $body, 'An authorization is not a receipt and does not read as one.');
    }

    public function testReorderRebuildsTheCartFromAPastOrder(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];
        $this->createAddress($userId);

        $orderId = $this->placedOrderFrom($userId, $shop);

        (new CartService())->clear($userId);

        $response = $this->post('/app/orders/' . $orderId . '/reorder', ['_csrf' => $this->csrfToken()]);

        self::assertSame(302, $response->status);
        self::assertSame('/app/cart', $response->header('Location'));
        self::assertSame(2, (new CartService())->count($userId));
        self::assertSame($shop['restaurant_id'], (int) Cart::forUser($userId)['restaurant_id']);
    }

    public function testReorderWarnsWhenSomethingOnTheMenuHasMoved(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];
        $this->createAddress($userId);

        $orderId = $this->placedOrderFrom($userId, $shop);

        MenuItem::update($shop['item_id'], ['price_cents' => 425]);
        (new CartService())->clear($userId);

        $this->post('/app/orders/' . $orderId . '/reorder', ['_csrf' => $this->csrfToken()]);

        $body = $this->get('/app/cart')->body;

        self::assertStringContainsString('Some things changed', $body);
        self::assertStringContainsString('has changed price since your last order', $body);
        self::assertSame(2, (new CartService())->count($userId), 'A repriced item still goes back in the cart.');
    }

    public function testReorderRefusesWhenNothingFromTheOrderCanBeBoughtAnyMore(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];
        $this->createAddress($userId);

        $orderId = $this->placedOrderFrom($userId, $shop);

        MenuItem::update($shop['item_id'], ['active' => 0]);
        (new CartService())->clear($userId);

        $response = $this->post('/app/orders/' . $orderId . '/reorder', ['_csrf' => $this->csrfToken()]);

        self::assertSame(302, $response->status);
        self::assertSame('/app/orders/' . $orderId, $response->header('Location'));
        self::assertSame(0, (new CartService())->count($userId));

        $body = $this->get('/app/orders/' . $orderId)->body;

        self::assertStringContainsString('Nothing from that order can be ordered right now.', $body);
    }

    /**
     * An order carrying real line items, built the way checkout builds one.
     *
     * @param array{restaurant_id: int, item_id: int} $shop
     */
    private function placedOrderFrom(int $userId, array $shop): int
    {
        $cart = new CartService();
        $cart->add($userId, $shop['item_id'], 2, []);

        $orderId = $this->createChargedOrder($userId, $shop['restaurant_id']);

        \Keel\App\Models\OrderItem::create([
            'order_id' => $orderId,
            'menu_item_id' => $shop['item_id'],
            'name_snapshot' => 'Al Pastor Taco',
            'unit_price_cents' => 375,
            'quantity' => 2,
            'line_total_cents' => 750,
        ]);

        return $orderId;
    }

    private function pingDriver(int $driverId, int $orderId): void
    {
        DriverLocation::create([
            'driver_id' => $driverId,
            'order_id' => $orderId,
            'lat' => '30.4400000',
            'lng' => '-84.2810000',
            'recorded_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
