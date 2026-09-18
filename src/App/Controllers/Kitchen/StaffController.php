<?php

namespace Keel\App\Controllers\Kitchen;

use Keel\App\Models\RestaurantStaff;
use Keel\App\Models\User;
use Keel\Core\Activity;
use Keel\Core\Env;
use Keel\Core\Request;
use Keel\Core\Sms;

/**
 * Who else can work the tablet.
 *
 * There is no invitation to accept and no link to click. Sign-in is a phone
 * number and a texted code, so an invitation is nothing more than the number
 * being attached to the restaurant: the new hire opens the app, types their
 * number, and the kitchen is there. The text this sends is a courtesy, not a
 * credential, and losing it costs nothing.
 *
 * A number already signed up as a customer or a driver is refused rather than
 * converted. Someone's role decides which of the four apps they land in, and
 * flipping a driver to restaurant_staff because a manager typed the wrong
 * number would take away the app they were working in.
 */
class StaffController extends KitchenController
{
    public function index(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];

        $this->view('kitchen.staff', array_merge(
            $this->shell($restaurant, 'staff', 'Staff'),
            [
                'staff' => RestaurantStaff::forRestaurant($restaurantId),
                'isOwner' => $this->isOwner($restaurantId),
            ]
        ));
    }

    public function invite(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];
        $this->authorizeStaffAdmin($restaurantId);

        $phone = Sms::normalize((string) $request->input('phone', ''));

        if ($phone === null) {
            $this->back('/kitchen/staff', 'That is not a mobile number.', 'bad');
        }

        $name = trim((string) $request->input('name', ''));
        $user = User::findByPhone($phone);

        if ($user === null) {
            $userId = User::createWithPhone($phone, User::ROLE_RESTAURANT_STAFF, $name === '' ? null : $name);
        } elseif ((string) $user['role'] === User::ROLE_RESTAURANT_STAFF) {
            $userId = (int) $user['id'];
        } else {
            $this->back(
                '/kitchen/staff',
                'That number already belongs to a FairPlate account of another kind. '
                . 'Ask them to use a different number.',
                'bad'
            );
        }

        if (RestaurantStaff::isStaffOf($userId, $restaurantId)) {
            $this->back('/kitchen/staff', 'They are already on your staff list.', 'warn');
        }

        RestaurantStaff::create([
            'restaurant_id' => $restaurantId,
            'user_id' => $userId,
            'is_owner' => 0,
        ]);

        Activity::log('restaurant.staff_invited', 'Restaurant', $restaurantId, ['user_id' => $userId]);

        $appName = (string) Env::get('APP_NAME', 'FairPlate');
        Sms::send(
            $phone,
            $restaurant['name'] . ' added you to their ' . $appName . ' kitchen. '
            . 'Sign in at ' . rtrim((string) Env::get('APP_URL', ''), '/') . '/login with this number.'
        );

        $this->back('/kitchen/staff', 'Added. They can sign in with that number now.');
    }

    public function remove(Request $request, string $id): void
    {
        $staffId = (int) $id;
        $this->authorizeRecord('restaurant_staff', $staffId);

        $row = RestaurantStaff::find($staffId);
        $restaurantId = (int) $row['restaurant_id'];
        $this->authorizeStaffAdmin($restaurantId);

        if ((int) $row['is_owner'] === 1) {
            $this->back('/kitchen/staff', 'The owner cannot be removed from their own restaurant.', 'bad');
        }

        RestaurantStaff::delete($staffId);
        Activity::log('restaurant.staff_removed', 'Restaurant', $restaurantId, ['user_id' => (int) $row['user_id']]);

        $this->back('/kitchen/staff', 'Removed. They can no longer open your kitchen.');
    }

    private function isOwner(int $restaurantId): bool
    {
        foreach (RestaurantStaff::forRestaurant($restaurantId) as $member) {
            if ((int) $member['user_id'] === $this->userId()) {
                return (int) $member['is_owner'] === 1;
            }
        }

        return false;
    }
}
