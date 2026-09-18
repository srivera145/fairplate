<?php

namespace Keel\App\Services\Routing;

/**
 * Driving distance between two points, in miles.
 *
 * Every price that depends on distance goes through this one method, so a
 * quote, a driver offer and a receipt can never disagree about how far the
 * delivery was. Implementations must not throw: a routing outage has to
 * degrade to an estimate rather than take checkout down with it.
 */
interface RoutingService
{
    /**
     * Driving miles from the first point to the second.
     */
    public function miles(float $fromLat, float $fromLng, float $toLat, float $toLng): float;
}
