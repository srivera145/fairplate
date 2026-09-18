<?php

namespace Keel\App\Services\Routing;

use Keel\Core\Database;

/**
 * The route cache backed by the route_cache table.
 *
 * Shared across web requests and queue workers, which a per-process cache would
 * not be, and expired by a stored timestamp rather than a sweep — a row past
 * its expiry simply stops matching and is overwritten on the next lookup.
 *
 * Both methods swallow their database errors after logging. A cache that cannot
 * be reached costs an API call; a cache that throws costs a checkout.
 */
final class DatabaseRouteCache implements RouteCache
{
    public function get(string $key): ?float
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT miles FROM route_cache WHERE cache_key = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1'
            );
            $statement->execute([$key]);
            $row = $statement->fetch();
        } catch (\Throwable $exception) {
            error_log('[Keel] Route cache read failed: ' . $exception->getMessage());

            return null;
        }

        return $row === false || $row === null ? null : (float) $row['miles'];
    }

    public function put(string $key, float $miles, int $ttlSeconds): void
    {
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . max(1, $ttlSeconds) . ' seconds')
            ->format('Y-m-d H:i:s');

        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO route_cache (cache_key, miles, expires_at)
                 VALUES (:cache_key, :miles, :expires_at)
                 ON DUPLICATE KEY UPDATE miles = VALUES(miles), expires_at = VALUES(expires_at)'
            );
            $statement->execute([
                'cache_key' => $key,
                'miles' => number_format($miles, 2, '.', ''),
                'expires_at' => $expiresAt,
            ]);
        } catch (\Throwable $exception) {
            error_log('[Keel] Route cache write failed: ' . $exception->getMessage());
        }
    }
}
