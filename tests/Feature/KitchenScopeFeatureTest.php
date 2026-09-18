<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\MenuItem;
use Keel\App\Models\Order;
use Keel\App\Models\RestaurantStaff;
use Keel\App\Models\Special;
use Keel\App\Policies\RestaurantPolicy;
use Keel\Core\Csrf;
use Tests\Support\KitchenFixtures;
use Tests\TestCase;

/**
 * Restaurant A, holding restaurant B's record ids.
 *
 * Every id in a kitchen URL is a sequential integer from a table shared by the
 * whole platform, so the ids of another restaurant's orders and menu items are
 * not a secret and were never going to be. What stops A reading and writing B's
 * data is the policy, and this is the test that says so — for every kind of
 * record the kitchen exposes, on both the read routes and the write ones.
 *
 * Two things it deliberately checks that a UI test would not:
 *   - a GET to another restaurant's item is a 403, not a page with someone
 *     else's prices on it;
 *   - a POST that names another restaurant's id in the *body* rather than the
 *     path is refused too, because a form field is as easy to change as a URL.
 */
class KitchenScopeFeatureTest extends TestCase
{
    use KitchenFixtures;

    private array $a;
    private array $b;
    private array $bRecords;

    protected function setUp(): void
    {
        parent::setUp();

        $zoneId = $this->createZone();

        $this->a = $this->createRestaurantWithOwner('Restaurant A', $zoneId);
        $this->b = $this->createRestaurantWithOwner('Restaurant B', $zoneId);

        $categoryId = $this->createCategory($this->b['restaurant_id'], 'B Category');
        $itemId = $this->createItem($this->b['restaurant_id'], $categoryId, ['name' => 'B Item']);
        $groupId = $this->createOptionGroup($itemId);

        $this->bRecords = [
            'category' => $categoryId,
            'item' => $itemId,
            'group' => $groupId,
            'option' => $this->createOption($groupId),
            'order' => $this->createPlacedOrder($this->b['restaurant_id']),
            'special' => Special::create([
                'restaurant_id' => $this->b['restaurant_id'],
                'title' => 'B Special',
                'type' => Special::TYPE_PERCENT,
                'value_pct' => '0.1000',
                'active' => 1,
            ]),
            'staff' => (int) RestaurantStaff::forRestaurant($this->b['restaurant_id'])[0]['id'],
        ];

        $this->actingAsOwner($this->a['owner_id']);
    }

    /**
     * Every write route that takes one of B's record ids in the path.
     */
    public function testEveryWriteRouteOnAnotherRestaurantsRecordIsForbidden(): void
    {
        $routes = [
            '/kitchen/orders/' . $this->bRecords['order'] . '/accept' => ['prep_minutes' => 15],
            '/kitchen/orders/' . $this->bRecords['order'] . '/reject' => ['reason' => 'too_busy'],
            '/kitchen/orders/' . $this->bRecords['order'] . '/ready' => [],
            '/kitchen/menu/categories/' . $this->bRecords['category'] => ['name' => 'Renamed'],
            '/kitchen/menu/categories/' . $this->bRecords['category'] . '/delete' => [],
            '/kitchen/menu/categories/' . $this->bRecords['category'] . '/move' => ['direction' => 'down'],
            '/kitchen/menu/items/' . $this->bRecords['item'] => ['name' => 'Renamed', 'price' => '1.00'],
            '/kitchen/menu/items/' . $this->bRecords['item'] . '/delete' => [],
            '/kitchen/menu/items/' . $this->bRecords['item'] . '/stock' => [],
            '/kitchen/menu/items/' . $this->bRecords['item'] . '/move' => ['direction' => 'up'],
            '/kitchen/menu/items/' . $this->bRecords['item'] . '/photo/delete' => [],
            '/kitchen/menu/items/' . $this->bRecords['item'] . '/groups' => ['name' => 'Mine now'],
            '/kitchen/menu/groups/' . $this->bRecords['group'] => ['name' => 'Renamed'],
            '/kitchen/menu/groups/' . $this->bRecords['group'] . '/delete' => [],
            '/kitchen/menu/groups/' . $this->bRecords['group'] . '/options' => ['name' => 'Mine now'],
            '/kitchen/menu/options/' . $this->bRecords['option'] => ['name' => 'Renamed', 'price_delta' => '0'],
            '/kitchen/menu/options/' . $this->bRecords['option'] . '/delete' => [],
            '/kitchen/specials/' . $this->bRecords['special'] => ['title' => 'Renamed', 'type' => 'percent', 'value' => '5'],
            '/kitchen/specials/' . $this->bRecords['special'] . '/delete' => [],
            '/kitchen/staff/' . $this->bRecords['staff'] . '/delete' => [],
        ];

        foreach ($routes as $path => $body) {
            $response = $this->post($path, array_merge($body, ['_csrf' => Csrf::token()]));

            self::assertSame(403, $response->status, "POST {$path} should be forbidden");
        }

        self::assertSame(20, count($routes), 'every kitchen write route with a record id is covered');
    }

    /**
     * The move route names its collection in the path as well as its id, so a
     * mismatched pair must not open a door either.
     */
    public function testAnUnknownCollectionOnTheMoveRouteIsForbidden(): void
    {
        $response = $this->post('/kitchen/menu/specials/' . $this->bRecords['special'] . '/move', [
            '_csrf' => Csrf::token(),
            'direction' => 'up',
        ]);

        self::assertSame(403, $response->status);
    }

    public function testReadingAnotherRestaurantsItemIsForbidden(): void
    {
        $response = $this->get('/kitchen/menu/items/' . $this->bRecords['item']);

        self::assertSame(403, $response->status);
        self::assertStringNotContainsString('B Item', $response->body);
    }

