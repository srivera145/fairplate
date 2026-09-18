<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Address;
use Keel\App\Models\Membership;
use Keel\App\Models\Order;
use Keel\App\Models\Restaurant;
use Keel\App\Models\User;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;

/** The customer area. Placeholder until the storefront lands. */
class AppController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) Auth::id();

        $this->view('app.dashboard', [
            'title' => 'FairPlate',
            'user' => User::find($userId),
            'addresses' => Address::forUser($userId),
            'restaurants' => Restaurant::orderable(),
            'orders' => Order::forCustomer($userId),
            'isMember' => Membership::isActiveFor($userId),
        ]);
    }
}
