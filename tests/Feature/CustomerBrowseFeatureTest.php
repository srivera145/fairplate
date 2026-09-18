<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\MenuItem;
use Keel\App\Models\Restaurant;
use Keel\App\Models\Special;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * Browse: open first, nearest first, closed at the bottom saying when.
 */
class CustomerBrowseFeatureTest extends TestCase
{
    use CustomerFixtures;

    /** Downtown, and roughly two miles north-east of it. */
    private const NEARBY = ['lat' => 30.4400, 'lng' => -84.2810];
    private const FURTHER = ['lat' => 30.4700, 'lng' => -84.3100];

    protected function tearDown(): void
    {
        $this->restoreCollaborators();

        parent::tearDown();
    }

    public function testOpenRestaurantsComeFirstSortedByDistance(): void
    {
        $zoneId = $this->createZone();
        $customer = $this->actingAsCustomer();
        $this->createAddress((int) $customer['user']['id']);

        $far = $this->createRestaurantWithOwner('Far Noodles', $zoneId, [
            'hours' => $this->alwaysOpenHours(),
            'lat' => (string) self::FURTHER['lat'],
            'lng' => (string) self::FURTHER['lng'],
        ]);
        $near = $this->createRestaurantWithOwner('Near Tacos', $zoneId, [
            'hours' => $this->alwaysOpenHours(),
            'lat' => (string) self::NEARBY['lat'],
            'lng' => (string) self::NEARBY['lng'],
        ]);

        $body = $this->get('/app')->body;

        $nearAt = strpos($body, 'Near Tacos');
        $farAt = strpos($body, 'Far Noodles');

        self::assertIsInt($nearAt);
        self::assertIsInt($farAt);
        self::assertLessThan($farAt, $nearAt, 'The nearer restaurant is listed first.');
        self::assertNotSame(0, $near['restaurant_id'] + $far['restaurant_id']);
    }

    public function testAClosedRestaurantSitsBelowTheOpenOnesAndSaysWhenItOpens(): void
    {
        $zoneId = $this->createZone();
        $customer = $this->actingAsCustomer();
        $this->createAddress((int) $customer['user']['id']);

        $this->createRestaurantWithOwner('Open All Hours', $zoneId, [
            'hours' => $this->alwaysOpenHours(),
        ]);

        // Open around the clock, but shut today and tomorrow. Closing by holiday
        // rather than by hours keeps the test the same answer whatever day and
        // hour it runs at.
        $zone = new \DateTimeZone('America/New_York');
        $today = new \DateTimeImmutable('now', $zone);
        $closedHours = json_decode($this->alwaysOpenHours(), true);
        $closedHours['closures'] = [
            ['date' => $today->format('Y-m-d'), 'label' => 'Closed today'],
            ['date' => $today->modify('+1 day')->format('Y-m-d'), 'label' => 'Closed tomorrow'],
        ];

        $this->createRestaurantWithOwner('Back Thursday', $zoneId, [
            'hours' => (string) json_encode($closedHours),
        ]);

        $body = $this->get('/app')->body;

        self::assertStringContainsString('Open now', $body);
        self::assertStringContainsString('Closed right now', $body);
        self::assertStringContainsString(
            'Closed — opens ' . $today->modify('+2 days')->format('l'),
            $body,
            'A closed card has to say when it opens, not just that it is closed.'
        );

        $openAt = strpos($body, 'Open All Hours');
        $closedAt = strpos($body, 'Back Thursday');

        self::assertLessThan($closedAt, $openAt);
    }

    public function testSearchMatchesARestaurantByTheNameOfADishItSells(): void
    {
        $zoneId = $this->createZone();
        $customer = $this->actingAsCustomer();
        $this->createAddress((int) $customer['user']['id']);

        $tacos = $this->createRestaurantWithOwner('Casa Verde', $zoneId, ['hours' => $this->alwaysOpenHours()]);
        $categoryId = $this->createCategory($tacos['restaurant_id']);
        $this->createItem($tacos['restaurant_id'], $categoryId, ['name' => 'Birria Ramen']);

        $this->createRestaurantWithOwner('Blue Diner', $zoneId, ['hours' => $this->alwaysOpenHours()]);

        $body = $this->get('/app?q=ramen')->body;

        self::assertStringContainsString('Casa Verde', $body);
        self::assertStringNotContainsString('Blue Diner', $body);
    }

    public function testACardCarriesASpecialBadgeOnlyWhileASpecialIsRunning(): void
    {
        $zoneId = $this->createZone();
        $customer = $this->actingAsCustomer();
        $this->createAddress((int) $customer['user']['id']);

        $created = $this->createRestaurantWithOwner('Deal Street', $zoneId, ['hours' => $this->alwaysOpenHours()]);

        self::assertStringNotContainsString('Special', $this->get('/app')->body);

        Special::create([
            'restaurant_id' => $created['restaurant_id'],
            'title' => 'Ten off',
            'type' => Special::TYPE_PERCENT,
            'value_pct' => '0.1000',
            'active' => 1,
        ]);

        self::assertStringContainsString('Special', $this->get('/app')->body);
    }

    public function testARestaurantInAnotherZoneIsNotOffered(): void
    {
        $zoneId = $this->createZone();
        $customer = $this->actingAsCustomer();
        $this->createAddress((int) $customer['user']['id']);

        $this->createRestaurantWithOwner('In Zone', $zoneId, ['hours' => $this->alwaysOpenHours()]);
        $this->createRestaurantWithOwner('Other Zone', null, ['hours' => $this->alwaysOpenHours()]);

        $body = $this->get('/app')->body;

        self::assertStringContainsString('In Zone', $body);
        self::assertStringNotContainsString('Other Zone', $body);
    }

    public function testACustomerWithNoAddressIsAskedForOneRatherThanShownAGuess(): void
    {
        $zoneId = $this->createZone();
        $this->actingAsCustomer();
        $this->createRestaurantWithOwner('Somewhere', $zoneId, ['hours' => $this->alwaysOpenHours()]);

        $body = $this->get('/app')->body;

        self::assertStringContainsString('Where are we delivering?', $body);
        self::assertStringNotContainsString('Somewhere', $body);
    }

    public function testAnOutOfStockItemIsShownDisabledRatherThanHidden(): void
    {
        $zoneId = $this->createZone();
        $customer = $this->actingAsCustomer();
        $this->createAddress((int) $customer['user']['id']);

        $created = $this->createRestaurantWithOwner('Sold Out Cafe', $zoneId, ['hours' => $this->alwaysOpenHours()]);
        $categoryId = $this->createCategory($created['restaurant_id']);
        $itemId = $this->createItem($created['restaurant_id'], $categoryId, ['name' => 'Last Taco']);
        MenuItem::update($itemId, ['in_stock' => 0]);

        $restaurant = Restaurant::find($created['restaurant_id']);
        $body = $this->get('/app/r/' . $restaurant['slug'])->body;

        self::assertStringContainsString('Last Taco', $body);
        self::assertStringContainsString('Sold out', $body);
        self::assertStringContainsString('menu-row-out', $body);
        self::assertStringNotContainsString('/items/' . $itemId . '"', $body, 'A sold-out row is not a link.');
    }
}
