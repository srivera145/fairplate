<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Restaurant;
use Keel\App\Models\RestaurantStaff;
use Keel\App\Models\User;
use Keel\App\Services\Geo\FakeGeocoder;
use Keel\App\Services\Geo\GeocoderFactory;
use Keel\Core\Csrf;
use Keel\Core\Database;
use Tests\Support\KitchenFixtures;
use Tests\TestCase;

/**
 * Signing a restaurant up.
 *
 * The delivery zone check is the one with consequences. A restaurant outside
 * every zone can never be dispatched to, so letting one through would produce a
 * kitchen that can take orders no driver will ever be offered — and the first
 * anyone would know of it is a customer waiting for food.
 *
 * The geocoder is faked throughout. These tests are about what happens to a
 * point once it exists, not about Google's opinion of an address.
 */
class KitchenOnboardingFeatureTest extends TestCase
{
    use KitchenFixtures;

    private int $zoneId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zoneId = $this->createZone();
    }

    protected function tearDown(): void
    {
        GeocoderFactory::swap(null);

        parent::tearDown();
    }

    public function testAnAddressOutsideEveryZoneIsRejected(): void
    {
        $this->signInAsNewOwner();
        GeocoderFactory::swap(new FakeGeocoder(self::OUTSIDE_ZONE));

        $response = $this->post('/kitchen/onboarding', $this->profileForm([
            'line1' => '1 Independent Dr',
            'city' => 'Jacksonville',
            'zip' => '32202',
        ]));

        self::assertSame(200, $response->status, 'the form comes back, it does not redirect');
        self::assertStringContainsString('outside every FairPlate delivery zone', $response->body);
        self::assertSame(0, Restaurant::count(), 'nothing was created');
    }

    public function testAnAddressThatCannotBeFoundIsRejected(): void
    {
        $this->signInAsNewOwner();
        GeocoderFactory::swap(new FakeGeocoder(null));

        $response = $this->post('/kitchen/onboarding', $this->profileForm());

        self::assertSame(200, $response->status);
        self::assertStringContainsString('could not find that address', $response->body);
        self::assertSame(0, Restaurant::count());
    }

    public function testAnAddressInsideTheZoneCreatesTheRestaurant(): void
    {
        $owner = $this->signInAsNewOwner();
        GeocoderFactory::swap(new FakeGeocoder(self::INSIDE_ZONE));

        $response = $this->post('/kitchen/onboarding', $this->profileForm());

        self::assertSame(302, $response->status);
        self::assertSame('/kitchen/onboarding', $response->header('Location'));

        $restaurant = Database::connection()->query('SELECT * FROM restaurants LIMIT 1')->fetch();

        self::assertSame('Railroad Square Tacos', $restaurant['name']);
        self::assertSame($this->zoneId, (int) $restaurant['delivery_zone_id']);
        self::assertSame('0.0750', $restaurant['tax_rate']);
        self::assertEqualsWithDelta(self::INSIDE_ZONE['lat'], (float) $restaurant['lat'], 0.00001);
        self::assertEqualsWithDelta(self::INSIDE_ZONE['lng'], (float) $restaurant['lng'], 0.00001);

        // The owner is attached, and owns it.
        $staff = RestaurantStaff::forRestaurant((int) $restaurant['id']);
        self::assertCount(1, $staff);
        self::assertSame((int) $owner['id'], (int) $staff[0]['user_id']);
        self::assertSame(1, (int) $staff[0]['is_owner']);
    }

    /**
     * The whole point of the spec line: a finished profile is still pending.
     */
    public function testANewRestaurantStaysPending(): void
    {
        $this->signInAsNewOwner();
        GeocoderFactory::swap(new FakeGeocoder(self::INSIDE_ZONE));

        $this->post('/kitchen/onboarding', $this->profileForm());

        $restaurant = Database::connection()->query('SELECT * FROM restaurants LIMIT 1')->fetch();

        self::assertSame(Restaurant::STATUS_PENDING, $restaurant['status']);
    }

    public function testTheKitchenCannotApproveItself(): void
    {
        $this->signInAsNewOwner();
        GeocoderFactory::swap(new FakeGeocoder(self::INSIDE_ZONE));

        $this->post('/kitchen/onboarding', array_merge($this->profileForm(), [
            'status' => Restaurant::STATUS_ACTIVE,
        ]));

        $restaurant = Database::connection()->query('SELECT * FROM restaurants LIMIT 1')->fetch();

        self::assertSame(Restaurant::STATUS_PENDING, $restaurant['status']);
    }

    public function testTheStartingHoursAreWrittenForEveryDay(): void
    {
        $this->signInAsNewOwner();
        GeocoderFactory::swap(new FakeGeocoder(self::INSIDE_ZONE));

        $this->post('/kitchen/onboarding', $this->profileForm(['open' => '10:30', 'close' => '21:00']));

        $restaurant = Database::connection()->query('SELECT * FROM restaurants LIMIT 1')->fetch();
        $hours = json_decode((string) $restaurant['hours'], true);

        self::assertSame([['open' => '10:30', 'close' => '21:00']], $hours['days']['mon']);
        self::assertSame([['open' => '10:30', 'close' => '21:00']], $hours['days']['sun']);
    }

    public function testTheGeocoderIsNotCalledForAFormThatIsAlreadyWrong(): void
    {
        $this->signInAsNewOwner();
        $geocoder = new FakeGeocoder(self::INSIDE_ZONE);
        GeocoderFactory::swap($geocoder);

        $response = $this->post('/kitchen/onboarding', $this->profileForm(['zip' => 'nope']));

        self::assertSame(200, $response->status);
        self::assertSame([], $geocoder->calls(), 'a bad ZIP is caught before the network call');
    }

    public function testATaxRateIsRequiredAsAPercentage(): void
    {
        $this->signInAsNewOwner();
        GeocoderFactory::swap(new FakeGeocoder(self::INSIDE_ZONE));

        $response = $this->post('/kitchen/onboarding', $this->profileForm(['tax_rate' => 'seven and a half']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('sales tax as a percentage', $response->body);
        self::assertSame(0, Restaurant::count());
    }

    public function testEditingAnExistingProfileRegeocodesAndKeepsTheZoneCheck(): void
    {
        $created = $this->createRestaurantWithOwner('Existing Kitchen', $this->zoneId);
        $this->actingAsOwner($created['owner_id']);
        GeocoderFactory::swap(new FakeGeocoder(self::OUTSIDE_ZONE));

        $response = $this->post('/kitchen/onboarding', $this->profileForm(['name' => 'Moved Away']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('outside every FairPlate delivery zone', $response->body);
        self::assertSame('Existing Kitchen', (string) Restaurant::find($created['restaurant_id'])['name']);
    }

    public function testTheOnboardingScreenIsWhereANewOwnerLands(): void
    {
        $this->signInAsNewOwner();

        $board = $this->get('/kitchen');

        self::assertSame(200, $board->status);
        self::assertStringContainsString('Start your profile', $board->body);
    }

    /**
     * @return array<string, string>
     */
    private function profileForm(array $overrides = []): array
    {
        return array_merge([
            '_csrf' => Csrf::token(),
            'name' => 'Railroad Square Tacos',
            'phone' => '(850) 555-0411',
            'line1' => '648 McDonnell Dr',
            'city' => 'Tallahassee',
            'state' => 'FL',
            'zip' => '32310',
            'tax_rate' => '7.5',
            'open' => '11:00',
            'close' => '22:00',
        ], $overrides);
    }

    private function signInAsNewOwner(): array
    {
        $owner = $this->createUser([
            'role' => User::ROLE_RESTAURANT_STAFF,
            'name' => 'Rosa Delgado',
            'phone' => '+18505550412',
        ]);

        $this->actingAsOwner((int) $owner['id']);

        return $owner;
    }
}
