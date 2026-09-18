<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\Dispatch\OfferCard;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Pricing\PricingService;
use Tests\Support\DriverFixtures;
use Tests\TestCase;

/**
 * The offer card: what it says, who may see it, and the two buttons.
 *
 * The spec's promise is that a driver knows the guaranteed payout and both
 * distances before accepting, and that the guarantee never drops afterwards.
 * That promise is only worth anything if the number on the card is the same
 * number the customer was charged for, so the first test here recomputes it
 * from PricingService and the order's own snapshot rather than trusting what
 * the card assembled.
 */
class DriverOfferFeatureTest extends TestCase
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
     * The self-check the spec asks for outright: the guaranteed amount on the
     * card is PricingService::driverGuaranteed plus the tip.
     */
    public function testTheCardsGuaranteedAmountIsTheDriverGuaranteePlusTheTip(): void
    {
        $driverId = $this->createDispatchableDriver();
        $order = $this->createPricedOrder($this->restaurantId, 3.0, 200);
        $offer = $this->offerFor($order['order_id']);

        $card = OfferCard::forOffer(\Keel\App\Models\Driver::find($driverId), $offer);

        $row = OrderPriceBreakdown::forStage($order['order_id'], OrderPriceBreakdown::STAGE_AUTHORIZED);
        $snapshot = OrderPriceBreakdown::settingsSnapshot($row);
        $pricing = new PricingService();

        $expected = $pricing->driverGuaranteed((float) $snapshot['route_miles'], $snapshot)
            + (int) $row['tip_cents'];

        self::assertSame($expected, $card['pay']['payout_cents']);

        // And the arithmetic behind it, from the spec's seed settings:
        // max(300 + round(100 x 3.00), 500) = 600, plus a 200 tip.
        self::assertSame(600, $card['pay']['guaranteed_cents']);
        self::assertSame(300, $card['pay']['base_cents']);
        self::assertSame(300, $card['pay']['mileage_cents']);
        self::assertSame(200, $card['pay']['tip_cents']);
        self::assertSame(800, $card['pay']['payout_cents']);
    }

    /**
     * The guarantee the card promises is the one the customer was already
     * charged, which is what makes "never drops after acceptance" true rather
     * than merely intended.
     */
    public function testTheCardsGuaranteeMatchesWhatTheCustomerWasCharged(): void
    {
        $driverId = $this->createDispatchableDriver();
        $order = $this->createPricedOrder($this->restaurantId, 4.5, 350);
        $offer = $this->offerFor($order['order_id']);

        $card = OfferCard::forOffer(\Keel\App\Models\Driver::find($driverId), $offer);
        $row = OrderPriceBreakdown::forStage($order['order_id'], OrderPriceBreakdown::STAGE_AUTHORIZED);

        self::assertSame((int) $row['driver_guaranteed_cents'], $card['pay']['guaranteed_cents']);
        self::assertSame((int) $row['tip_cents'], $card['pay']['tip_cents']);
    }

    /**
     * Both distances, and they are different measurements on purpose: the
     * drop-off is the routed distance the pay was computed from, the pickup is a
     * straight line to the restaurant that pays nothing.
     */
    public function testTheCardCarriesBothDistances(): void
    {
        // A little under two miles north of the restaurant.
        $driverId = $this->createDispatchableDriver('Near', 30.4650, -84.2807);
        $order = $this->createPricedOrder($this->restaurantId, 3.0);
        $offer = $this->offerFor($order['order_id']);

        $card = OfferCard::forOffer(\Keel\App\Models\Driver::find($driverId), $offer);

        self::assertSame(3.0, $card['dropoff_miles']);
        self::assertNotNull($card['to_restaurant_miles']);
        self::assertGreaterThan(1.5, $card['to_restaurant_miles']);
        self::assertLessThan(2.5, $card['to_restaurant_miles']);
    }

    /**
     * The rate per mile, which is the number being compared against the other
     * app, is computed by PricingService rather than by a view.
     */
    public function testThePerMileRateIsThePayoutOverTheRouteMiles(): void
    {
        $driverId = $this->createDispatchableDriver();
        $order = $this->createPricedOrder($this->restaurantId, 3.0, 200);
        $offer = $this->offerFor($order['order_id']);

        $card = OfferCard::forOffer(\Keel\App\Models\Driver::find($driverId), $offer);

        // 800 over 3.00 miles, to the nearest cent.
        self::assertSame(267, $card['pay']['per_mile_cents']);
        self::assertSame(267, (new PricingService())->payPerMile(800, 3.0));
    }

    /**
     * The card renders, with the payout, the wait-pay line the spec dictates,
     * and an Accept button.
     */
    public function testTheOfferRendersOnTheHomeScreen(): void
    {
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $order = $this->createPricedOrder($this->restaurantId, 3.0, 200);
        $this->offerFor($order['order_id']);

        $response = $this->get('/drive');

        self::assertSame(200, $response->status);
        self::assertStringContainsString(Money::usd(800), $response->body);
        self::assertStringContainsString('Guaranteed', $response->body);
        self::assertStringContainsString('Taqueria Uno', $response->body);
        // The spec's exact promise, built from the order's snapshot rather than
        // written into the view.
        self::assertStringContainsString('+ $0.20/min wait pay after', $response->body);
        self::assertStringContainsString('10 min at the restaurant', $response->body);
        self::assertStringContainsString('offer-accept', $response->body);
    }

    /**
     * Nothing about the customer appears on a card that has not been accepted.
     */
    public function testTheCardSaysNothingAboutTheCustomer(): void
    {
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $order = $this->createPricedOrder($this->restaurantId);
        $this->offerFor($order['order_id']);

        $response = $this->get('/drive');

        self::assertStringNotContainsString('Marisol', $response->body);
        self::assertStringNotContainsString('9 Oak St', $response->body);
    }

    /**
     * The three-second poll hands back the same markup rather than JSON for a
     * script to assemble.
     */
    public function testThePollReturnsTheRenderedCard(): void
    {
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $order = $this->createPricedOrder($this->restaurantId, 3.0, 200);
        $offer = $this->offerFor($order['order_id']);

        $response = $this->getJson('/drive/offers/current');
        $data = $response->json();

        self::assertTrue($data['offer']);
        self::assertSame((int) $offer['id'], $data['offer_id']);
        self::assertGreaterThan(0, $data['seconds_left']);
        self::assertSame(45, $data['window_seconds']);
        self::assertStringContainsString(Money::usd(800), $data['html']);
        // Icons in a polled partial have to resolve against the same base the
        // page was served with, or they quietly 404 once swapped in.
        self::assertStringContainsString('/deck/deck-icons.svg', $data['html']);
    }

    /**
     * An offer also has a URL of its own, for a driver coming back to the app
     * from a notification.
     */
    public function testAnOfferHasAPageOfItsOwn(): void
    {
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $order = $this->createPricedOrder($this->restaurantId, 3.0, 200);
        $offer = $this->offerFor($order['order_id']);

        $response = $this->get('/drive/offers/' . (int) $offer['id']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString(Money::usd(800), $response->body);
        self::assertStringContainsString('offer-accept', $response->body);
    }

    /**
     * An offer somebody else already took is a sentence and a trip home, not an
     * error page.
     */
    public function testAnOfferThatIsNoLongerOpenSendsTheDriverHome(): void
    {
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $order = $this->createPricedOrder($this->restaurantId);
        $offer = $this->offerFor($order['order_id']);

        (new \Keel\App\Services\Dispatch\DispatchService())->decline((int) $offer['id'], $driverId);

        $response = $this->get('/drive/offers/' . (int) $offer['id']);

        self::assertSame(302, $response->status);
        self::assertSame('/drive', $response->header('Location'));
    }

    /**
     * With no offer, the poll says so rather than handing back stale markup.
     */
    public function testThePollSaysNothingIsWaitingWhenNothingIs(): void
    {
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $data = $this->getJson('/drive/offers/current')->json();

        self::assertFalse($data['offer']);
        self::assertSame('', $data['html']);
        self::assertNull($data['redirect']);
    }

    /**
     * A driver already carrying something is sent to it rather than left
     * looking at a switch.
     */
    public function testThePollSendsADriverBackToTheirActiveDelivery(): void
    {
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $order = $this->createPricedOrder($this->restaurantId);
        $offer = $this->offerFor($order['order_id']);
        (new \Keel\App\Services\Dispatch\DispatchService())->accept((int) $offer['id'], $driverId);

        $data = $this->getJson('/drive/offers/current')->json();

        self::assertFalse($data['offer']);
        self::assertSame('/drive/orders/' . $order['order_id'], $data['redirect']);
    }

    public function testAcceptingFromTheAppAssignsTheOrder(): void
    {
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $order = $this->createPricedOrder($this->restaurantId);
        $offer = $this->offerFor($order['order_id']);

        $response = $this->post('/drive/offers/' . (int) $offer['id'] . '/accept', [
            '_csrf' => $this->csrfToken(),
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('/drive/orders/' . $order['order_id'], $response->header('Location'));
        self::assertSame($driverId, (int) Order::find($order['order_id'])['driver_id']);
    }

    public function testDecliningFromTheAppClosesTheOffer(): void
    {
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $order = $this->createPricedOrder($this->restaurantId);
        $offer = $this->offerFor($order['order_id']);

        $response = $this->post('/drive/offers/' . (int) $offer['id'] . '/decline', [
            '_csrf' => $this->csrfToken(),
        ]);

        self::assertSame(302, $response->status);
        self::assertSame(
            DispatchOffer::RESPONSE_DECLINED,
            (string) DispatchOffer::find((int) $offer['id'])['response']
        );
        self::assertNull(Order::find($order['order_id'])['driver_id']);
    }

    /**
     * The spec's access rule, from the other driver's side. A 403 rather than a
     * 404: a driver who followed a stale link should be told plainly.
     */
    public function testADriverCannotSeeAnotherDriversOffer(): void
    {
        // "Theirs" is the nearer of the two, so dispatch offers it to them and
        // this test is about a driver reaching for a card that was never sent to
        // them rather than about tie-breaking.
        $mine = $this->createDispatchableDriver('Mine', 30.5200, -84.4000);
        $theirs = $this->createDispatchableDriver('Theirs', 30.4390, -84.2810);

        $order = $this->createPricedOrder($this->restaurantId);
        $offer = $this->offerFor($order['order_id'], $theirs);

        $this->actingAsDriver($mine);

        self::assertSame(403, $this->get('/drive/offers/' . (int) $offer['id'])->status);
        self::assertSame(403, $this->post('/drive/offers/' . (int) $offer['id'] . '/accept', [
            '_csrf' => $this->csrfToken(),
        ])->status);
        self::assertSame(403, $this->post('/drive/offers/' . (int) $offer['id'] . '/decline', [
            '_csrf' => $this->csrfToken(),
        ])->status);

        self::assertSame(
            DispatchOffer::RESPONSE_PENDING,
            (string) DispatchOffer::find((int) $offer['id'])['response'],
            'and the offer is untouched'
        );
    }

    /**
     * The Accept button clears the spec's 64px floor, and the stylesheet the
     * page loads is what sets it.
     */
    public function testTheAcceptButtonIsAtLeastSixtyFourPixelsTall(): void
    {
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $order = $this->createPricedOrder($this->restaurantId);
        $this->offerFor($order['order_id']);

        $body = $this->get('/drive')->body;

        self::assertStringContainsString('/css/drive.css', $body);
        self::assertStringContainsString('class="btn btn-primary offer-accept"', $body);

        $css = (string) file_get_contents(self::$basePath . '/public_html/css/drive.css');

        self::assertMatchesRegularExpression('/--drive-action-h:\s*64px/', $css);
        self::assertMatchesRegularExpression(
            '/\.offer-accept\s*\{[^}]*min-block-size:\s*var\(--drive-action-h\)/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.delivery-step-action\s*\{[^}]*min-block-size:\s*var\(--drive-action-h\)/s',
            $css
        );
    }

    /**
     * Dispatches an order and hands back the offer it produced.
     */
    private function offerFor(int $orderId, ?int $expectedDriverId = null): array
    {
        $this->acceptOrder($orderId);
        $this->drainQueue();

        $offer = $this->pendingOffer($orderId);

        self::assertNotNull($offer, 'the fixture order should have been offered to somebody');

        if ($expectedDriverId !== null) {
            self::assertSame($expectedDriverId, (int) $offer['driver_id']);
        }

        return $offer;
    }
}
