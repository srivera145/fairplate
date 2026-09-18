<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Jobs\DispatchOfferJob;
use Keel\App\Jobs\OfferTimeoutJob;
use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Services\Dispatch\DispatchException;
use Keel\App\Services\Dispatch\DispatchService;
use Keel\App\Services\Settings;
use Tests\Support\DriverFixtures;
use Tests\TestCase;

/**
 * The dispatch loop, exercised through every exit it has.
 *
 * The interesting half is the refusals. Offering the nearest driver is the easy
 * part and it is one query; what this engine is actually for is being right
 * when two drivers tap Accept in the same second, when a worker misses a
 * timeout, and when nobody says yes at all.
 */
class DispatchServiceFeatureTest extends TestCase
{
    use DriverFixtures;

    private int $restaurantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDispatchSettings();
        $zoneId = $this->createZone();
        $this->restaurantId = $this->createRestaurantWithOwner('Taqueria Uno', $zoneId)['restaurant_id'];
    }

    protected function tearDown(): void
    {
        $this->restoreCollaborators();

        parent::tearDown();
    }

    /**
     * The spec's first rule: nearest first.
     *
     * Both drivers are eligible, one is four streets away and the other is
     * across town, and the order goes to the near one. Declining sends it
     * straight to the other, which is the whole of the round loop in two
     * assertions.
     */
    public function testTheNearestDriverIsOfferedFirstAndADeclineGoesToTheOther(): void
    {
        $near = $this->createDispatchableDriver('Near Driver', 30.4390, -84.2810);
        $far = $this->createDispatchableDriver('Far Driver', 30.5200, -84.4000);

        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $first = $this->pendingOffer($order['order_id']);

        self::assertNotNull($first, 'an accepted order should be offered to somebody');
        self::assertSame($near, (int) $first['driver_id'], 'the nearest driver should be offered first');
        self::assertSame(1, (int) $first['round']);

        (new DispatchService())->decline((int) $first['id'], $near);

        $second = $this->pendingOffer($order['order_id']);

        self::assertNotNull($second, 'a decline should reach the next driver');
        self::assertSame($far, (int) $second['driver_id']);
        self::assertSame(2, (int) $second['round']);
    }

    /**
     * A driver who declined is not asked again about the same order, so a
     * two-driver town runs out of drivers rather than ping-ponging.
     */
    public function testADriverWhoDeclinedIsNotOfferedTheSameOrderAgain(): void
    {
        $only = $this->createDispatchableDriver('Only Driver');
        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offer = $this->pendingOffer($order['order_id']);
        (new DispatchService())->decline((int) $offer['id'], $only);

        self::assertNull($this->pendingOffer($order['order_id']));
        self::assertSame(
            [$only],
            array_map(
                static fn (array $row): int => (int) $row['driver_id'],
                DispatchOffer::forOrder($order['order_id'])
            )
        );
    }

    /**
     * The clock ends the round even when nobody touches anything.
     *
     * The offer is aged past its forty-five seconds rather than slept through —
     * the state the worker would find is the same either way — and the timeout
     * both closes it and starts the next round.
     */
    public function testATimedOutOfferExpiresAndAdvancesToTheNextDriver(): void
    {
        $first = $this->createDispatchableDriver('First', 30.4390, -84.2810);
        $second = $this->createDispatchableDriver('Second', 30.4600, -84.3200);

        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offer = $this->pendingOffer($order['order_id']);
        self::assertSame($first, (int) $offer['driver_id']);

        // Exactly the spec's window, plus a second, so this is a timeout and
        // not a rounding accident.
        self::assertSame(45, Settings::int('offer_timeout_seconds'));
        $this->ageOffer((int) $offer['id'], 46);

        (new DispatchService())->timeout((int) $offer['id']);

        self::assertSame(
            DispatchOffer::RESPONSE_EXPIRED,
            (string) DispatchOffer::find((int) $offer['id'])['response']
        );

        $next = $this->pendingOffer($order['order_id']);

        self::assertNotNull($next, 'a timeout should advance to the next driver');
        self::assertSame($second, (int) $next['driver_id']);
        self::assertSame(2, (int) $next['round']);
    }

    /**
     * A timeout job that fires early puts itself back rather than cutting a
     * driver's decision short.
     */
    public function testATimeoutJobThatFiresEarlyDoesNotCloseTheOffer(): void
    {
        $driverId = $this->createDispatchableDriver();
        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offer = $this->pendingOffer($order['order_id']);

        (new DispatchService())->timeout((int) $offer['id']);

        self::assertSame(
            DispatchOffer::RESPONSE_PENDING,
            (string) DispatchOffer::find((int) $offer['id'])['response'],
            'an offer with time left must survive an early timeout job'
        );
        self::assertNotSame([], $this->queuedJobs(OfferTimeoutJob::class));
    }

    /**
     * Five rounds, five refusals, and the order becomes a person's problem.
     */
    public function testFiveRoundsWithoutAnAcceptanceEndsInNeedsAttention(): void
    {
        self::assertSame(5, Settings::int('dispatch_max_rounds'));

        $drivers = [];

        // Each one a little further out, so the rounds run in a known order and
        // the test is not relying on tie-breaking.
        for ($i = 0; $i < 6; $i++) {
            $drivers[] = $this->createDispatchableDriver('Driver ' . $i, 30.4390 + ($i / 100), -84.2810);
        }

        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $dispatch = new DispatchService();
        $rounds = 0;

        while (($offer = $this->pendingOffer($order['order_id'])) !== null) {
            $rounds++;
            $dispatch->decline((int) $offer['id'], (int) $offer['driver_id']);

            self::assertLessThanOrEqual(5, $rounds, 'dispatch ran more rounds than the setting allows');
        }

        self::assertSame(5, $rounds, 'the spec allows five rounds');
        self::assertSame(Order::STATUS_NEEDS_ATTENTION, $this->orderStatus($order['order_id']));
        self::assertCount(5, DispatchOffer::forOrder($order['order_id']));
    }

    /**
     * The other escalation: the minutes run out before the rounds do.
     */
    public function testAnOrderThatRunsOutOfMinutesEndsInNeedsAttention(): void
    {
        $this->createDispatchableDriver('Slow To Answer');
        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offer = $this->pendingOffer($order['order_id']);

        // The kitchen accepted nine minutes ago; the spec gives dispatch eight.
        $this->backdate($order['order_id'], 'accepted_at', 9 * 60);
        $this->ageOffer((int) $offer['id'], 46);

        (new DispatchService())->timeout((int) $offer['id']);

        self::assertSame(Order::STATUS_NEEDS_ATTENTION, $this->orderStatus($order['order_id']));
        self::assertNull($this->pendingOffer($order['order_id']));
    }

    /**
     * Two accepts, one order, one winner.
     *
     * Both drivers are given a live offer for the same order — a state dispatch
     * itself will not create, and which accept() must survive anyway, because
     * the reason it holds a row lock is precisely that it cannot assume its own
     * invariants held.
     */
    public function testTwoSimultaneousAcceptsLeaveExactlyOneWinner(): void
    {
        $first = $this->createDispatchableDriver('First');
        $second = $this->createDispatchableDriver('Second');

        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offerA = $this->pendingOffer($order['order_id']);
        $loserDriverId = (int) $offerA['driver_id'] === $first ? $second : $first;

        $offerB = DispatchOffer::create([
            'order_id' => $order['order_id'],
            'driver_id' => $loserDriverId,
            'round' => 99,
            'offered_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 45),
        ]);

        $dispatch = new DispatchService();
        $winners = 0;
        $losers = 0;

        foreach ([[(int) $offerA['id'], (int) $offerA['driver_id']], [$offerB, $loserDriverId]] as [$offerId, $driverId]) {
            try {
                $dispatch->accept($offerId, $driverId);
                $winners++;
            } catch (DispatchException $exception) {
                $losers++;
                self::assertSame(DispatchException::REASON_TAKEN, $exception->reason);
            }
        }

        self::assertSame(1, $winners, 'exactly one accept must succeed');
        self::assertSame(1, $losers, 'the other must fail, and say why');

        $assigned = Order::find($order['order_id']);

        self::assertSame(Order::STATUS_DRIVER_ASSIGNED, (string) $assigned['status']);
        self::assertNotNull($assigned['driver_id']);
        self::assertCount(
            1,
            array_filter(
                DispatchOffer::forOrder($order['order_id']),
                static fn (array $row): bool => (string) $row['response'] === DispatchOffer::RESPONSE_ACCEPTED
            )
        );
    }

    /**
     * Accepting sets the three things the rest of the application reads.
     */
    public function testAcceptingAssignsTheOrderAndTakesTheDriverOutOfTheIdlePool(): void
    {
        $driverId = $this->createDispatchableDriver();
        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offer = $this->pendingOffer($order['order_id']);
        (new DispatchService())->accept((int) $offer['id'], $driverId);

        $assigned = Order::find($order['order_id']);

        self::assertSame($driverId, (int) $assigned['driver_id']);
        self::assertSame(Order::STATUS_DRIVER_ASSIGNED, (string) $assigned['status']);
        self::assertNotNull($assigned['driver_assigned_at']);
        self::assertSame(0, (int) Driver::find($driverId)['idle']);
    }

    /**
     * The same driver tapping Accept twice on a bad connection is not an error
     * page.
     */
    public function testASecondAcceptOnTheSameOfferFailsGracefully(): void
    {
        $driverId = $this->createDispatchableDriver();
        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offer = $this->pendingOffer($order['order_id']);
        $dispatch = new DispatchService();
        $dispatch->accept((int) $offer['id'], $driverId);

        $this->expectException(DispatchException::class);

        $dispatch->accept((int) $offer['id'], $driverId);
    }

    /**
     * One pending offer per order, whatever asks for another.
     */
    public function testAnOrderNeverHasTwoPendingOffersAtOnce(): void
    {
        $this->createDispatchableDriver('First');
        $this->createDispatchableDriver('Second');

        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $dispatch = new DispatchService();
        $dispatch->dispatch($order['order_id']);
        $dispatch->dispatch($order['order_id']);

        $pending = array_filter(
            DispatchOffer::forOrder($order['order_id']),
            static fn (array $row): bool => (string) $row['response'] === DispatchOffer::RESPONSE_PENDING
        );

        self::assertCount(1, $pending);
    }

    /**
     * One pending offer per driver, across orders.
     */
    public function testADriverHoldingAnOfferIsNotSentAnother(): void
    {
        $driverId = $this->createDispatchableDriver();

        $first = $this->createPricedOrder($this->restaurantId);
        $second = $this->createPricedOrder($this->restaurantId);

        $this->acceptOrder($first['order_id']);
        $this->acceptOrder($second['order_id']);
        $this->drainQueue();

        self::assertNotNull($this->pendingOffer($first['order_id']));
        self::assertNull(
            $this->pendingOffer($second['order_id']),
            'the only driver is already deciding about another order'
        );
        self::assertCount(1, DispatchOffer::forDriver($driverId));
    }

    /**
     * Offline, unapproved, busy and stale drivers are all skipped, and an order
     * with nobody to send it to waits rather than failing.
     */
    public function testDriversWhoAreNotThereAreNotOffered(): void
    {
        $this->createDispatchableDriver('Offline', null, null, ['online' => 0]);
        $this->createDispatchableDriver('Unapproved', null, null, ['approved' => 0]);
        $this->createDispatchableDriver('Busy', null, null, ['idle' => 0]);
        $this->createDispatchableDriver('Stale', null, null, [
            'last_seen_at' => gmdate('Y-m-d H:i:s', time() - 200),
        ]);
        $this->createDispatchableDriver('No position', null, null, [
            'last_lat' => null,
            'last_lng' => null,
        ]);

        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        self::assertNull($this->pendingOffer($order['order_id']));
        self::assertSame(Order::STATUS_ACCEPTED, $this->orderStatus($order['order_id']));
        self::assertNotSame(
            [],
            $this->queuedJobs(DispatchOfferJob::class),
            'with nobody eligible, dispatch should look again rather than give up'
        );
    }

    /**
     * Two minutes, exactly as the spec says, is the line between a driver who is
     * out there and one whose phone has stopped talking to us.
     */
    public function testTheStalenessWindowIsTwoMinutes(): void
    {
        self::assertSame(120, Driver::SEEN_WITHIN_SECONDS);

        $fresh = $this->createDispatchableDriver('Fresh', null, null, [
            'last_seen_at' => gmdate('Y-m-d H:i:s', time() - 110),
        ]);
        $this->createDispatchableDriver('Stale', 30.4390, -84.2810, [
            'last_seen_at' => gmdate('Y-m-d H:i:s', time() - 130),
        ]);

        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offer = $this->pendingOffer($order['order_id']);

        self::assertNotNull($offer);
        self::assertSame($fresh, (int) $offer['driver_id']);
    }

    /**
     * The kitchen's Accept is what starts dispatch, and it does so through the
     * queue rather than inline.
     */
    public function testAcceptingAnOrderQueuesDispatchRatherThanRunningIt(): void
    {
        $this->createDispatchableDriver();
        $order = $this->createPricedOrder($this->restaurantId);

        $this->acceptOrder($order['order_id']);

        self::assertNull($this->pendingOffer($order['order_id']), 'dispatch should not run inside the kitchen tap');
        self::assertCount(1, $this->queuedJobs(DispatchOfferJob::class));

        $this->drainQueue();

        self::assertNotNull($this->pendingOffer($order['order_id']));
    }

    /**
     * An order pulled out of the flow takes the card off the driver's screen.
     *
     * Through the provider interface rather than through DispatchService
     * directly, because that is the seam the lifecycle calls: whichever network
     * is carrying the order, calling it off has to be one method.
     */
    public function testCallingOffADeliveryClosesTheOfferOnIt(): void
    {
        $this->createDispatchableDriver();
        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        self::assertNotNull($this->pendingOffer($order['order_id']));

        (new \Keel\App\Services\Delivery\InHouseDriverProvider())
            ->cancel(Order::find($order['order_id']), 'too_busy');

        self::assertNull($this->pendingOffer($order['order_id']));
    }

    /**
     * The loser of a race is told they lost, not that they answered.
     *
     * Both cards are closed the same way in the database — expired — so the
     * difference has to come from the order, and a driver who was a second slow
     * should be told that rather than accused of having already replied.
     */
    public function testTheLoserOfARaceIsToldTheOrderWasTaken(): void
    {
        $winner = $this->createDispatchableDriver('Winner');
        $loser = $this->createDispatchableDriver('Loser');

        $order = $this->createPricedOrder($this->restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offerA = $this->pendingOffer($order['order_id']);
        $winnerId = (int) $offerA['driver_id'];
        $loserId = $winnerId === $winner ? $loser : $winner;

        $offerB = DispatchOffer::create([
            'order_id' => $order['order_id'],
            'driver_id' => $loserId,
            'round' => 99,
            'offered_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 45),
        ]);

        $dispatch = new DispatchService();
        $dispatch->accept((int) $offerA['id'], $winnerId);

        try {
            $dispatch->accept($offerB, $loserId);
            self::fail('the second accept should have been refused');
        } catch (DispatchException $exception) {
            self::assertSame(DispatchException::REASON_TAKEN, $exception->reason);
            self::assertSame('Another driver took that order.', $exception->getMessage());
        }
    }
}
