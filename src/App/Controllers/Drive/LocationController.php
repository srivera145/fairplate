<?php

namespace Keel\App\Controllers\Drive;

use Keel\App\Models\Driver;
use Keel\App\Models\DriverLocation;
use Keel\App\Models\Order;
use Keel\Core\Request;

/**
 * Where the driver is.
 *
 * One endpoint, called a few times a minute by a phone in a cup holder, and the
 * shortest thing in the driver app on purpose: it runs on a cellular connection
 * that keeps dropping, so it does two writes and answers.
 *
 * The rule the spec is firm about is enforced here rather than in the script.
 * Location is collected only while online. A ping from a driver who has gone
 * offline — a tab left open, a script that did not get the message, a request
 * that was in flight when the switch was flipped — is accepted with a 200 and
 * then discarded, and the answer tells the page to stop. The page could have
 * stopped by itself; this is what makes it true whether or not it did.
 *
 * Two writes, because they answer different questions. The drivers row is the
 * live position and is what dispatch ranks offers against; it is one row per
 * driver and it is overwritten. driver_locations is the trail, and carries the
 * order id while there is one, which is what lets a customer watch their own
 * delivery and nothing else.
 */
class LocationController extends DriverController
{
    /**
     * Coordinates outside these are a broken sensor, not a position.
     */
    private const LAT_RANGE = 90.0;
    private const LNG_RANGE = 180.0;

    public function store(Request $request): never
    {
        $driver = $this->driver();

        if ($driver === null || !Driver::isApproved($driver)) {
            $this->json(['stored' => false, 'online' => false], 403);
        }

        if (!Driver::isOnline($driver)) {
            $this->json(['stored' => false, 'online' => false]);
        }

        $lat = $this->coordinate($request->input('lat'), self::LAT_RANGE);
        $lng = $this->coordinate($request->input('lng'), self::LNG_RANGE);

        // A phone with no fix sometimes reports the null island rather than an
        // error. It is in the Gulf of Guinea, and nobody is delivering from it.
        if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
            $this->json(['stored' => false, 'online' => true, 'error' => 'A position needs a lat and a lng.'], 422);
        }

        $driverId = (int) $driver['id'];
        $active = Order::activeForDriver($driverId);
        $orderId = $active === null ? null : (int) $active['id'];

        Driver::recordPing($driverId, $lat, $lng);
        DriverLocation::record($driverId, $lat, $lng, $orderId);

        $this->json([
            'stored' => true,
            'online' => true,
            'order_id' => $orderId,
            // The page reads this back rather than keeping its own copy, so a
            // driver who goes from waiting to carrying speeds up without a
            // reload.
            'next_ping_seconds' => $orderId === null
                ? $this->pingSeconds('location_ping_online_seconds')
                : $this->pingSeconds('location_ping_active_seconds'),
        ]);
    }

    /**
     * A coordinate, or null when it is not one.
     *
     * Bound as a float here and written as a string by the model, so it reaches
     * DECIMAL(10,7) as the phone reported it.
     */
    private function coordinate(mixed $value, float $range): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (!is_finite($number) || abs($number) > $range) {
            return null;
        }

        return $number;
    }
}
