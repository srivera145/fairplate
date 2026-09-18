<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\User;
use Keel\App\Services\Settings;
use Keel\Core\Database;
use Tests\Support\DriverFixtures;
use Tests\TestCase;

/**
 * Signing up, waiting to be approved, and the switch at the end of it.
 *
 * The thread running through all of it is that a driver record is not
 * permission to work. A driver can fill in every field, connect Stripe and open
 * the app, and still be unable to take an offer until an admin says so — which
 * is the spec's rule, and is checked here at the point it actually bites rather
 * than only where it is displayed.
 */
class DriverOnboardingFeatureTest extends TestCase
{
    use DriverFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDispatchSettings();
    }

    protected function tearDown(): void
    {
        $this->restoreCollaborators();

        parent::tearDown();
    }

    /**
     * A driver who has never filled anything in still gets a page, not a
     * redirect and not a 500.
     */
    public function testANewDriverIsInvitedToSetUp(): void
    {
        $this->actingAsUnregisteredDriver();

        $response = $this->get('/drive');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Drive with FairPlate', $response->body);
        self::assertStringContainsString('/drive/onboarding', $response->body);
    }

    /**
     * The sign-up screen does not depend on settings it is not going to use.
     *
     * A fresh deployment has no settings rows, Settings throws rather than
     * inventing a value, and the first thing a new driver does is open /drive.
     * If the chrome reads a ping interval, that driver meets a 500 on the one
     * screen whose entire job is to tell them what to do next.
     */
    public function testTheSignUpScreenRendersOnADeploymentWithNoSettings(): void
    {
        Database::connection()->exec('TRUNCATE TABLE settings');
        Settings::flush();

        $this->actingAsUnregisteredDriver();

        $response = $this->get('/drive');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Drive with FairPlate', $response->body);
    }

    public function testSubmittingTheFormCreatesAPendingDriver(): void
    {
        $user = $this->actingAsUnregisteredDriver();

        $response = $this->post('/drive/onboarding', [
            '_csrf' => $this->csrfToken(),
            'name' => 'Dana Ruiz',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Corolla',
            'vehicle_color' => 'Silver',
            'plate' => 'leon421',
        ]);

        self::assertSame(302, $response->status);

        $driver = Driver::forUser((int) $user['id']);

        self::assertNotNull($driver);
        self::assertSame(0, (int) $driver['approved'], 'a new driver starts unapproved');
        self::assertSame(0, (int) $driver['online'], 'and offline');
        self::assertSame('Toyota', (string) $driver['vehicle_make']);
        self::assertSame('LEON421', (string) $driver['plate'], 'plates are stored upper case');
        self::assertSame('Dana Ruiz', (string) User::find((int) $user['id'])['name']);
    }

    public function testTheFormRefusesToSaveWithoutAName(): void
    {
        $user = $this->actingAsUnregisteredDriver();

        $this->post('/drive/onboarding', [
            '_csrf' => $this->csrfToken(),
            'name' => '',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Corolla',
        ]);

        self::assertNull(Driver::forUser((int) $user['id']));
    }

    /**
     * The pending screen says what has been done and who is holding it, rather
     * than showing a switch that would do nothing.
     */
    public function testAnUnapprovedDriverSeesTheirStatusAndNoSwitch(): void
    {
        $driverId = $this->createDispatchableDriver('Pending Pat', null, null, [
            'approved' => 0,
            'online' => 0,
        ]);
        $this->actingAsDriver($driverId);

        $response = $this->get('/drive');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Pending approval', $response->body);
        self::assertStringNotContainsString('Tap to go online', $response->body);
    }

    /**
     * And the switch refuses them even if the form is posted directly.
     */
    public function testAnUnapprovedDriverCannotGoOnline(): void
    {
        $driverId = $this->createDispatchableDriver('Pending Pat', null, null, [
            'approved' => 0,
            'online' => 0,
        ]);
        $this->actingAsDriver($driverId);

        $response = $this->post('/drive/online', [
            '_csrf' => $this->csrfToken(),
            'online' => '1',
        ]);

        self::assertSame(403, $response->status);
        self::assertSame(0, (int) Driver::find($driverId)['online']);
    }

    public function testAnApprovedDriverCanGoOnlineAndOffline(): void
    {
        $driverId = $this->createDispatchableDriver('Dana', null, null, ['online' => 0]);
        $this->actingAsDriver($driverId);

        $this->post('/drive/online', ['_csrf' => $this->csrfToken(), 'online' => '1']);
        self::assertSame(1, (int) Driver::find($driverId)['online']);

        $this->post('/drive/online', ['_csrf' => $this->csrfToken(), 'online' => '0']);
        self::assertSame(0, (int) Driver::find($driverId)['online']);
    }

    /**
     * Going offline mid-run is refused. The customer is watching a map and the
     * food is in the car either way.
     */
    public function testADriverCarryingAnOrderCannotGoOffline(): void
    {
        $zoneId = $this->createZone();
        $restaurantId = $this->createRestaurantWithOwner('Taqueria Uno', $zoneId)['restaurant_id'];
        $driverId = $this->createDispatchableDriver();
        $this->actingAsDriver($driverId);

        $order = $this->createPricedOrder($restaurantId);
        $this->acceptOrder($order['order_id']);
        $this->drainQueue();

        $offer = $this->pendingOffer($order['order_id']);
        (new \Keel\App\Services\Dispatch\DispatchService())->accept((int) $offer['id'], $driverId);

        $this->post('/drive/online', ['_csrf' => $this->csrfToken(), 'online' => '0']);

        self::assertSame(1, (int) Driver::find($driverId)['online'], 'a driver mid-run stays online');
        self::assertSame(Order::STATUS_DRIVER_ASSIGNED, $this->orderStatus($order['order_id']));
    }

    /**
     * An admin is the one who clears a driver, and it is the only thing that
     * turns a pending record into a working one.
     */
    public function testAnAdminCanApproveAPendingDriver(): void
    {
        $driverId = $this->createDispatchableDriver('Pending Pat', null, null, ['approved' => 0]);

        $this->actingAsRole(User::ROLE_ADMIN);

        $listing = $this->get('/admin');
        self::assertStringContainsString('Pending Pat', $listing->body);

        $this->post('/admin/drivers/' . $driverId . '/approve', ['_csrf' => $this->csrfToken()]);

        self::assertSame(1, (int) Driver::find($driverId)['approved']);
    }
}
