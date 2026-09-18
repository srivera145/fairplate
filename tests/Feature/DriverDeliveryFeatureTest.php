<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Driver;
use Keel\App\Models\DriverLocation;
use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\Dispatch\DispatchService;
use Keel\App\Services\OrderLifecycle;
use Keel\App\Services\Pricing\Breakdown;
use Tests\Support\DriverFixtures;
use Tests\TestCase;

/**
 * The run, and the money at the end of it.
 *
 * Two halves. The first is the four taps and what each one is allowed to show:
 * the customer's address and phone do not exist on screen before pickup, which
 * the spec requires and which is checked here against the rendered page rather
 * than against a flag.
 *
 * The second is the settlement. Delivering recomputes the wait pay from the
 * order's own frozen snapshot, writes the final breakdown and puts the driver
 * back in the pool — and the final total can never exceed what the card was
 * authorized for, which is the invariant the whole pricing design exists to
 * keep.
 */
class DriverDeliveryFeatureTest extends TestCase
{
    use DriverFixtures;

    private int $restaurantId;
    private int $driverId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDispatchSettings();
        $zoneId = $this->createZone();
        $this->restaurantId = $this->createRestaurantWithOwner('Taqueria Uno', $zoneId)['restaurant_id'];
        $this->driverId = $this->createDispatchableDriver();
    }

    protected function tearDown(): void
    {
        $this->restoreCollaborators();

        parent::tearDown();
    }

    /**
     * Four taps, in order, each one moving the order exactly one step.
     */
    public function testTheFourStepsMoveTheOrderThroughTheRun(): void
    {
        $orderId = $this->assignedOrder();
        $this->actingAsDriver($this->driverId);

        $this->step($orderId, 'arrived-restaurant');
        self::assertSame(Order::STATUS_ARRIVED_AT_RESTAURANT, $this->orderStatus($orderId));

        $this->step($orderId, 'picked-up');
        self::assertSame(Order::STATUS_PICKED_UP, $this->orderStatus($orderId));

        $this->step($orderId, 'arrived-customer');
        self::assertSame(Order::STATUS_ARRIVED_AT_CUSTOMER, $this->orderStatus($orderId));

        $this->step($orderId, 'delivered');
        self::assertSame(Order::STATUS_DELIVERED, $this->orderStatus($orderId));
    }

    /**
     * Every step offers a way to navigate, and it points at the right end of
     * the run.
     */
    public function testEachStepOffersNavigation(): void
    {
        $orderId = $this->assignedOrder();
        $this->actingAsDriver($this->driverId);

        $body = $this->get('/drive/orders/' . $orderId)->body;

        self::assertStringContainsString('google.com/maps/dir/', $body);
        self::assertStringContainsString('Navigate', $body);
        // Before pickup, navigation is to the restaurant's coordinates.
        self::assertStringContainsString('destination=30.4383', $body);

        $this->step($orderId, 'arrived-restaurant');
        $this->step($orderId, 'picked-up');

        $body = $this->get('/drive/orders/' . $orderId)->body;

        self::assertStringContainsString('google.com/maps/dir/', $body);
    }

    /**
     * The spec's disclosure rule, checked against what is actually rendered.
     */
    public function testTheCustomerIsNotOnScreenBeforePickup(): void
    {
        $orderId = $this->assignedOrder();
        $this->actingAsDriver($this->driverId);

        $before = $this->get('/drive/orders/' . $orderId)->body;

        self::assertStringNotContainsString('9 Oak St', $before, 'no address before pickup');
        self::assertStringNotContainsString('Call the customer', $before);
        self::assertStringContainsString('Taqueria Uno', $before);

        $this->step($orderId, 'arrived-restaurant');
        $this->step($orderId, 'picked-up');

        $after = $this->get('/drive/orders/' . $orderId)->body;

        self::assertStringContainsString('9 Oak St', $after, 'the address appears at the drop-off step');
        self::assertStringContainsString('Call the customer', $after);
    }

    /**
     * The spec's worked example: fourteen minutes between arriving and picking
     * up, with ten free at twenty cents a minute, is eighty cents.
     */
    public function testAFourteenMinuteWaitPaysEightyCents(): void
    {
        $orderId = $this->assignedOrder();
        $this->actingAsDriver($this->driverId);

        $this->step($orderId, 'arrived-restaurant');
        $this->backdate($orderId, 'arrived_at_restaurant_at', 14 * 60);
        $this->step($orderId, 'picked-up');

        self::assertSame(14, OrderLifecycle::waitMinutes(Order::find($orderId)));

        $this->step($orderId, 'arrived-customer');
        $this->step($orderId, 'delivered');

        $final = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);

        self::assertNotNull($final, 'delivering writes the final breakdown');
        self::assertSame(80, (int) $final['wait_pay_cents']);
    }

    /**
     * A wait inside the free window costs nothing.
     */
    public function testAWaitInsideTheFreeWindowPaysNothing(): void
    {
        $orderId = $this->assignedOrder();
        $this->actingAsDriver($this->driverId);

        $this->step($orderId, 'arrived-restaurant');
        $this->backdate($orderId, 'arrived_at_restaurant_at', 9 * 60);
        $this->step($orderId, 'picked-up');
        $this->step($orderId, 'arrived-customer');
        $this->step($orderId, 'delivered');

        $final = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);

        self::assertSame(0, (int) $final['wait_pay_cents']);
    }

    /**
     * A very long wait stops at the cap, which is also what the card was
     * authorized for.
     */
    public function testAVeryLongWaitStopsAtTheCap(): void
    {
        $orderId = $this->assignedOrder();
        $this->actingAsDriver($this->driverId);

        $this->step($orderId, 'arrived-restaurant');
        $this->backdate($orderId, 'arrived_at_restaurant_at', 90 * 60);
        $this->step($orderId, 'picked-up');
        $this->step($orderId, 'arrived-customer');
        $this->step($orderId, 'delivered');

        $final = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);
        $order = Order::find($orderId);

        self::assertSame(300, (int) $final['wait_pay_cents'], 'the seeded cap');
        self::assertSame(
            (int) $order['authorized_cents'],
            (int) $final['total_cents'],
            'the worst case is exactly what was authorized'
        );
    }

    /**
     * The invariant the whole pricing design exists to keep: the final
     * breakdown balances, and never exceeds the authorization.
     */
    public function testTheFinalBreakdownBalancesAndFitsInsideTheAuthorization(): void
    {
        foreach ([0, 4, 14, 45] as $waitedMinutes) {
            $orderId = $this->assignedOrder();
            $this->actingAsDriver($this->driverId);

            $this->step($orderId, 'arrived-restaurant');

            if ($waitedMinutes > 0) {
                $this->backdate($orderId, 'arrived_at_restaurant_at', $waitedMinutes * 60);
            }

            $this->step($orderId, 'picked-up');
            $this->step($orderId, 'arrived-customer');
            $this->step($orderId, 'delivered');

            $order = Order::find($orderId);
            $row = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);
            $final = Breakdown::fromRow($row);

            $final->assertBalanced();

            self::assertLessThanOrEqual(
                (int) $order['authorized_cents'],
                $final->total(),
                "a {$waitedMinutes} minute wait must still fit inside the authorization"
            );

            // And the driver's half of it never moved from what they accepted.
            $authorized = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_AUTHORIZED);

            self::assertSame(
                (int) $authorized['driver_guaranteed_cents'],
                $final->line(Breakdown::DRIVER_PAY),
                'the guarantee must not move after acceptance'
            );
        }
    }

    /**
     * Delivering puts the driver back in the pool.
     */
    public function testDeliveringMakesTheDriverIdleAgain(): void
    {
        $orderId = $this->assignedOrder();
        $this->actingAsDriver($this->driverId);

        self::assertSame(0, (int) Driver::find($this->driverId)['idle']);

        $this->step($orderId, 'arrived-restaurant');
        $this->step($orderId, 'picked-up');
        $this->step($orderId, 'arrived-customer');
        $this->step($orderId, 'delivered');

        self::assertSame(1, (int) Driver::find($this->driverId)['idle']);
    }

    /**
     * A leave-at-door drop needs a photograph, and saying so is not optional.
     */
    public function testALeaveAtDoorDropRefusesToFinishWithoutAPhoto(): void
    {
        $orderId = $this->assignedOrder(['instructions' => 'Please leave it at the front door.']);
        $this->actingAsDriver($this->driverId);

        self::assertTrue(Order::requiresDeliveryPhoto(Order::find($orderId)));

        $this->step($orderId, 'arrived-restaurant');
        $this->step($orderId, 'picked-up');
        $this->step($orderId, 'arrived-customer');

        $body = $this->get('/drive/orders/' . $orderId)->body;
        self::assertStringContainsString('Photo of the drop-off (required)', $body);

        $this->step($orderId, 'delivered');

        self::assertSame(
            Order::STATUS_ARRIVED_AT_CUSTOMER,
            $this->orderStatus($orderId),
            'a leave-at-door drop must not complete without a photo'
        );
    }

    /**
     * Everywhere else the photo is offered and not demanded.
     */
    public function testAnOrdinaryDropCanFinishWithoutAPhoto(): void
    {
        $orderId = $this->assignedOrder(['instructions' => 'Ring the bell.']);
        $this->actingAsDriver($this->driverId);

        self::assertFalse(Order::requiresDeliveryPhoto(Order::find($orderId)));

        $this->step($orderId, 'arrived-restaurant');
        $this->step($orderId, 'picked-up');
        $this->step($orderId, 'arrived-customer');

        $body = $this->get('/drive/orders/' . $orderId)->body;
        self::assertStringContainsString('Photo of the drop-off (optional)', $body);

        $this->step($orderId, 'delivered');

        self::assertSame(Order::STATUS_DELIVERED, $this->orderStatus($orderId));
    }

    /**
     * A photo that was taken is kept on the order.
     *
     * Delivered through OrderLifecycle rather than over HTTP, because the HTTP
     * path's first act is is_uploaded_file(), which by design cannot be true for
     * a file a test wrote — the same reason the kitchen's photo tests go through
     * ImageService directly. What this covers is the half either way round: the
     * column is written, survives the transition, and reaches the screen.
     */
    public function testAPhotoTakenAtTheDropIsKeptOnTheOrder(): void
    {
        $orderId = $this->assignedOrder(['instructions' => 'Leave at door']);
        $this->actingAsDriver($this->driverId);

        $this->step($orderId, 'arrived-restaurant');
        $this->step($orderId, 'picked-up');
        $this->step($orderId, 'arrived-customer');

        $path = '/uploads/deliveries/' . $orderId . '/' . str_repeat('a', 32) . '.webp';

        OrderLifecycle::deliver($orderId, ['delivery_photo' => $path]);

        $order = Order::find($orderId);

        self::assertSame(Order::STATUS_DELIVERED, (string) $order['status']);
        self::assertSame($path, (string) $order['delivery_photo']);
    }

    /**
     * The phrasings customers actually write.
     */
    public function testLeaveAtDoorIsRecognisedHoweverItIsPhrased(): void
    {
        $requires = [
            'Leave at door',
            'leave it at the door',
            'Please just leave by the front door, thanks',
            'LEAVE AT THE SIDE DOOR',
        ];

        foreach ($requires as $instruction) {
            self::assertTrue(
                Order::requiresDeliveryPhoto(['address_snapshot' => json_encode(['instructions' => $instruction])]),
                "\"{$instruction}\" should require a photo"
            );
        }

        $doesNot = ['Ring the bell', 'Call on arrival', '', 'Apartment 4, buzzer is broken'];

        foreach ($doesNot as $instruction) {
            self::assertFalse(
                Order::requiresDeliveryPhoto(['address_snapshot' => json_encode(['instructions' => $instruction])]),
                "\"{$instruction}\" should not require a photo"
            );
        }
    }

    /**
     * Position is recorded while online, tied to the order while carrying one,
     * and refused outright once offline.
     */
    public function testPositionIsRecordedOnlyWhileOnline(): void
    {
        $orderId = $this->assignedOrder();
        $this->actingAsDriver($this->driverId);

        $response = $this->postJson('/drive/location', [
            '_csrf' => $this->csrfToken(),
            'lat' => 30.4400,
            'lng' => -84.2800,
        ]);

        $data = $response->json();

        self::assertTrue($data['stored']);
        self::assertSame($orderId, $data['order_id'], 'a ping mid-run belongs to the order');
        self::assertSame(10, $data['next_ping_seconds'], 'the active interval, from settings');

        $trail = DriverLocation::forOrder($orderId);
        self::assertCount(1, $trail);
        self::assertSame($this->driverId, (int) $trail[0]['driver_id']);

        $driver = Driver::find($this->driverId);
        self::assertSame('30.4400000', (string) $driver['last_lat']);

        Driver::update($this->driverId, ['online' => 0]);

        $offline = $this->postJson('/drive/location', [
            '_csrf' => $this->csrfToken(),
            'lat' => 30.4500,
            'lng' => -84.2900,
        ])->json();

        self::assertFalse($offline['stored']);
        self::assertCount(1, DriverLocation::forOrder($orderId), 'nothing is recorded once offline');
    }

    /**
     * A ping from an idle driver is kept, but belongs to no order — which is
     * what keeps a customer's view of a driver scoped to their own delivery.
     */
    public function testAnIdlePingBelongsToNoOrder(): void
    {
        $this->actingAsDriver($this->driverId);

        $data = $this->postJson('/drive/location', [
            '_csrf' => $this->csrfToken(),
            'lat' => 30.4400,
            'lng' => -84.2800,
        ])->json();

        self::assertTrue($data['stored']);
        self::assertNull($data['order_id']);
        self::assertSame(15, $data['next_ping_seconds'], 'the online interval, from settings');
        self::assertNull(DriverLocation::latestForDriver($this->driverId)['order_id']);
    }

    public function testABrokenPositionIsRefused(): void
    {
        $this->actingAsDriver($this->driverId);

        foreach ([['lat' => 0, 'lng' => 0], ['lat' => 'north', 'lng' => -84.2], ['lat' => 200, 'lng' => 1]] as $body) {
            $response = $this->postJson('/drive/location', $body + ['_csrf' => $this->csrfToken()]);

            self::assertSame(422, $response->status);
        }

        self::assertNull(DriverLocation::latestForDriver($this->driverId));
    }

    /**
     * The spec's access rule for an order, from the other driver's side.
     */
    public function testADriverCannotTouchAnotherDriversOrder(): void
    {
        $orderId = $this->assignedOrder();
        $intruder = $this->createDispatchableDriver('Intruder');

        $this->actingAsDriver($intruder);

        self::assertSame(403, $this->get('/drive/orders/' . $orderId)->status);

        foreach (['arrived-restaurant', 'picked-up', 'arrived-customer', 'delivered'] as $action) {
            self::assertSame(
                403,
                $this->post('/drive/orders/' . $orderId . '/' . $action, ['_csrf' => $this->csrfToken()])->status,
                $action . ' should be refused'
            );
        }

        self::assertSame(
            Order::STATUS_DRIVER_ASSIGNED,
            $this->orderStatus($orderId),
            'and the order has not moved'
        );
    }

    /**
     * An order dispatched and accepted, with $this->driverId set to whoever it
     * actually reached.
     *
     * Reading the driver back off the offer rather than assuming it is the one
     * this test created keeps the fixture honest: dispatch picks by distance,
     * and a test that told it who to pick would be testing itself.
     */
    private function assignedOrder(array $overrides = []): int
    {
        $order = $this->createPricedOrder($this->restaurantId, 3.0, 200, $overrides);

        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offer = $this->pendingOffer($order['order_id']);

        self::assertNotNull($offer, 'the fixture order should have been offered to somebody');

        $this->driverId = (int) $offer['driver_id'];
        (new DispatchService())->accept((int) $offer['id'], $this->driverId);

        return $order['order_id'];
    }

    private function step(int $orderId, string $action): void
    {
        $this->post('/drive/orders/' . $orderId . '/' . $action, ['_csrf' => $this->csrfToken()]);
    }
}
