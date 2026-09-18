<?php

namespace Keel\App\Services\Geo;

/**
 * A typed address turned into coordinates.
 *
 * Addresses and restaurants store lat/lng because the delivery zone test and
 * the route lookup both need a point, not a string. This is the one place a
 * string becomes a point.
 *
 * An address that cannot be found is a normal outcome — customers mistype —
 * so it returns null rather than throwing. Misconfiguration is not: an
 * implementation missing its credentials throws, because silently geocoding
 * nothing would look exactly like a town full of bad addresses.
 */
interface Geocoder
{
    /**
     * @return array{lat: float, lng: float, formatted_address: string}|null
     */
    public function geocode(string $address): ?array;
}
