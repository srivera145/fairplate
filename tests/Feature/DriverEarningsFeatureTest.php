<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\Dispatch\DispatchService;
use Keel\App\Services\OrderLifecycle;
use Keel\App\Services\Pricing\Money;
use Tests\Support\DriverFixtures;
use Tests\TestCase;

/**
 * What the day and the week paid.
 *
 * The screen a driver checks against the other app, so the tests are about the
 * numbers agreeing with the frozen breakdowns rather than about the page
 * rendering. A total that is right but unexplainable is not useful here: the
 * per-line figures are checked too, because that is what makes the total
 * checkable by the person earning it.
 */
class DriverEarningsFeatureTest extends TestCase
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

    public function testAnEmptyWeekSaysSoRatherThanShowingNothing(): void
    {
        $this->actingAsDriver($this->driverId);

        $response = $this->get('/drive/earnings');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Nothing yet this week', $response->body);
        self::assertStringContainsString(Money::usd(0), $response->body);
    }

    /**
     * Guarantee plus wait plus tip, per delivery and in the totals.
     */
    public function testTodayAddsUpTheGuaranteeTheWaitAndTheTip(): void
    {
        // Fourteen minutes of waiting, so there is a wait line worth adding up.
        $first = $this->deliver(14);
        $second = $this->deliver(0);

        $this->actingAsDriver($this->driverId);
        $body = $this->get('/drive/earnings')->body;

        $expected = $this->payFor($first) + $this->payFor($second);

        self::assertSame(880, $this->payFor($first), '600 guaranteed + 80 wait + 200 tip');
        self::assertSame(800, $this->payFor($second), '600 guaranteed + 200 tip');
        self::assertStringContainsString(Money::usd($expected), $body);
        self::assertStringContainsString('2 deliveries', $body);
    }

    /**
     * Each run is broken out far enough to be checked against the offer that
     * was accepted.
     */
    public function testEachDeliveryShowsItsOwnLines(): void
    {
        $orderId = $this->deliver(14);

        $this->actingAsDriver($this->driverId);
        $body = $this->get('/drive/earnings')->body;

        $row = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);

        self::assertStringContainsString('Taqueria Uno', $body);
        self::assertStringContainsString('Base', $body);
        self::assertStringContainsString('Miles', $body);
        self::assertStringContainsString('Wait', $body);
        self::assertStringContainsString('Tip', $body);
        self::assertStringContainsString(Money::usd((int) $row['driver_base_cents']), $body);
        self::assertStringContainsString(Money::usd((int) $row['wait_pay_cents']), $body);
    }

    /**
     * The minimum payout is named rather than left as an unexplained gap
     * between base plus miles and the guarantee.
     */
    public function testAShortRunNamesTheMinimumPayout(): void
    {
        // Half a mile: 300 base + 50 mileage is under the 500 minimum.
        $orderId = $this->deliver(0, 0.5);

        $row = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);

        self::assertSame(300, (int) $row['driver_base_cents']);
        self::assertSame(50, (int) $row['driver_mileage_cents']);
        self::assertSame(500, (int) $row['driver_guaranteed_cents'], 'the minimum payout carries it');

        $this->actingAsDriver($this->driverId);
        $body = $this->get('/drive/earnings')->body;

        self::assertStringContainsString('Minimum payout', $body);
        self::assertStringContainsString('+' . Money::usd(150), $body);
    }

    /**
     * A delivery from last month is not this week's earnings.
     */
    public function testOnlyThisWeeksDeliveriesAreCounted(): void
    {
        $recent = $this->deliver(0);
        $old = $this->deliver(0);

        $this->backdate($old, 'delivered_at', 40 * 86400);

        $this->actingAsDriver($this->driverId);
        $body = $this->get('/drive/earnings')->body;

        self::assertStringContainsString(Money::usd($this->payFor($recent)), $body);
        self::assertStringContainsString('1 delivery', $body);
    }

    /**
     * And another driver's work is not this driver's earnings.
     */
    public function testOneDriversEarningsAreNotAnothers(): void
    {
        $this->deliver(0);

        $other = $this->createDispatchableDriver('Somebody Else');
        $this->actingAsDriver($other);

        $body = $this->get('/drive/earnings')->body;

        self::assertStringContainsString('Nothing yet this week', $body);
        self::assertStringContainsString('0 deliveries', $body);
    }

    /**
     * Today's total also leads the home screen, and the two agree.
     */
    public function testTodaysTotalIsOnTheHomeScreenToo(): void
    {
        $orderId = $this->deliver(14);

        $this->actingAsDriver($this->driverId);
        $body = $this->get('/drive')->body;

        self::assertStringContainsString('Today', $body);
        self::assertStringContainsString(Money::usd($this->payFor($orderId)), $body);
    }

    /**
     * A whole run, start to finish, ending delivered.
     */
    private function deliver(int $waitedMinutes, float $miles = 3.0): int
    {
        $order = $this->createPricedOrder($this->restaurantId, $miles, 200);
        $orderId = $order['order_id'];

        $this->acceptOrder($orderId);
        $this->drainQueue();

        $offer = $this->pendingOffer($orderId);
        $driverId = (int) $offer['driver_id'];
        (new DispatchService())->accept((int) $offer['id'], $driverId);

        OrderLifecycle::arriveAtRestaurant($orderId);

        if ($waitedMinutes > 0) {
            $this->backdate($orderId, 'arrived_at_restaurant_at', $waitedMinutes * 60);
        }

        OrderLifecycle::pickUp($orderId);
        OrderLifecycle::arriveAtCustomer($orderId);
        OrderLifecycle::deliver($orderId);

        self::assertSame(Order::STATUS_DELIVERED, $this->orderStatus($orderId));

        return $orderId;
    }

    /**
     * What one delivery paid, from its frozen final breakdown.
     */
    private function payFor(int $orderId): int
    {
        $row = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);

        return (int) $row['driver_guaranteed_cents']
            + (int) $row['wait_pay_cents']
            + (int) $row['tip_cents'];
    }
}