    public function testNoneOfTheWritesLanded(): void
    {
        $this->testEveryWriteRouteOnAnotherRestaurantsRecordIsForbidden();

        self::assertSame(Order::STATUS_PLACED, $this->orderStatus($this->bRecords['order']));
        self::assertSame('B Item', (string) MenuItem::find($this->bRecords['item'])['name']);
        self::assertSame(1, (int) MenuItem::find($this->bRecords['item'])['in_stock']);
        self::assertSame('B Special', (string) Special::find($this->bRecords['special'])['title']);
        self::assertNotNull(RestaurantStaff::find($this->bRecords['staff']));
    }

    /**
     * The id in a form field gets the same treatment as the id in a path.
     */
    public function testAnItemCannotBeAddedToAnotherRestaurantsCategory(): void
    {
        $response = $this->post('/kitchen/menu/items', [
            '_csrf' => Csrf::token(),
            'menu_category_id' => $this->bRecords['category'],
            'name' => 'Planted',
            'price' => '5.00',
        ]);

        self::assertSame(403, $response->status);

        $names = array_column(MenuItem::forCategory($this->bRecords['category']), 'name');
        self::assertNotContains('Planted', $names);
    }

    public function testASpecialCannotPointAtAnotherRestaurantsItem(): void
    {
        $response = $this->post('/kitchen/specials', [
            '_csrf' => Csrf::token(),
            'title' => 'Borrowed',
            'type' => Special::TYPE_PRICE,
            'value' => '1.00',
            'menu_item_id' => $this->bRecords['item'],
        ]);

        self::assertSame(403, $response->status);
        self::assertSame([], Special::forRestaurant($this->a['restaurant_id']));
    }

    /**
     * The drag-to-sort endpoint takes a whole list of ids at once, which makes
     * it the easiest one to write with the check applied to the first id only.
     */
    public function testSortingCannotIncludeAnotherRestaurantsRecords(): void
    {
        $ownCategory = $this->createCategory($this->a['restaurant_id'], 'A Category');

        $response = $this->postJson('/kitchen/menu/sort', [
            '_csrf' => Csrf::token(),
            'type' => 'categories',
            'order' => [$ownCategory, $this->bRecords['category']],
        ]);

        self::assertSame(403, $response->status);
    }

    public function testASwitchToAnotherRestaurantIsForbidden(): void
    {
        $response = $this->post('/kitchen/restaurant/select', [
            '_csrf' => Csrf::token(),
            'restaurant_id' => $this->b['restaurant_id'],
        ]);

        self::assertSame(403, $response->status);
    }

    /**
     * Another restaurant's orders must not turn up in the poll either — the
     * board is a read, and a read that leaks is still a leak.
     */
    public function testTheFeedOnlyShowsThisRestaurantsOrders(): void
    {
        $ownOrder = $this->createPlacedOrder($this->a['restaurant_id']);

        $response = $this->getJson('/kitchen/orders/feed');
        $feed = $response->json();

        self::assertSame(200, $response->status);
        self::assertSame([$ownOrder], $feed['new_order_ids']);
        self::assertStringNotContainsString('#' . $this->bRecords['order'], $feed['html']);
    }

    /**
     * The owner's own records still work, which is what makes the 403s above
     * mean something rather than the routes being broken for everyone.
     */
    public function testTheOwnerReachesTheirOwnRecords(): void
    {
        $categoryId = $this->createCategory($this->a['restaurant_id'], 'A Category');
        $itemId = $this->createItem($this->a['restaurant_id'], $categoryId, ['name' => 'A Item']);

        $page = $this->get('/kitchen/menu/items/' . $itemId);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('A Item', $page->body);

        $toggled = $this->post('/kitchen/menu/items/' . $itemId . '/stock', ['_csrf' => Csrf::token()]);
        self::assertSame(302, $toggled->status);
        self::assertSame(0, (int) MenuItem::find($itemId)['in_stock']);
    }

    /**
     * The policy resolves a record id back to its restaurant through however
     * many joins that takes, and an option is three away from one.
     */
    public function testThePolicyWalksTheWholeOwnershipChain(): void
    {
        self::assertSame(
            $this->b['restaurant_id'],
            RestaurantPolicy::restaurantIdFor('item_options', $this->bRecords['option'])
        );

        self::assertFalse(
            RestaurantPolicy::allowsRecord($this->a['owner_id'], 'item_options', $this->bRecords['option'])
        );

        self::assertTrue(
            RestaurantPolicy::allowsRecord($this->b['owner_id'], 'item_options', $this->bRecords['option'])
        );
    }

    /**
     * A record that never existed is refused the same way one belonging to
     * someone else is, so probing ids tells the prober nothing.
     */
    public function testAMissingRecordIsRefusedRatherThanReported(): void
    {
        self::assertNull(RestaurantPolicy::restaurantIdFor('menu_items', 999999));
        self::assertFalse(RestaurantPolicy::allowsRecord($this->a['owner_id'], 'menu_items', 999999));

        $response = $this->post('/kitchen/menu/items/999999/stock', ['_csrf' => Csrf::token()]);
        self::assertSame(403, $response->status);
    }

    /**
     * A kitchen table with no ownership rule refuses everyone rather than
     * admitting everyone, which is the failure mode worth having when someone
     * adds a table in a later phase and forgets this file.
     */
    public function testAnUndeclaredTableCannotBeAuthorized(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RestaurantPolicy::allowsRecord($this->a['owner_id'], 'users', $this->b['owner_id']);
    }
}
