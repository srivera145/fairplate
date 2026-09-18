<?php

namespace Keel\App\Controllers;

use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\User;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;

/** The driver area. Placeholder until offers and navigation land. */
class DriveController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) Auth::id();
        $driver = Driver::forUser($userId);

        $this->view('drive.dashboard', [
            'title' => 'FairPlate Drive',
            'user' => User::find($userId),
            'driver' => $driver,
            'offers' => $driver === null ? [] : DispatchOffer::openForDriver((int) $driver['id']),
            'orders' => $driver === null ? [] : Order::forDriver((int) $driver['id']),
        ]);
    }
}
