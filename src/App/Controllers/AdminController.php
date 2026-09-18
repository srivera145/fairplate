<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\Restaurant;
use Keel\App\Models\Setting;
use Keel\App\Models\User;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;

/** The admin area. Placeholder until the operations console lands. */
class AdminController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin.dashboard', [
            'title' => 'FairPlate Admin',
            'user' => User::find((int) Auth::id()),
            'restaurantCount' => Restaurant::count(),
            'driverCount' => Driver::count(),
            'orderCount' => Order::count(),
            'settings' => Setting::all(),
        ]);
    }
}
