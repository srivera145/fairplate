<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Address;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * The address book, and the one sentence that matters most in it.
 *
 * An address outside the delivery area has to be refused here, at the cheapest
 * possible moment, and told so plainly. Every later screen assumes an address
 * that was saved is an address FairPlate can reach.
 */
class CustomerAddressesFeatureTest extends TestCase
{
    use CustomerFixtures;

    protected function tearDown(): void
    {
        $this->restoreCollaborators();

        parent::tearDown();
    }

    public function testAnAddressInsideTheZoneIsSavedGeocodedAndMadeTheDefault(): void
    {
        $this->createZone();
        $customer = $this->actingAsCustomer();
        $this->fakeGeocoder(self::INSIDE_ZONE);

        $response = $this->post('/app/addresses', [
            '_csrf' => $this->csrfToken(),
            'label' => 'Home',
            'line1' => '415 N Monroe St',
            'city' => 'Tallahassee',
            'state' => 'FL',
            'zip' => '32301',
        ]);

        self::assertSame(302, $response->status);
        self::assertSame('/app/addresses', $response->header('Location'));

        $saved = Address::forUser((int) $customer['user']['id']);

        self::assertCount(1, $saved);
        self::assertSame('415 N Monroe St', (string) $saved[0]['line1']);
        self::assertSame(1, (int) $saved[0]['is_default'], 'The first address saved becomes the default.');
        self::assertEqualsWithDelta(self::INSIDE_ZONE['lat'], (float) $saved[0]['lat'], 0.0001);
        self::assertEqualsWithDelta(self::INSIDE_ZONE['lng'], (float) $saved[0]['lng'], 0.0001);
    }

    public function testAnAddressOutsideTheZoneIsRefusedWithTheSpecsMessageAndNotSaved(): void
    {
        $this->createZone();
        $customer = $this->actingAsCustomer();
        $this->fakeGeocoder(self::OUTSIDE_ZONE);

        $response = $this->post('/app/addresses', [
            '_csrf' => $this->csrfToken(),
            'label' => 'Work',
            'line1' => '1 Independent Dr',
            'city' => 'Jacksonville',
            'state' => 'FL',
            'zip' => '32202',
        ]);

        self::assertSame(200, $response->status, 'A refused address re-renders the form rather than redirecting.');
        self::assertStringContainsString('Not in our delivery area yet.', $response->body);
        self::assertSame([], Address::forUser((int) $customer['user']['id']));
    }

    public function testAnAddressThatCannotBeFoundIsRefusedDifferentlyFromOneOutOfZone(): void
    {
        $this->createZone();
        $customer = $this->actingAsCustomer();
        $this->fakeGeocoder(null);

        $response = $this->post('/app/addresses', [
            '_csrf' => $this->csrfToken(),
            'line1' => '999999 Nowhere Rd',
            'city' => 'Tallahassee',
            'state' => 'FL',
            'zip' => '32301',
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('could not find that address', $response->body);
        self::assertStringNotContainsString('Not in our delivery area yet.', $response->body);
        self::assertSame([], Address::forUser((int) $customer['user']['id']));
    }

    public function testAnAddressIsNeverGeocodedUntilItLooksLikeAnAddress(): void
    {
        $this->createZone();
        $this->actingAsCustomer();
        $geocoder = $this->fakeGeocoder(self::INSIDE_ZONE);

        $response = $this->post('/app/addresses', [
            '_csrf' => $this->csrfToken(),
            'line1' => '',
            'city' => '',
            'state' => 'FL',
            'zip' => 'nope',
        ]);

        self::assertSame(200, $response->status);
        self::assertSame([], $geocoder->calls(), 'A half-filled form must not cost a geocoding call.');
        self::assertStringContainsString('A street address is needed.', $response->body);
    }

    public function testPickingADefaultMovesItAndClearsTheOldOne(): void
    {
        $this->createZone();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $home = $this->createAddress($userId);
        $work = $this->createAddress($userId, ['label' => 'Work', 'line1' => '12 Pine St']);

        $this->post('/app/addresses/' . $work . '/default', ['_csrf' => $this->csrfToken()]);

        self::assertSame(0, (int) Address::find($home)['is_default']);
        self::assertSame(1, (int) Address::find($work)['is_default']);
        self::assertSame($work, (int) Address::defaultForUser($userId)['id']);
    }

    public function testDeletingTheDefaultHandsTheFlagToWhatIsLeft(): void
    {
        $this->createZone();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $home = $this->createAddress($userId);
        $work = $this->createAddress($userId, ['label' => 'Work', 'line1' => '12 Pine St']);
        Address::makeDefault($userId, $home);

        $this->post('/app/addresses/' . $home . '/delete', ['_csrf' => $this->csrfToken()]);

        self::assertNull(Address::find($home));
        self::assertSame($work, (int) Address::defaultForUser($userId)['id']);
    }

    public function testACustomerCannotTouchAnotherCustomersAddress(): void
    {
        $this->createZone();
        $stranger = $this->actingAsCustomer(['name' => 'Someone Else']);
        $strangerAddress = $this->createAddress((int) $stranger['user']['id']);

        $this->actingAsCustomer(['name' => 'Marisol Vega']);

        self::assertSame(404, $this->get('/app/addresses/' . $strangerAddress . '/edit')->status);
        self::assertSame(
            404,
            $this->post('/app/addresses/' . $strangerAddress . '/delete', ['_csrf' => $this->csrfToken()])->status
        );
        self::assertNotNull(Address::find($strangerAddress), 'The address must still be there.');
    }
}
