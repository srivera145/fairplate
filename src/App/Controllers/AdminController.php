<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\Restaurant;
use Keel\App\Models\Setting;
use Keel\App\Models\User;
use Keel\Core\Activity;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;
use Keel\Core\Response;

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
            'pendingDrivers' => Driver::awaitingApproval(),
            'settings' => Setting::all(),
        ]);
    }

    /**
     * Clears a driver to take offers.
     *
     * The one piece of the operations console that could not wait for it. A
     * driver who has filled in the form and connected Stripe cannot work until
     * somebody says so — that is the spec's rule and it is the right one — but
     * without this there is nobody to say it, and the whole driver app is a
     * screen that reads "pending approval" forever.
     *
     * Approval only. There is deliberately no button here for the other
     * direction: taking a driver's income away mid-shift deserves a screen that
     * shows what they are carrying and what they are owed, and that belongs with
     * the console rather than bolted on here.
     */
    public function approveDriver(Request $request, string $id): void
    {
        $driverId = (int) $id;
        $driver = Driver::find($driverId);

        if ($driver === null) {
            Response::redirect('/admin');
        }

        if (!Driver::isApproved($driver)) {
            Driver::update($driverId, ['approved' => 1]);
            Activity::log('driver.approved', 'Driver', $driverId, [
                'user_id' => (int) $driver['user_id'],
            ]);
        }

        Response::redirect('/admin');
    }
}
