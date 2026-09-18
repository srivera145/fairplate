<?php

namespace Keel\App\Controllers\Drive;

use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Services\Dispatch\OfferCard;
use Keel\Core\Activity;
use Keel\Core\Request;

/**
 * The screen a driver leaves open all shift.
 *
 * It has four states and no navigation between them, because a driver parked
 * outside a taqueria with the engine running is not browsing. Not signed up
 * yet, waiting on approval, online or offline with today's earnings at the top,
 * and — taking the whole screen when it happens — an offer.
 *
 * Going online is the only control on here, and it is what starts location
 * reporting: the spec collects position only while online, so the switch is the
 * consent and the script stops asking the moment it is turned off. Going
 * offline while carrying somebody's food is refused — the customer is watching
 * a map, and the food is in the car either way.
 */
class HomeController extends DriverController
{
    public function index(Request $request): void
    {
        $driver = $this->driver();

        // Never a redirect. /drive is the driver's home whatever state they are
        // in, and a page that bounces somebody who has just signed in is a page
        // that looks broken on a phone with one bar.
        if ($driver === null || !Driver::isApproved($driver)) {
            $this->view('drive.home', array_merge(
                $this->shell($driver, 'home', 'Drive'),
                [
                    'todayCents' => 0,
                    'todayCount' => 0,
                    'activeOrder' => null,
                    'card' => null,
                ]
            ));

            return;
        }

        $driverId = (int) $driver['id'];
        $active = Order::activeForDriver($driverId);
        $offer = $active === null ? DispatchOffer::currentForDriver($driverId) : null;
        $today = $this->today($driverId);

        $this->view('drive.home', array_merge(
            $this->shell($driver, 'home', 'Drive'),
            [
                'todayCents' => $today['cents'],
                'todayCount' => $today['count'],
                'activeOrder' => $active,
                'card' => $offer === null ? null : OfferCard::forOffer($driver, $offer),
                'onlinePingSeconds' => $this->pingSeconds('location_ping_online_seconds'),
            ]
        ));
    }

    /**
     * The switch.
     *
     * Going offline also stops location reporting, which is the half a driver
     * cares about: "only while online" has to mean something the driver
     * controls, and this is the control.
     */
    public function setOnline(Request $request): void
    {
        $driver = $this->requireApprovedDriver();
        $driverId = (int) $driver['id'];
        $online = $this->truthy($request->input('online', '0'));

        if (!$online && Order::activeForDriver($driverId) !== null) {
            $this->back('/drive', 'Finish the delivery you are on before going offline.', 'warn');
        }

        $attributes = ['online' => $online ? 1 : 0];

        if ($online) {
            // Coming back on after a break should not leave last night's
            // position looking like a live one to the dispatcher. The first ping
            // is a second away; until it lands this is the honest timestamp.
            $attributes['last_seen_at'] = gmdate('Y-m-d H:i:s');
        }

        Driver::update($driverId, $attributes);

        Activity::log($online ? 'driver.online' : 'driver.offline', 'Driver', $driverId);

        $this->back('/drive', $online ? 'You are online. Waiting for offers.' : 'You are offline.');
    }

    /**
     * What this driver has earned since midnight, their time.
     *
     * Guarantee plus wait plus tip, which is the whole of what a delivery pays.
     * Adding up settled line items is not pricing — nothing here applies a rate
     * or rounds anything — and every figure it sums was computed by
     * PricingService when the order was delivered.
     *
     * @return array{cents: int, count: int}
     */
    private function today(int $driverId): array
    {
        [$startUtc, $endUtc] = $this->dayBoundsUtc();
        $rows = Order::driverEarnings($driverId, $startUtc, $endUtc);
        $cents = 0;

        foreach ($rows as $row) {
            $cents += (int) $row['driver_guaranteed_cents']
                + (int) $row['wait_pay_cents']
                + (int) $row['tip_cents'];
        }

        return ['cents' => $cents, 'count' => count($rows)];
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
