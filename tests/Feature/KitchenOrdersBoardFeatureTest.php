<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Order;
use Keel\App\Services\OrderLifecycle;
use Keel\Core\Csrf;
use Tests\Support\KitchenFixtures;
use Tests\TestCase;

/**
 * The board, the poll, and the three buttons.
 *
 * The board is server-rendered and the poll asks for the same markup back, so
 * these tests exercise the real thing rather than a JSON shape that a template
 * might or might not agree with.
 */
class KitchenOrdersBoardFeatureTest extends TestCase
{
    use KitchenFixtures;

    private int $restaurantId;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $zoneId = $this->createZone();
        $created = $this->createRestaurantWithOwner('Board Kitchen', $zoneId);

        $this->restaurantId = $created['restaurant_id'];
        $this->ownerId = $created['owner_id'];

        $this->actingAsOwner($this->ownerId);
    }

    public function testANewOrderAppearsInTheNewColumn(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $response = $this->get('/kitchen');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('#' . $orderId, $response->body);
        self::assertStringContainsString('aria-label="New"', $response->body);
        self::assertStringContainsString('order-card-new', $response->body, 'and it is marked as new');
    }

    /**
     * What the five-second poll actually returns.
     */
    public function testTheFeedCarriesTheOrderTheChimeAndTheMarkup(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $response = $this->getJson('/kitchen/orders/feed');
        $feed = $response->json();

        self::assertSame(200, $response->status);
        self::assertSame([$orderId], $feed['new_order_ids'], 'the chime rings for this order');
        self::assertNotSame('', $feed['signature']);
        self::assertStringContainsString('#' . $orderId, $feed['html']);
        self::assertFalse($feed['paused']);
    }

    /**
     * The whole card: what the kitchen has to read to cook the thing.
     */
    public function testTheCardShowsItemsOptionsNotesAndTheCustomersFirstName(): void
    {
        $this->createPlacedOrder($this->restaurantId);

        $body = $this->get('/kitchen')->body;

        self::assertStringContainsString('Al Pastor Taco', $body);
        self::assertStringContainsString('Large', $body, 'the chosen option');
        self::assertStringContainsString('No onions, please', $body, 'the special instructions');
        self::assertStringContainsString('Marisol', $body, 'the first name');
        self::assertStringNotContainsString('Vega', $body, 'and only the first name');
    }

    public function testTheCardShowsTheDriverAndEtaOnceAssigned(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);
        $driverId = $this->createDriver('Dee Rowan');

        OrderLifecycle::accept($orderId, 15);
        OrderLifecycle::markReady($orderId);
        OrderLifecycle::transition($orderId, Order::STATUS_DRIVER_ASSIGNED, [
            'driver_id' => $driverId,
            'driver_eta_at' => gmdate('Y-m-d H:i:s', time() + 600),
        ]);

        $body = $this->get('/kitchen')->body;

        self::assertStringContainsString('Dee Rowan', $body);
        self::assertStringContainsString('ETA', $body);
        self::assertStringNotContainsString('ETA pending', $body);
    }

    public function testADriverWithNoEtaYetSaysSoRatherThanGuessing(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);
        $driverId = $this->createDriver('Sam Okonkwo');

        OrderLifecycle::accept($orderId, 15);
        OrderLifecycle::transition($orderId, Order::STATUS_DRIVER_ASSIGNED, ['driver_id' => $driverId]);

        $body = $this->get('/kitchen')->body;

        self::assertStringContainsString('Sam Okonkwo', $body);
        self::assertStringContainsString('ETA pending', $body);
    }

    public function testAcceptMovesTheOrderAndRecordsThePrepTime(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $response = $this->post('/kitchen/orders/' . $orderId . '/accept', [
            '_csrf' => Csrf::token(),
            'prep_minutes' => 20,
        ]);

        self::assertSame(302, $response->status);
        self::assertSame(Order::STATUS_ACCEPTED, $this->orderStatus($orderId));
        self::assertSame(20, (int) Order::find($orderId)['prep_minutes']);

        // Out of New, into In Progress, and no longer ringing.
        $feed = $this->getJson('/kitchen/orders/feed')->json();
        self::assertSame([], $feed['new_order_ids']);
        self::assertStringContainsString('Mark ready', $feed['html']);
    }

    public function testMarkReadyMovesTheOrderAgain(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);
        OrderLifecycle::accept($orderId, 15);

        $response = $this->post('/kitchen/orders/' . $orderId . '/ready', ['_csrf' => Csrf::token()]);

        self::assertSame(302, $response->status);
        self::assertSame(Order::STATUS_READY, $this->orderStatus($orderId));

        $feed = $this->getJson('/kitchen/orders/feed')->json();
        self::assertStringContainsString('Waiting for a driver', $feed['html']);
    }

    public function testRejectRecordsTheReasonAndClearsTheBoard(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $response = $this->post('/kitchen/orders/' . $orderId . '/reject', [
            '_csrf' => Csrf::token(),
            'reason' => 'item_unavailable',
        ]);

        self::assertSame(302, $response->status);
        self::assertSame(Order::STATUS_REJECTED, $this->orderStatus($orderId));
        self::assertSame('Item unavailable', (string) Order::find($orderId)['reject_reason']);

        $feed = $this->getJson('/kitchen/orders/feed')->json();
        self::assertStringNotContainsString('#' . $orderId, $feed['html']);
    }

    /**
     * Every reason the card offers has to be one the lifecycle accepts.
     */
    public function testEveryOfferedRejectionReasonWorks(): void
    {
        foreach (array_keys(OrderLifecycle::REJECT_REASONS) as $reason) {
            $orderId = $this->createPlacedOrder($this->restaurantId);

            $response = $this->post('/kitchen/orders/' . $orderId . '/reject', [
                '_csrf' => Csrf::token(),
                'reason' => $reason,
            ]);

            self::assertSame(302, $response->status, "rejecting with \"{$reason}\"");
            self::assertSame(Order::STATUS_REJECTED, $this->orderStatus($orderId));
        }
    }

    /**
     * Every prep time the card offers has to be one the lifecycle accepts.
     */
    public function testEveryOfferedPrepTimeWorks(): void
    {
        foreach (OrderLifecycle::PREP_MINUTES as $minutes) {
            $orderId = $this->createPlacedOrder($this->restaurantId);

            $response = $this->post('/kitchen/orders/' . $orderId . '/accept', [
                '_csrf' => Csrf::token(),
                'prep_minutes' => $minutes,
            ]);

            self::assertSame(302, $response->status, "accepting with {$minutes} minutes");
            self::assertSame($minutes, (int) Order::find($orderId)['prep_minutes']);
        }
    }

    /**
     * A tablet whose board is a few seconds stale should be told, not obeyed.
     */
    public function testATapOnAnOrderThatAlreadyMovedIsRefusedGracefully(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);
        OrderLifecycle::accept($orderId, 15);

        $response = $this->post('/kitchen/orders/' . $orderId . '/accept', [
            '_csrf' => Csrf::token(),
            'prep_minutes' => 45,
        ]);

        self::assertSame(302, $response->status, 'a stale tap is not a crash');
        self::assertSame(15, (int) Order::find($orderId)['prep_minutes'], 'the first answer stands');
    }

    public function testTheSignatureOnlyChangesWhenSomethingOnTheBoardDoes(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);

        $first = $this->getJson('/kitchen/orders/feed')->json()['signature'];
        $second = $this->getJson('/kitchen/orders/feed')->json()['signature'];

        self::assertSame($first, $second, 'nothing changed, so the board is not redrawn');

        OrderLifecycle::accept($orderId, 15);

        $third = $this->getJson('/kitchen/orders/feed')->json()['signature'];

        self::assertNotSame($first, $third);
    }

    public function testFinishedOrdersLeaveTheBoard(): void
    {
        $orderId = $this->createPlacedOrder($this->restaurantId);
        OrderLifecycle::accept($orderId, 10);
        OrderLifecycle::markReady($orderId);
        OrderLifecycle::transition($orderId, Order::STATUS_DRIVER_ASSIGNED);
        OrderLifecycle::transition($orderId, Order::STATUS_ARRIVED_AT_RESTAURANT);

        $feed = $this->getJson('/kitchen/orders/feed')->json();

        self::assertStringNotContainsString('#' . $orderId, $feed['html']);
    }

    /**
     * The spec asks for a five-second poll and a chime, and both live in the
     * script. Reading the constants out of it is not the same as watching a
     * browser do it, but it does catch the day someone changes 5000 to 60000.
     */
    public function testTheBoardScriptPollsEveryFiveSeconds(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public_html/js/kitchen-orders.js');

        self::assertStringContainsString('const POLL_MS = 5000;', $script);
        self::assertStringContainsString("window.setInterval(poll, POLL_MS)", $script);
        self::assertStringContainsString('chimeTimer = window.setInterval(playOnce, CHIME_MS)', $script);
    }

    public function testTheChimeFileIsThere(): void
    {
        $path = dirname(__DIR__, 2) . '/public_html/sounds/new-order.mp3';

        self::assertFileExists($path);
        self::assertGreaterThan(1000, filesize($path), 'a real audio file, not a placeholder');
    }

    /**
     * Autoplay rules mean the chime cannot ring until someone has touched the
     * page, so the board has to say so rather than failing silently.
     */
    public function testTheBoardCarriesTheSoundGate(): void
    {
        $body = $this->get('/kitchen')->body;

        self::assertStringContainsString('id="sound-gate"', $body);
        self::assertStringContainsString('Tap to enable sound', $body);
        self::assertStringContainsString('id="chime-stop"', $body);
        self::assertStringContainsString('data-feed="/kitchen/orders/feed"', $body);
    }

    public function testTheBoardCarriesTheEightySixSwitch(): void
    {
        $categoryId = $this->createCategory($this->restaurantId);
        $itemId = $this->createItem($this->restaurantId, $categoryId, ['name' => 'Horchata']);

        $body = $this->get('/kitchen')->body;

        self::assertStringContainsString('86 an item', $body);
        self::assertStringContainsString('Horchata', $body);
        self::assertStringContainsString('/kitchen/menu/items/' . $itemId . '/stock', $body);
    }

    public function testTheMonthPanelReportsOrdersTierAndFee(): void
    {
        $this->createFeeTiers();

        $response = $this->get('/kitchen/month');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Completed orders', $response->body);
        self::assertStringContainsString('Projected fee', $response->body);
        // Nothing delivered yet, so the free tier and a zero fee.
        self::assertStringContainsString('$0.00', $response->body);
        self::assertStringContainsString('Nothing is deducted from these orders', $response->body);
    }
}
