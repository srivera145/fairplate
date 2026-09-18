<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Restaurant;
use Keel\App\Services\RestaurantHours;
use Keel\Core\Csrf;
use Keel\Core\Database;
use Tests\Support\KitchenFixtures;
use Tests\TestCase;

/**
 * The hours editor and the Pause Orders switch.
 *
 * The pause is the interesting one. There is no scheduler in this application,
 * so "pause for 15 minutes" cannot be a job that fires later; it is a stored
 * deadline, and every read compares against it. These tests check the property
 * that actually matters — that a restaurant is taking orders again after the
 * deadline without anything having run in between.
 */
class KitchenHoursFeatureTest extends TestCase
{
    use KitchenFixtures;

    private int $restaurantId;

    protected function setUp(): void
    {
        parent::setUp();

        $zoneId = $this->createZone();
        $created = $this->createRestaurantWithOwner('Hours Kitchen', $zoneId);

        $this->restaurantId = $created['restaurant_id'];
        $this->actingAsOwner($created['owner_id']);
    }

    // -- Pause --------------------------------------------------------------

    public function testPausingForFifteenMinutesStopsOrders(): void
    {
        $response = $this->post('/kitchen/pause', ['_csrf' => Csrf::token(), 'minutes' => '15']);

        self::assertSame(302, $response->status);

        $restaurant = Restaurant::find($this->restaurantId);

        self::assertTrue(Restaurant::isPaused($restaurant));
        self::assertSame(15, Restaurant::pauseMinutesLeft($restaurant));
        self::assertSame([], Restaurant::orderable(), 'and the customer app cannot see it');
    }

    /**
     * The spec's check, with no job runner involved.
     */
    public function testAFifteenMinutePauseResumesOnItsOwn(): void
    {
        $this->post('/kitchen/pause', ['_csrf' => Csrf::token(), 'minutes' => '15']);

        self::assertTrue(Restaurant::isPaused(Restaurant::find($this->restaurantId)));

        $this->travelPastThePauseDeadline();

        $restaurant = Restaurant::find($this->restaurantId);

        self::assertFalse(Restaurant::isPaused($restaurant), 'the deadline passed, so the pause is over');
        self::assertNull(Restaurant::pauseMinutesLeft($restaurant));
        self::assertCount(1, Restaurant::orderable(), 'customers can order again');
    }

    /**
     * The stored flag catches up the next time the kitchen opens a screen, so
     * the row does not sit there claiming to be paused forever.
     */
    public function testTheExpiredFlagIsClearedWhenTheKitchenNextLooks(): void
    {
        $this->post('/kitchen/pause', ['_csrf' => Csrf::token(), 'minutes' => '15']);
        $this->travelPastThePauseDeadline();

        $response = $this->get('/kitchen/hours');

        self::assertSame(200, $response->status);

        $restaurant = Restaurant::find($this->restaurantId);

        self::assertSame(0, (int) $restaurant['paused']);
        self::assertNull($restaurant['paused_until']);
        self::assertStringNotContainsString('Orders are paused', $response->body);
    }

    public function testAPauseUntilResumedHasNoDeadline(): void
    {
        $this->post('/kitchen/pause', ['_csrf' => Csrf::token(), 'minutes' => 'until_resumed']);

        $restaurant = Restaurant::find($this->restaurantId);

        self::assertTrue(Restaurant::isPaused($restaurant));
        self::assertNull($restaurant['paused_until']);
        self::assertNull(Restaurant::pauseMinutesLeft($restaurant));

        // And it does not lift itself, however long anyone waits.
        Database::connection()->exec(
            'UPDATE restaurants SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = ' . $this->restaurantId
        );

        self::assertTrue(Restaurant::isPaused(Restaurant::find($this->restaurantId)));
    }

    public function testResumingTakesOrdersAgain(): void
    {
        $this->post('/kitchen/pause', ['_csrf' => Csrf::token(), 'minutes' => 'until_resumed']);

        $response = $this->post('/kitchen/resume', ['_csrf' => Csrf::token()]);

        self::assertSame(302, $response->status);
        self::assertFalse(Restaurant::isPaused(Restaurant::find($this->restaurantId)));
        self::assertCount(1, Restaurant::orderable());
    }

    public function testOnlyTheOfferedPauseLengthsAreAccepted(): void
    {
        $this->post('/kitchen/pause', ['_csrf' => Csrf::token(), 'minutes' => '90']);

        self::assertFalse(Restaurant::isPaused(Restaurant::find($this->restaurantId)));
    }

