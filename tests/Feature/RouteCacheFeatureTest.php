<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Services\Routing\DatabaseRouteCache;
use Keel\App\Services\Routing\GoogleRoutingService;
use Keel\Core\Database;
use Tests\TestCase;

/**
 * The route cache against a real route_cache table.
 *
 * The unit suite proves the caching behaviour with an in-memory store; this
 * proves the store itself — that the migration applied, that a row survives a
 * round trip at the precision orders are stored at, and that an expired row
 * stops being served.
 */
class RouteCacheFeatureTest extends TestCase
{
    private const RESTAURANT_LAT = 30.4383;
    private const RESTAURANT_LNG = -84.2807;
    private const CUSTOMER_LAT = 30.4500;
    private const CUSTOMER_LNG = -84.3000;

    public function testAMissingKeyReadsAsNull(): void
    {
        self::assertNull((new DatabaseRouteCache())->get('30.4383,-84.2807:30.4500,-84.3000'));
    }

    public function testAStoredRouteComesBackAtTheStoredPrecision(): void
    {
        $cache = new DatabaseRouteCache();

        $cache->put('30.4383,-84.2807:30.4500,-84.3000', 3.27, GoogleRoutingService::CACHE_TTL_SECONDS);

        self::assertSame(3.27, $cache->get('30.4383,-84.2807:30.4500,-84.3000'));
    }

    public function testWritingTheSameKeyTwiceUpdatesRatherThanDuplicates(): void
    {
        $cache = new DatabaseRouteCache();
        $key = '30.4383,-84.2807:30.4500,-84.3000';

        $cache->put($key, 3.27, GoogleRoutingService::CACHE_TTL_SECONDS);
        $cache->put($key, 4.10, GoogleRoutingService::CACHE_TTL_SECONDS);

        self::assertSame(4.10, $cache->get($key));
        self::assertSame(1, $this->rowCount());
    }

    public function testAnExpiredRowIsNoLongerServed(): void
    {
        $cache = new DatabaseRouteCache();
        $key = '30.4383,-84.2807:30.4500,-84.3000';

        $cache->put($key, 3.27, GoogleRoutingService::CACHE_TTL_SECONDS);
        $this->expireEverything();

        self::assertNull($cache->get($key));
        // The row is still there; it simply stops matching until it is rewritten.
        self::assertSame(1, $this->rowCount());
    }

    public function testTheRoutingServiceCachesARealLookupAndServesTheNextOneFromTheTable(): void
    {
        $calls = 0;
        $transport = function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => json_encode(['routes' => [['distanceMeters' => 4828]]])];
        };

        $_ENV['GOOGLE_MAPS_KEY'] = 'test-key';
        $_SERVER['GOOGLE_MAPS_KEY'] = 'test-key';

        $routing = new GoogleRoutingService(new DatabaseRouteCache(), $transport);

        $first = $routing->miles(self::RESTAURANT_LAT, self::RESTAURANT_LNG, self::CUSTOMER_LAT, self::CUSTOMER_LNG);
        $second = $routing->miles(self::RESTAURANT_LAT, self::RESTAURANT_LNG, self::CUSTOMER_LAT, self::CUSTOMER_LNG);

        unset($_ENV['GOOGLE_MAPS_KEY'], $_SERVER['GOOGLE_MAPS_KEY']);

        self::assertSame(3.0, $first);
        self::assertSame(3.0, $second);
        self::assertSame(1, $calls);
        self::assertSame(1, $this->rowCount());
    }

    private function rowCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM route_cache')->fetchColumn();
    }

    private function expireEverything(): void
    {
        Database::connection()->exec('UPDATE route_cache SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND');
    }
}
