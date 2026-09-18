<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Controllers\Kitchen\SpecialsController;
use Keel\App\Models\Special;
use Keel\Core\Csrf;
use Tests\Support\KitchenFixtures;
use Tests\TestCase;

/**
 * Specials, and the preview of how each one reads.
 *
 * A special is three fields that interact, and the sentence they produce is the
 * part an owner is actually deciding about. So the preview wording is tested as
 * a thing in its own right, not just the columns behind it.
 */
class KitchenSpecialsFeatureTest extends TestCase
{
    use KitchenFixtures;

    private int $restaurantId;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();

        $zoneId = $this->createZone();
        $created = $this->createRestaurantWithOwner('Specials Kitchen', $zoneId);

        $this->restaurantId = $created['restaurant_id'];
        $this->actingAsOwner($created['owner_id']);

        $categoryId = $this->createCategory($this->restaurantId);
        $this->itemId = $this->createItem($this->restaurantId, $categoryId, ['name' => 'Horchata']);
    }

    public function testAPercentSpecialStoresAFractionNotAPercentage(): void
    {
        $this->post('/kitchen/specials', [
            '_csrf' => Csrf::token(),
            'title' => 'Taco Tuesday',
            'type' => Special::TYPE_PERCENT,
            'value' => '20',
            'days' => ['2'],
            'active' => '1',
        ]);

        $special = Special::forRestaurant($this->restaurantId)[0];

        self::assertSame('0.2000', $special['value_pct']);
        self::assertNull($special['value_cents']);
        self::assertSame([2], json_decode((string) $special['days'], true));
    }

    public function testADollarSpecialStoresCents(): void
    {
        $this->post('/kitchen/specials', [
            '_csrf' => Csrf::token(),
            'title' => 'Two dollars off',
            'type' => Special::TYPE_AMOUNT,
            'value' => '2.00',
        ]);

        $special = Special::forRestaurant($this->restaurantId)[0];

        self::assertSame(200, (int) $special['value_cents']);
        self::assertNull($special['value_pct']);
    }

    public function testAFixedPriceSpecialHasToNameAnItem(): void
    {
        $response = $this->post('/kitchen/specials', [
            '_csrf' => Csrf::token(),
            'title' => 'Dollar drink',
            'type' => Special::TYPE_PRICE,
            'value' => '1.00',
            'menu_item_id' => '0',
        ]);

        self::assertSame(302, $response->status);
        self::assertSame([], Special::forRestaurant($this->restaurantId));
    }

    public function testTheTimeWindowIsStoredWhenGiven(): void
    {
        $this->post('/kitchen/specials', [
            '_csrf' => Csrf::token(),
            'title' => 'Late lunch horchata',
            'type' => Special::TYPE_PRICE,
            'value' => '1.00',
            'menu_item_id' => (string) $this->itemId,
            'days' => ['1', '2', '3', '4', '5'],
            'start_time' => '14:00',
            'end_time' => '16:00',
            'active' => '1',
        ]);

        $special = Special::forRestaurant($this->restaurantId)[0];

        self::assertSame('14:00:00', $special['start_time']);
        self::assertSame('16:00:00', $special['end_time']);
        self::assertTrue(Special::runsAt($special, 2, '15:00:00'));
        self::assertFalse(Special::runsAt($special, 2, '17:00:00'));
        self::assertFalse(Special::runsAt($special, 6, '15:00:00'));
    }

    /**
     * The sentence the customer will read, for each shape of special.
     */
    public function testThePreviewReadsTheWayTheCustomerMenuWill(): void
    {
        self::assertSame(
            '20% off your order',
            SpecialsController::describe(['type' => Special::TYPE_PERCENT, 'value_pct' => '0.2000', 'title' => 'x'])
        );

        self::assertSame(
            '20% off Horchata',
            SpecialsController::describe(
                ['type' => Special::TYPE_PERCENT, 'value_pct' => '0.2000', 'title' => 'x'],
                'Horchata'
            )
        );

        self::assertSame(
            '$2.00 off your order',
            SpecialsController::describe(['type' => Special::TYPE_AMOUNT, 'value_cents' => 200, 'title' => 'x'])
        );

        self::assertSame(
            'Horchata for $1.00',
            SpecialsController::describe(
                ['type' => Special::TYPE_PRICE, 'value_cents' => 100, 'title' => 'x'],
                'Horchata'
            )
        );
    }

    public function testThePreviewIsOnTheScreen(): void
    {
        $this->post('/kitchen/specials', [
            '_csrf' => Csrf::token(),
            'title' => 'Taco Tuesday',
            'type' => Special::TYPE_PERCENT,
            'value' => '20',
            'days' => ['2'],
            'active' => '1',
        ]);

        $body = $this->get('/kitchen/specials')->body;

        self::assertStringContainsString('20% off your order', $body, 'the saved special reads back');
        self::assertStringContainsString('Tue', $body, 'and when it runs');
        self::assertStringContainsString('data-special-preview', $body, 'the live preview is wired up');
    }

    public function testTheDayLabelSaysEveryDayWhenNoDaysArePicked(): void
    {
        self::assertSame('Every day', SpecialsController::dayLabels(['days' => null]));
        self::assertSame('Mon, Wed, Fri', SpecialsController::dayLabels(['days' => '[1,3,5]']));
        self::assertSame(
            'Tue, 14:00–16:00',
            SpecialsController::dayLabels(['days' => '[2]', 'start_time' => '14:00:00', 'end_time' => '16:00:00'])
        );
    }

    public function testEditingAndDeleting(): void
    {
        $this->post('/kitchen/specials', [
            '_csrf' => Csrf::token(),
            'title' => 'Taco Tuesday',
            'type' => Special::TYPE_PERCENT,
            'value' => '20',
            'active' => '1',
        ]);

        $specialId = (int) Special::forRestaurant($this->restaurantId)[0]['id'];

        $this->post('/kitchen/specials/' . $specialId, [
            '_csrf' => Csrf::token(),
            'title' => 'Taco Wednesday',
            'type' => Special::TYPE_PERCENT,
            'value' => '25',
            'days' => ['3'],
        ]);

        $special = Special::find($specialId);

        self::assertSame('Taco Wednesday', (string) $special['title']);
        self::assertSame('0.2500', $special['value_pct']);
        self::assertSame(0, (int) $special['active'], 'the unchecked box turns it off');

        $this->post('/kitchen/specials/' . $specialId . '/delete', ['_csrf' => Csrf::token()]);

        self::assertNull(Special::find($specialId));
    }

    public function testAnAmountThatIsNotAnAmountIsRefused(): void
    {
        $this->post('/kitchen/specials', [
            '_csrf' => Csrf::token(),
            'title' => 'Half off maybe',
            'type' => Special::TYPE_AMOUNT,
            'value' => 'a bit',
        ]);

        self::assertSame([], Special::forRestaurant($this->restaurantId));
    }

    public function testAnUnknownKindOfSpecialIsRefused(): void
    {
        $this->post('/kitchen/specials', [
            '_csrf' => Csrf::token(),
            'title' => 'Free food',
            'type' => 'whatever',
            'value' => '1',
        ]);

        self::assertSame([], Special::forRestaurant($this->restaurantId));
    }
}
