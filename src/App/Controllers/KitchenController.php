<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Order;
use Keel\App\Models\RestaurantStaff;
use Keel\App\Models\User;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;

/** The restaurant area. Placeholder until the kitchen board lands. */
class KitchenController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) Auth::id();
        $restaurants = RestaurantStaff::restaurantsForUser($userId);

        $current = $restaurants[0] ?? null;

        $this->view('kitchen.dashboard', [
            'title' => 'FairPlate Kitchen',
            'user' => User::find($userId),
            'restaurants' => $restaurants,
            'restaurant' => $current,
            'orders' => $current === null ? [] : Order::forRestaurant((int) $current['id']),
        ]);
    }
}
