<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Order;
use Keel\App\Services\OrderLifecycle;
use Keel\App\Services\OrderLifecycleException;
use Tests\Support\KitchenFixtures;
use Tests\TestCase;

/**
 * The guard table, exercised in both directions.
 *
 * The important half is the refusals. Every app in FairPlate writes order
 * statuses through this one class, and the reason that is worth the indirection
 * is precisely that placed cannot become picked_up however confidently
 * something asks.
 */
class OrderLifecycleFeatureTest extends TestCase
{
    use KitchenFixtures;

    private int $restaurantId;

    protected function setUp(): void
    {
        parent::setUp();

        $zoneId = $this->createZone();
        $this->restaurantId = $this->createRestaurantWithOwner('Guard Table Grill', $zoneId)['restaurant_id'];
    }

    public function testAnOrderIsPlacedToBeginWith(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        self::assertSame(Order::STATUS_PLACED, $this->orderStatus($orderId));
    }

    /**
     * The example from the spec.
     */
    public function testPlacedCannotBecomePickedUp(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $this->expectException(OrderLifecycleException::class);
        $this->expectExceptionMessage('cannot move from "placed" to "picked_up"');

        OrderLifecycle::transition($orderId, Order::STATUS_PICKED_UP);
    }

    /**
     * Every pair the table forbids, tried. Sixty-odd refusals matter more than
     * the dozen that are allowed, because a missing entry in the allowed list
     * shows up as a broken button and a missing refusal shows up as a driver
     * collecting food nobody cooked.
     */
    public function testEveryForbiddenPairIsRefused(): void
    {
        $statuses = array_keys(OrderLifecycle::TRANSITIONS);
        $refused = 0;

        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                if (OrderLifecycle::allows($from, $to)) {
                    continue;
                }

                $orderId = $this->createPlacedOrder($this->restaurantId);
                $this->forceStatus($orderId, $from);

                try {
                    OrderLifecycle::transition($orderId, $to);
                    self::fail("{$from} was allowed to become {$to}");
                } catch (OrderLifecycleException $exception) {
                    self::assertStringContainsString($from, $exception->getMessage());
                    $refused++;
                }
            }
        }

        $allowed = array_sum(array_map('count', OrderLifecycle::TRANSITIONS));

        self::assertSame(11, count($statuses), 'the spec lists eleven statuses');
        self::assertSame(26, $allowed, 'the table allows twenty-six moves');
        self::assertSame((11 * 11) - $allowed, $refused);
    }

    public function testTheTerminalStatusesGoNowhere(): void
    {
        foreach (Order::TERMINAL_STATUSES as $terminal) {
            self::assertSame([], OrderLifecycle::TRANSITIONS[$terminal], "{$terminal} should be terminal");
        }
    }

    public function testReadyAndDriverAssignedMayHappenInEitherOrder(): void
    {
        self::assertTrue(OrderLifecycle::allows(Order::STATUS_ACCEPTED, Order::STATUS_READY));
        self::assertTrue(OrderLifecycle::allows(Order::STATUS_ACCEPTED, Order::STATUS_DRIVER_ASSIGNED));
        self::assertTrue(OrderLifecycle::allows(Order::STATUS_READY, Order::STATUS_DRIVER_ASSIGNED));
        self::assertTrue(OrderLifecycle::allows(Order::STATUS_DRIVER_ASSIGNED, Order::STATUS_READY));
        self::assertTrue(OrderLifecycle::allows(Order::STATUS_READY, Order::STATUS_ARRIVED_AT_RESTAURANT));
        self::assertTrue(OrderLifecycle::allows(Order::STATUS_DRIVER_ASSIGNED, Order::STATUS_ARRIVED_AT_RESTAURANT));
    }

    public function testTheWholeHappyPathWalks(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        OrderLifecycle::accept($orderId, 15);
        OrderLifecycle::markReady($orderId);

        foreach ([
            Order::STATUS_DRIVER_ASSIGNED,
            Order::STATUS_ARRIVED_AT_RESTAURANT,
            Order::STATUS_PICKED_UP,
            Order::STATUS_ARRIVED_AT_CUSTOMER,
            Order::STATUS_DELIVERED,
        ] as $status) {
            OrderLifecycle::transition($orderId, $status);
        }

        self::assertSame(Order::STATUS_DELIVERED, $this->orderStatus($orderId));

        // Every stop stamped its own timestamp on the way through.
        $order = Order::find($orderId);

        foreach (['accepted_at', 'ready_at', 'driver_assigned_at', 'picked_up_at', 'delivered_at'] as $column) {
            self::assertNotNull($order[$column], "{$column} should have been stamped");
        }
    }

    public function testAcceptRecordsThePrepTime(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $order = OrderLifecycle::accept($orderId, 30);

        self::assertSame(Order::STATUS_ACCEPTED, (string) $order['status']);
        self::assertSame(30, (int) $order['prep_minutes']);
    }

    public function testOnlyTheOfferedPrepTimesAreAccepted(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $this->expectException(OrderLifecycleException::class);
        $this->expectExceptionMessage('not an offered prep time');

        OrderLifecycle::accept($orderId, 12);
    }

    public function testRejectRecordsTheReason(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $order = OrderLifecycle::reject($orderId, 'too_busy');

        self::assertSame(Order::STATUS_REJECTED, (string) $order['status']);
        self::assertSame('Too busy', (string) $order['reject_reason']);
        self::assertNotNull($order['rejected_at']);
    }

    public function testRejectKeepsTheNoteWithTheReason(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $order = OrderLifecycle::reject($orderId, 'other', 'Power cut on the block');

        self::assertSame('Other: Power cut on the block', (string) $order['reject_reason']);
    }

    public function testAnInventedReasonIsRefused(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $this->expectException(OrderLifecycleException::class);

        OrderLifecycle::reject($orderId, 'chef_was_rude');
    }

    /**
     * Two tablets, one order. The second tap must not be able to undo the first.
     */
    public function testTheSecondTabletCannotOverwriteTheFirst(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        OrderLifecycle::accept($orderId, 10);

        try {
            OrderLifecycle::reject($orderId, 'too_busy');
            self::fail('an accepted order should not be rejectable');
        } catch (OrderLifecycleException $exception) {
            self::assertSame(Order::STATUS_ACCEPTED, $this->orderStatus($orderId));
            self::assertSame(10, (int) Order::find($orderId)['prep_minutes']);
        }
    }

    public function testAnUnknownStatusIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(OrderLifecycleException::class);
        $this->expectExceptionMessage('is not an order status');

        OrderLifecycle::allows(Order::STATUS_PLACED, 'burnt');
    }

    public function testAMissingOrderIsRefused(): void
    {
        $this->expectException(OrderLifecycleException::class);
        $this->expectExceptionMessage('does not exist');

        OrderLifecycle::transition(999999, Order::STATUS_ACCEPTED);
    }

    /**
     * transition() builds its own UPDATE, so the column names it will accept had
     * better be the model's and not the caller's.
     */
    public function testATransitionCannotWriteAColumnThatIsNotOnOrders(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $this->expectException(OrderLifecycleException::class);
        $this->expectExceptionMessage('is not a column on orders');

        OrderLifecycle::transition($orderId, Order::STATUS_ACCEPTED, ['id` = 1, `status' => 'x']);
    }

    public function testATransitionCannotSmuggleAStatusThroughTheAttributes(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        OrderLifecycle::transition($orderId, Order::STATUS_ACCEPTED, ['status' => Order::STATUS_DELIVERED]);

        self::assertSame(Order::STATUS_ACCEPTED, $this->orderStatus($orderId));
    }
}
