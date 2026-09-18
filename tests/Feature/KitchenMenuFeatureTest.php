<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\ItemOption;
use Keel\App\Models\ItemOptionGroup;
use Keel\App\Models\MenuCategory;
use Keel\App\Models\MenuItem;
use Keel\Core\Csrf;
use Tests\Support\KitchenFixtures;
use Tests\TestCase;

/**
 * The menu manager.
 *
 * Two things here are load-bearing beyond this screen. Prices are typed in
 * dollars and stored in cents, which every later phase reads; and the 86 switch
 * has to change what MenuItem::orderable() returns straight away, because that
 * is the query the customer menu runs.
 */
class KitchenMenuFeatureTest extends TestCase
{
    use KitchenFixtures;

    private int $restaurantId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $zoneId = $this->createZone();
        $created = $this->createRestaurantWithOwner('Menu Kitchen', $zoneId);

        $this->restaurantId = $created['restaurant_id'];
        $this->actingAsOwner($created['owner_id']);
        $this->categoryId = $this->createCategory($this->restaurantId);
    }

    // -- 86 -----------------------------------------------------------------

    /**
     * The spec's check: one tap, and the item is gone from the customer query.
     */
    public function testTheEightySixSwitchHidesTheItemFromTheMenuQueryImmediately(): void
    {
        $itemId = $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'Horchata']);

        self::assertContains('Horchata', array_column(MenuItem::orderable($this->restaurantId), 'name'));

        $this->post('/kitchen/menu/items/' . $itemId . '/stock', ['_csrf' => Csrf::token()]);

        self::assertNotContains('Horchata', array_column(MenuItem::orderable($this->restaurantId), 'name'));
        self::assertSame(0, (int) MenuItem::find($itemId)['in_stock']);

        // Still on the menu, just not orderable — 86 is not a delete.
        self::assertContains('Horchata', array_column(MenuItem::forRestaurant($this->restaurantId), 'name'));
    }

    public function testTheEightySixSwitchTogglesBack(): void
    {
        $itemId = $this->createItem($this->restaurantId, $this->categoryId, ['in_stock' => 0]);

        $this->post('/kitchen/menu/items/' . $itemId . '/stock', ['_csrf' => Csrf::token()]);

        self::assertSame(1, (int) MenuItem::find($itemId)['in_stock']);
    }

    public function testTheEightySixSwitchReturnsToWhicheverScreenItWasTappedFrom(): void
    {
        $itemId = $this->createItem($this->restaurantId, $this->categoryId);

        $fromBoard = $this->post('/kitchen/menu/items/' . $itemId . '/stock', [
            '_csrf' => Csrf::token(),
            'back' => '/kitchen',
        ]);

        self::assertSame('/kitchen', $fromBoard->header('Location'));
    }

    /**
     * The return path comes from a form field, so it must not be able to send
     * someone off this site.
     */
    public function testTheReturnPathCannotLeaveTheKitchen(): void
    {
        $itemId = $this->createItem($this->restaurantId, $this->categoryId);

        $response = $this->post('/kitchen/menu/items/' . $itemId . '/stock', [
            '_csrf' => Csrf::token(),
            'back' => 'https://example.com/phish',
        ]);

        self::assertSame('/kitchen/menu', $response->header('Location'));
    }

    // -- Prices -------------------------------------------------------------

    public function testAPriceTypedInDollarsIsStoredInCents(): void
    {
        $this->post('/kitchen/menu/items', [
            '_csrf' => Csrf::token(),
            'menu_category_id' => $this->categoryId,
            'name' => 'Birria Plate',
            'price' => '13.75',
        ]);

        $item = MenuItem::forCategory($this->categoryId)[0];

        self::assertSame(1375, (int) $item['price_cents']);
    }

    public function testThePriceThatBreaksFloatMathSurvives(): void
    {
        $this->post('/kitchen/menu/items', [
            '_csrf' => Csrf::token(),
            'menu_category_id' => $this->categoryId,
            'name' => 'Agua Fresca',
            'price' => '1.15',
        ]);

        self::assertSame(115, (int) MenuItem::forCategory($this->categoryId)[0]['price_cents']);
    }

    public function testAPriceThatIsNotAPriceIsRefusedWithAMessage(): void
    {
        $response = $this->post('/kitchen/menu/items', [
            '_csrf' => Csrf::token(),
            'menu_category_id' => $this->categoryId,
            'name' => 'Mystery',
            'price' => 'market rate',
        ]);

        self::assertSame(302, $response->status);
        self::assertSame([], MenuItem::forCategory($this->categoryId));
    }

    public function testAnItemPriceCannotBeNegative(): void
    {
        $this->post('/kitchen/menu/items', [
            '_csrf' => Csrf::token(),
            'menu_category_id' => $this->categoryId,
            'name' => 'Refund Taco',
            'price' => '-3.00',
        ]);

        self::assertSame([], MenuItem::forCategory($this->categoryId));
    }

    /**
     * An option may take money off, which is the one place a negative price is
     * the right answer.
     */
    public function testAnOptionMayDiscount(): void
    {
        $itemId = $this->createItem($this->restaurantId, $this->categoryId);
        $groupId = $this->createOptionGroup($itemId);

        $this->post('/kitchen/menu/groups/' . $groupId . '/options', [
            '_csrf' => Csrf::token(),
            'name' => 'No cheese',
            'price_delta' => '-0.50',
        ]);

        self::assertSame(-50, (int) ItemOption::forGroup($groupId)[0]['price_delta_cents']);
    }

    public function testAPriceComesBackIntoTheFormAsDollars(): void
    {
        $itemId = $this->createItem($this->restaurantId, $this->categoryId, ['price_cents' => 1375]);

        $body = $this->get('/kitchen/menu/items/' . $itemId)->body;

        self::assertStringContainsString('value="13.75"', $body);
    }

    // -- CRUD ---------------------------------------------------------------

    public function testTheFullCrudRound(): void
    {
        // Category
        $this->post('/kitchen/menu/categories', ['_csrf' => Csrf::token(), 'name' => 'Sides']);
        $sides = array_values(array_filter(
            MenuCategory::forRestaurant($this->restaurantId),
            static fn (array $row): bool => $row['name'] === 'Sides'
        ))[0];

        $this->post('/kitchen/menu/categories/' . (int) $sides['id'], [
            '_csrf' => Csrf::token(),
            'name' => 'Sides and Snacks',
            'active' => '1',
        ]);
        self::assertSame('Sides and Snacks', (string) MenuCategory::find((int) $sides['id'])['name']);

        // Item
        $this->post('/kitchen/menu/items', [
            '_csrf' => Csrf::token(),
            'menu_category_id' => (int) $sides['id'],
            'name' => 'Elote',
            'price' => '4.00',
        ]);
        $itemId = (int) MenuItem::forCategory((int) $sides['id'])[0]['id'];

        $this->post('/kitchen/menu/items/' . $itemId, [
            '_csrf' => Csrf::token(),
            'name' => 'Street Corn',
            'price' => '4.50',
            'active' => '1',
        ]);
        self::assertSame('Street Corn', (string) MenuItem::find($itemId)['name']);
        self::assertSame(450, (int) MenuItem::find($itemId)['price_cents']);

        // Option group and option
        $this->post('/kitchen/menu/items/' . $itemId . '/groups', [
            '_csrf' => Csrf::token(),
            'name' => 'How spicy?',
            'min_select' => '1',
            'max_select' => '1',
        ]);
        $groupId = (int) ItemOptionGroup::forItem($itemId)[0]['id'];
        self::assertSame(1, (int) ItemOptionGroup::find($groupId)['required'], 'a minimum of one makes it required');

        $this->post('/kitchen/menu/groups/' . $groupId . '/options', [
            '_csrf' => Csrf::token(),
            'name' => 'Extra hot',
            'price_delta' => '0.00',
        ]);
        $optionId = (int) ItemOption::forGroup($groupId)[0]['id'];

        $this->post('/kitchen/menu/options/' . $optionId . '/delete', ['_csrf' => Csrf::token()]);
        self::assertSame([], ItemOption::forGroup($groupId));

        $this->post('/kitchen/menu/groups/' . $groupId . '/delete', ['_csrf' => Csrf::token()]);
        self::assertSame([], ItemOptionGroup::forItem($itemId));

        $this->post('/kitchen/menu/items/' . $itemId . '/delete', ['_csrf' => Csrf::token()]);
        self::assertNull(MenuItem::find($itemId));

        $this->post('/kitchen/menu/categories/' . (int) $sides['id'] . '/delete', ['_csrf' => Csrf::token()]);
        self::assertNull(MenuCategory::find((int) $sides['id']));
    }

    public function testAnOptionGroupMinimumCannotExceedItsMaximum(): void
    {
        $itemId = $this->createItem($this->restaurantId, $this->categoryId);

        $this->post('/kitchen/menu/items/' . $itemId . '/groups', [
            '_csrf' => Csrf::token(),
            'name' => 'Pick some',
            'min_select' => '5',
            'max_select' => '2',
        ]);

        $group = ItemOptionGroup::forItem($itemId)[0];

        self::assertSame(2, (int) $group['min_select']);
        self::assertSame(2, (int) $group['max_select']);
    }

    // -- Ordering -----------------------------------------------------------

    public function testDragToSortWritesTheNewOrder(): void
    {
        $first = $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'First', 'sort' => 0]);
        $second = $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'Second', 'sort' => 1]);
        $third = $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'Third', 'sort' => 2]);

        $response = $this->postJson('/kitchen/menu/sort', [
            '_csrf' => Csrf::token(),
            'type' => 'items',
            'order' => [$third, $first, $second],
        ]);

        self::assertSame(200, $response->status);
        self::assertSame(3, $response->json()['saved']);
        self::assertSame(
            ['Third', 'First', 'Second'],
            array_column(MenuItem::forCategory($this->categoryId), 'name')
        );
    }

    public function testTheArrowsMoveOneStep(): void
    {
        $first = $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'First', 'sort' => 0]);
        $second = $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'Second', 'sort' => 1]);
        $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'Third', 'sort' => 2]);

        $this->post('/kitchen/menu/items/' . $second . '/move', ['_csrf' => Csrf::token(), 'direction' => 'up']);

        self::assertSame(
            ['Second', 'First', 'Third'],
            array_column(MenuItem::forCategory($this->categoryId), 'name')
        );

        $this->post('/kitchen/menu/items/' . $first . '/move', ['_csrf' => Csrf::token(), 'direction' => 'down']);

        self::assertSame(
            ['Second', 'Third', 'First'],
            array_column(MenuItem::forCategory($this->categoryId), 'name')
        );
    }

    public function testMovingPastTheEndDoesNothing(): void
    {
        $first = $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'First', 'sort' => 0]);
        $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'Second', 'sort' => 1]);

        $this->post('/kitchen/menu/items/' . $first . '/move', ['_csrf' => Csrf::token(), 'direction' => 'up']);

        self::assertSame(
            ['First', 'Second'],
            array_column(MenuItem::forCategory($this->categoryId), 'name')
        );
    }

    public function testANewItemLandsAtTheEnd(): void
    {
        $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'First', 'sort' => 0]);
        $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'Second', 'sort' => 1]);

        $this->post('/kitchen/menu/items', [
            '_csrf' => Csrf::token(),
            'menu_category_id' => $this->categoryId,
            'name' => 'Third',
            'price' => '1.00',
        ]);

        self::assertSame(
            ['First', 'Second', 'Third'],
            array_column(MenuItem::forCategory($this->categoryId), 'name')
        );
    }

    public function testAnUnknownSortCollectionIsRefused(): void
    {
        $response = $this->postJson('/kitchen/menu/sort', [
            '_csrf' => Csrf::token(),
            'type' => 'restaurants',
            'order' => [1],
        ]);

        self::assertSame(422, $response->status);
    }

    // -- Moving an item between categories ----------------------------------

    public function testAnItemMovesBetweenCategories(): void
    {
        $other = $this->createCategory($this->restaurantId, 'Drinks');
        $itemId = $this->createItem($this->restaurantId, $this->categoryId, ['name' => 'Horchata']);

        $this->post('/kitchen/menu/items/' . $itemId, [
            '_csrf' => Csrf::token(),
            'name' => 'Horchata',
            'price' => '3.50',
            'menu_category_id' => $other,
            'active' => '1',
        ]);

        self::assertSame($other, (int) MenuItem::find($itemId)['menu_category_id']);
        self::assertSame([], MenuItem::forCategory($this->categoryId));
    }
}
