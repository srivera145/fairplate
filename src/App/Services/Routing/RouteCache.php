<?php

namespace Keel\App\Services\Routing;

/**
 * Storage for distances already looked up.
 *
 * A restaurant delivers to the same few blocks all day, so most route lookups
 * repeat. Keyed by rounded coordinates, a cache turns those into one paid API
 * call instead of hundreds.
 *
 * A cache is an optimisation, never a dependency: an implementation that cannot
 * reach its store must return null and let the lookup proceed, not throw.
 */
interface RouteCache
{
    /**
     * The cached miles for this key, or null when absent or expired.
     */
    public function get(string $key): ?float;

    public function put(string $key, float $miles, int $ttlSeconds): void;
}
