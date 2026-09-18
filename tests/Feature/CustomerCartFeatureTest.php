<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Cart;
use Keel\App\Models\CartItem;
use Keel\App\Models\ItemOptionGroup;
use Keel\App\Models\MenuItem;
use Keel\App\Services\CartService;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * The cart: what may go in it, and what happens when a second kitchen is asked
 * for.
 */
class CustomerCartFeatureTest extends TestCase
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

    public function testAnItemIsAddedAndTheCartIsHeldOnTheServerForThatCustomer(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $response = $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $shop['item_id'],
            'quantity' => 2,
        ]);

        self::assertSame(302, $response->status);

        $cart = Cart::forUser($userId);

        self::assertNotNull($cart, 'The cart is a row, not a cookie.');
        self::assertSame($shop['restaurant_id'], (int) $cart['restaurant_id']);

        $lines = CartItem::forCart((int) $cart['id']);

        self::assertCount(1, $lines);
        self::assertSame(2, (int) $lines[0]['quantity']);
    }

    public function testAPricePostedByTheClientIsNotStoredAndNotBelieved(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();

        $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $shop['item_id'],
            'quantity' => 1,
            // A page trying its luck. Nothing reads these.
            'price_cents' => 1,
            'unit_price_cents' => 1,
            'line_total_cents' => 1,
        ]);

        $summary = (new CartService())->summary((int) $customer['user']['id']);
        $menuPrice = (int) MenuItem::find($shop['item_id'])['price_cents'];

        self::assertSame($menuPrice, $summary['subtotal_cents']);
        self::assertSame($menuPrice, (int) $summary['lines'][0]['unit_price_cents']);
    }

    public function testARequiredOptionGroupRefusesAnEmptyChoice(): void
    {
        $shop = $this->createOrderableRestaurant();
        $groupId = $this->createOptionGroup($shop['item_id'], 'Pick a salsa');
        ItemOptionGroup::update($groupId, ['required' => 1, 'min_select' => 1, 'max_select' => 1]);
        $this->createOption($groupId, 'Verde', 0);

        $customer = $this->actingAsCustomer();

        $response = $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $shop['item_id'],
            'quantity' => 1,
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Choose an option for &quot;Pick a salsa&quot;.', $response->body);
        self::assertSame(0, (new CartService())->count((int) $customer['user']['id']));
    }

    public function testMoreOptionsThanTheGroupAllowsAreRefused(): void
    {
        $shop = $this->createOrderableRestaurant();
        $groupId = $this->createOptionGroup($shop['item_id'], 'Two toppings');
        ItemOptionGroup::update($groupId, ['required' => 0, 'min_select' => 0, 'max_select' => 1]);
        $first = $this->createOption($groupId, 'Cheese', 50);
        $second = $this->createOption($groupId, 'Crema', 50);

        $customer = $this->actingAsCustomer();

        $response = $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $shop['item_id'],
            'quantity' => 1,
            'options' => [$first, $second],
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Only one option can be chosen', $response->body);
        self::assertSame(0, (new CartService())->count((int) $customer['user']['id']));
    }

    public function testAnOptionFromAnotherItemIsRefused(): void
    {
        $shop = $this->createOrderableRestaurant();
        $otherItemId = $this->createItem($shop['restaurant_id'], $shop['category_id'], ['name' => 'Elote']);
        $foreignGroup = $this->createOptionGroup($otherItemId, 'Elote extras');
        $foreignOption = $this->createOption($foreignGroup, 'Chilli', 100);

        $customer = $this->actingAsCustomer();

        $response = $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $shop['item_id'],
            'quantity' => 1,
            'options' => [$foreignOption],
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('not offered on this item', $response->body);
        self::assertSame(0, (new CartService())->count((int) $customer['user']['id']));
    }

    public function testASoldOutItemCannotBeAdded(): void
    {
        $shop = $this->createOrderableRestaurant();
        MenuItem::update($shop['item_id'], ['in_stock' => 0]);

        $customer = $this->actingAsCustomer();

        $response = $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $shop['item_id'],
            'quantity' => 1,
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString(CartService::PROBLEM_ITEM_UNAVAILABLE, $response->body);
        self::assertSame(0, (new CartService())->count((int) $customer['user']['id']));
    }

    public function testAddingFromASecondRestaurantAsksBeforeThrowingAnythingAway(): void
    {
        $zoneId = $this->createZone();
        $tacos = $this->createRestaurantWithOwner('Taqueria Uno', $zoneId, ['hours' => $this->alwaysOpenHours()]);
        $tacoItem = $this->createItem($tacos['restaurant_id'], $this->createCategory($tacos['restaurant_id']));

        $noodles = $this->createRestaurantWithOwner('Noodle Bar', $zoneId, ['hours' => $this->alwaysOpenHours()]);
        $noodleItem = $this->createItem(
            $noodles['restaurant_id'],
            $this->createCategory($noodles['restaurant_id']),
            ['name' => 'Dan Dan']
        );

        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $tacoItem,
            'quantity' => 1,
        ]);

        $asked = $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $noodleItem,
            'quantity' => 1,
        ]);

        self::assertSame(200, $asked->status);
        self::assertStringContainsString('Start a new cart?', $asked->body);
        self::assertStringContainsString('Taqueria Uno', $asked->body);
        self::assertStringContainsString('Noodle Bar', $asked->body);

        $cart = Cart::forUser($userId);
        self::assertSame($tacos['restaurant_id'], (int) $cart['restaurant_id'], 'Nothing is thrown away by asking.');

        $confirmed = $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $noodleItem,
            'quantity' => 1,
            'replace_cart' => '1',
        ]);

        self::assertSame(302, $confirmed->status);

        $cart = Cart::forUser($userId);
        $lines = CartItem::forCart((int) $cart['id']);

        self::assertSame($noodles['restaurant_id'], (int) $cart['restaurant_id']);
        self::assertCount(1, $lines);
        self::assertSame($noodleItem, (int) $lines[0]['menu_item_id']);
    }

    public function testAddingTheSameThingTwiceBumpsTheQuantityRatherThanTheRowCount(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();

        foreach ([1, 2] as $quantity) {
            $this->post('/app/cart/items', [
                '_csrf' => $this->csrfToken(),
                'menu_item_id' => $shop['item_id'],
                'quantity' => $quantity,
            ]);
        }

        $cart = Cart::forUser((int) $customer['user']['id']);
        $lines = CartItem::forCart((int) $cart['id']);

        self::assertCount(1, $lines);
        self::assertSame(3, (int) $lines[0]['quantity']);
    }

    public function testACustomerCannotChangeAnotherCustomersCartLine(): void
    {
        $shop = $this->createOrderableRestaurant();

        $stranger = $this->actingAsCustomer(['name' => 'Someone Else']);
        $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $shop['item_id'],
            'quantity' => 1,
        ]);
        $strangerCart = Cart::forUser((int) $stranger['user']['id']);
        $strangerLine = (int) CartItem::forCart((int) $strangerCart['id'])[0]['id'];

        $this->actingAsCustomer(['name' => 'Marisol Vega']);

        $response = $this->post('/app/cart/items/' . $strangerLine . '/delete', ['_csrf' => $this->csrfToken()]);

        self::assertSame(404, $response->status);
        self::assertNotNull(CartItem::find($strangerLine));
    }

    public function testTheItemSheetAndTheItemPageServeTheSameForm(): void
    {
        $shop = $this->createOrderableRestaurant();
        $groupId = $this->createOptionGroup($shop['item_id'], 'Which size?');
        $this->createOption($groupId, 'Large', 100);

        $this->actingAsCustomer();

        $slug = (string) \Keel\App\Models\Restaurant::find($shop['restaurant_id'])['slug'];
        $url = '/app/r/' . $slug . '/items/' . $shop['item_id'];

        $page = $this->get($url);
        $sheet = $this->getJson($url);

        self::assertSame(200, $page->status);
        self::assertSame(200, $sheet->status);

        $html = (string) $sheet->json()['html'];

        self::assertSame('Al Pastor Taco', (string) $sheet->json()['name']);
        self::assertStringContainsString('Which size?', $html);
        self::assertStringContainsString('data-option-group="' . $groupId . '"', $html);
        self::assertStringContainsString($html, $page->body, 'The page embeds exactly what the sheet fetches.');
    }

    public function testAnItemFromAnotherRestaurantsMenuIsNotFoundUnderThisOnesSlug(): void
    {
        $zoneId = $this->createZone();
        $tacos = $this->createRestaurantWithOwner('Taqueria Uno', $zoneId, ['hours' => $this->alwaysOpenHours()]);
        $noodles = $this->createRestaurantWithOwner('Noodle Bar', $zoneId, ['hours' => $this->alwaysOpenHours()]);

        $noodleItem = $this->createItem(
            $noodles['restaurant_id'],
            $this->createCategory($noodles['restaurant_id']),
            ['name' => 'Dan Dan']
        );

        $this->actingAsCustomer();

        $slug = (string) \Keel\App\Models\Restaurant::find($tacos['restaurant_id'])['slug'];

        self::assertSame(404, $this->get('/app/r/' . $slug . '/items/' . $noodleItem)->status);
    }

    public function testWindingAQuantityToZeroRemovesTheLineAndForgetsTheRestaurant(): void
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $this->post('/app/cart/items', [
            '_csrf' => $this->csrfToken(),
            'menu_item_id' => $shop['item_id'],
            'quantity' => 1,
        ]);

        $cart = Cart::forUser($userId);
        $lineId = (int) CartItem::forCart((int) $cart['id'])[0]['id'];

        $this->post('/app/cart/items/' . $lineId, ['_csrf' => $this->csrfToken(), 'quantity' => 0]);

        self::assertSame(0, (new CartService())->count($userId));
        self::assertNull(Cart::forUser($userId)['restaurant_id']);
    }
}
