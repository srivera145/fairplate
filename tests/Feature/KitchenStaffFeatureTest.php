<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\RestaurantStaff;
use Keel\App\Models\User;
use Keel\App\Services\PhoneOtpService;
use Keel\Core\Csrf;
use Tests\Support\KitchenFixtures;
use Tests\TestCase;

/**
 * Inviting staff by phone number.
 *
 * The invitation is the attachment — there is no link to click, because sign-in
 * is already a phone number and a texted code. So the test that matters is that
 * the invited number can sign in and lands in this kitchen and no other.
 */
class KitchenStaffFeatureTest extends TestCase
{
    use KitchenFixtures;

    private int $restaurantId;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $zoneId = $this->createZone();
        $created = $this->createRestaurantWithOwner('Staff Kitchen', $zoneId);

        $this->restaurantId = $created['restaurant_id'];
        $this->ownerId = $created['owner_id'];

        $this->actingAsOwner($this->ownerId);
    }

    public function testInvitingANewNumberCreatesAKitchenAccount(): void
    {
        $response = $this->post('/kitchen/staff', [
            '_csrf' => Csrf::token(),
            'phone' => '(850) 555-0199',
            'name' => 'Wes Okafor',
        ]);

        self::assertSame(302, $response->status);

        $invited = User::findByPhone('+18505550199');

        self::assertNotNull($invited);
        self::assertSame(User::ROLE_RESTAURANT_STAFF, (string) $invited['role']);
        self::assertSame('Wes Okafor', (string) $invited['name']);
        self::assertTrue(RestaurantStaff::isStaffOf((int) $invited['id'], $this->restaurantId));
        self::assertSame(0, (int) RestaurantStaff::forRestaurant($this->restaurantId)[1]['is_owner']);
    }

    /**
     * The whole point of inviting them: they can get in, and only here.
     */
    public function testAnInvitedNumberCanSignInAndLandsInThisKitchen(): void
    {
        $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => '8505550199']);

        $otp = new PhoneOtpService();
        $otp->requestCode('8505550199');

        preg_match('/code: (\d{6})/', $this->latestSmsLog(), $matches);
        self::assertNotEmpty($matches, 'a code was texted');

        $result = $otp->verifyCode('8505550199', $matches[1]);

        self::assertTrue($result['success']);
        self::assertSame('/kitchen', User::homePath($result['user']));

        // And their kitchen is this one.
        $restaurants = RestaurantStaff::restaurantsForUser((int) $result['user']['id']);
        self::assertCount(1, $restaurants);
        self::assertSame($this->restaurantId, (int) $restaurants[0]['id']);
    }

    public function testTheInvitedPersonGetsATextTellingThemSo(): void
    {
        $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => '8505550199']);

        $log = $this->latestSmsLog();

        self::assertStringContainsString('+18505550199', $log);
        self::assertStringContainsString('Staff Kitchen', $log);
    }

    /**
     * A number already in use as a customer or a driver is refused rather than
     * converted — their role decides which app they land in, and taking that
     * away because a manager mistyped a digit is not a recoverable mistake.
     */
    public function testANumberBelongingToAnotherKindOfAccountIsRefused(): void
    {
        $driver = $this->createUser([
            'role' => User::ROLE_DRIVER,
            'phone' => '+18505550177',
            'name' => 'Dee Rowan',
        ]);

        $response = $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => '8505550177']);

        self::assertSame(302, $response->status);
        self::assertSame(User::ROLE_DRIVER, (string) User::find((int) $driver['id'])['role']);
        self::assertFalse(RestaurantStaff::isStaffOf((int) $driver['id'], $this->restaurantId));
    }

    public function testAnExistingKitchenAccountIsAttachedRatherThanDuplicated(): void
    {
        $other = $this->createRestaurantWithOwner('Other Kitchen');
        $existing = User::find($other['owner_id']);

        $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => (string) $existing['phone']]);

        self::assertTrue(RestaurantStaff::isStaffOf($other['owner_id'], $this->restaurantId));
        self::assertTrue(RestaurantStaff::isStaffOf($other['owner_id'], $other['restaurant_id']));
        self::assertSame($other['owner_id'], (int) User::findByPhone((string) $existing['phone'])['id']);
    }

    public function testInvitingSomeoneTwiceIsHarmless(): void
    {
        $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => '8505550199']);
        $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => '8505550199']);

        self::assertCount(2, RestaurantStaff::forRestaurant($this->restaurantId), 'the owner and one other');
    }

    public function testANumberThatIsNotANumberIsRefused(): void
    {
        $response = $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => 'call the shop']);

        self::assertSame(302, $response->status);
        self::assertCount(1, RestaurantStaff::forRestaurant($this->restaurantId));
    }

    public function testRemovingSomeoneEndsTheirAccessToThisKitchen(): void
    {
        $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => '8505550199']);
        $invitedId = (int) User::findByPhone('+18505550199')['id'];
        $rowId = (int) RestaurantStaff::forRestaurant($this->restaurantId)[1]['id'];

        $this->post('/kitchen/staff/' . $rowId . '/delete', ['_csrf' => Csrf::token()]);

        self::assertFalse(RestaurantStaff::isStaffOf($invitedId, $this->restaurantId));
        self::assertSame([], RestaurantStaff::restaurantsForUser($invitedId));
    }

    public function testTheOwnerCannotBeRemovedFromTheirOwnRestaurant(): void
    {
        $ownerRowId = (int) RestaurantStaff::forRestaurant($this->restaurantId)[0]['id'];

        $this->post('/kitchen/staff/' . $ownerRowId . '/delete', ['_csrf' => Csrf::token()]);

        self::assertTrue(RestaurantStaff::isStaffOf($this->ownerId, $this->restaurantId));
    }

    /**
     * Staff can work the tablet. Hiring is the owner's job.
     */
    public function testANonOwnerCannotInviteOrRemove(): void
    {
        $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => '8505550199']);
        $invitedId = (int) User::findByPhone('+18505550199')['id'];
        $rowId = (int) RestaurantStaff::forRestaurant($this->restaurantId)[1]['id'];

        $this->actingAsOwner($invitedId);

        $invite = $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => '8505550188']);
        self::assertSame(403, $invite->status);
        self::assertNull(User::findByPhone('+18505550188'));

        $remove = $this->post('/kitchen/staff/' . $rowId . '/delete', ['_csrf' => Csrf::token()]);
        self::assertSame(403, $remove->status);
        self::assertTrue(RestaurantStaff::isStaffOf($invitedId, $this->restaurantId));
    }

    public function testANonOwnerSeesTheListButNotTheForm(): void
    {
        $this->post('/kitchen/staff', ['_csrf' => Csrf::token(), 'phone' => '8505550199']);
        $this->actingAsOwner((int) User::findByPhone('+18505550199')['id']);

        $body = $this->get('/kitchen/staff')->body;

        self::assertStringContainsString('Your team', $body);
        self::assertStringContainsString('Only the owner', $body);
        self::assertStringNotContainsString('Add to kitchen', $body);
    }
}