    public function testEveryOfferedPauseLengthWorks(): void
    {
        foreach (Restaurant::PAUSE_MINUTES as $minutes) {
            $this->post('/kitchen/pause', ['_csrf' => Csrf::token(), 'minutes' => (string) $minutes]);

            self::assertSame(
                $minutes,
                Restaurant::pauseMinutesLeft(Restaurant::find($this->restaurantId)),
                "pausing for {$minutes} minutes"
            );

            $this->post('/kitchen/resume', ['_csrf' => Csrf::token()]);
        }
    }

    public function testThePauseSwitchIsOnTheBoardAsWellAsTheHoursScreen(): void
    {
        $board = $this->get('/kitchen')->body;

        self::assertStringContainsString('action="/kitchen/pause"', $board);

        $this->post('/kitchen/pause', ['_csrf' => Csrf::token(), 'minutes' => '30']);

        $paused = $this->get('/kitchen')->body;

        self::assertStringContainsString('Orders are paused', $paused);
        self::assertStringContainsString('action="/kitchen/resume"', $paused);
    }

    // -- Hours --------------------------------------------------------------

    public function testADayMaySplitIntoSeveralRanges(): void
    {
        $response = $this->post('/kitchen/hours', [
            '_csrf' => Csrf::token(),
            'open' => ['mon' => ['11:00', '17:00', '']],
            'close' => ['mon' => ['14:00', '22:00', '']],
        ]);

        self::assertSame(302, $response->status);

        $hours = RestaurantHours::normalize(Restaurant::find($this->restaurantId)['hours']);

        self::assertSame(
            [['open' => '11:00', 'close' => '14:00'], ['open' => '17:00', 'close' => '22:00']],
            $hours['days']['mon']
        );
    }

    public function testAHolidayClosureIsSaved(): void
    {
        $this->post('/kitchen/hours', [
            '_csrf' => Csrf::token(),
            'open' => ['thu' => ['11:00']],
            'close' => ['thu' => ['22:00']],
            'closure_date' => ['2026-11-26'],
            'closure_label' => ['Thanksgiving'],
        ]);

        $hours = RestaurantHours::normalize(Restaurant::find($this->restaurantId)['hours']);

        self::assertSame([['date' => '2026-11-26', 'label' => 'Thanksgiving']], $hours['closures']);
        self::assertFalse(RestaurantHours::isOpenAt(
            $hours,
            new \DateTimeImmutable('2026-11-26 12:00', new \DateTimeZone('America/New_York'))
        ));
    }

    public function testClearingADayClosesIt(): void
    {
        $this->post('/kitchen/hours', [
            '_csrf' => Csrf::token(),
            'open' => ['mon' => ['11:00'], 'tue' => ['']],
            'close' => ['mon' => ['22:00'], 'tue' => ['']],
        ]);

        $hours = RestaurantHours::normalize(Restaurant::find($this->restaurantId)['hours']);

        self::assertSame([], $hours['days']['tue']);
        self::assertSame('Closed', RestaurantHours::describeDay($hours['days']['tue']));
    }

    public function testAHalfFilledRangeComesBackAsAnError(): void
    {
        $response = $this->post('/kitchen/hours', [
            '_csrf' => Csrf::token(),
            'open' => ['fri' => ['11:00']],
            'close' => ['fri' => ['']],
        ]);

        self::assertSame(200, $response->status, 'the form comes back, it does not redirect');
        self::assertStringContainsString('Your hours were not saved', $response->body);
        self::assertNull(Restaurant::find($this->restaurantId)['hours']);
    }

    public function testTheEditorShowsWhatWasSaved(): void
    {
        $this->post('/kitchen/hours', [
            '_csrf' => Csrf::token(),
            'open' => ['sat' => ['09:30']],
            'close' => ['sat' => ['23:00']],
        ]);

        $body = $this->get('/kitchen/hours')->body;

        self::assertStringContainsString('value="09:30"', $body);
        self::assertStringContainsString('9:30am–11:00pm', $body);
    }

    /**
     * Moves the stored deadline into the past, which is what the clock would
     * have done on its own fifteen minutes later.
     */
    private function travelPastThePauseDeadline(): void
    {
        Database::connection()->exec(
            'UPDATE restaurants
             SET paused_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
             WHERE id = ' . $this->restaurantId
        );
    }
}
