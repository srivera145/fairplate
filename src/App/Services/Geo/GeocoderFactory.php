<?php

namespace Keel\App\Services\Geo;

/**
 * Hands a controller a geocoder.
 *
 * Keel's router builds controllers with `new $class()` and no arguments, so a
 * controller cannot be given its collaborators the usual way. This is the seam:
 * production gets the real thing, a test calls swap() and gets a fake, and the
 * controller is unaware either way.
 *
 * It is not a container and should not grow into one. One interface, one
 * default, one override.
 */
final class GeocoderFactory
{
    private static ?Geocoder $override = null;

    public static function make(): Geocoder
    {
        return self::$override ?? new GoogleGeocoder();
    }

    /**
     * Replaces the geocoder for the rest of the process. Tests only; pass null
     * to put the real one back.
     */
    public static function swap(?Geocoder $geocoder): void
    {
        self::$override = $geocoder;
    }
}
