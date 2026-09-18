<?php

namespace Keel\App\Services\Routing;

/**
 * Hands a controller a routing service.
 *
 * The same seam GeocoderFactory is: Keel's router builds controllers with
 * `new $class()` and no arguments, so checkout cannot be handed its collaborators
 * the usual way. Production gets Google behind the database cache; a test calls
 * swap() and quotes without touching the network.
 *
 * One interface, one default, one override. It is not a container.
 */
final class RoutingFactory
{
    private static ?RoutingService $override = null;

    public static function make(): RoutingService
    {
        return self::$override ?? new GoogleRoutingService(new DatabaseRouteCache());
    }

    /**
     * Replaces the routing service for the rest of the process. Tests only; pass
     * null to put the real one back.
     */
    public static function swap(?RoutingService $routing): void
    {
        self::$override = $routing;
    }
}
