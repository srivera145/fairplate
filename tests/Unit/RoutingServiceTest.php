<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Geo\GoogleGeocoder;
use Keel\App\Services\Routing\ArrayRouteCache;
use Keel\App\Services\Routing\FakeRoutingService;
use Keel\App\Services\Routing\GoogleRoutingService;
use Keel\App\Services\ZoneService;
use PHPUnit\Framework\TestCase;

/**
 * Routing and geocoding without the network.
 *
 * Both services take a transport, so every response Google could give — a good
 * one, a 500, a timeout, nonsense — is handed in directly. The warning log is
 * redirected to a temp file and asserted on, because "falls back quietly" and
 * "falls back silently" are very different operational stories.
 */
class RoutingServiceTest extends TestCase
{
    private const RESTAURANT_LAT = 30.4383;
    private const RESTAURANT_LNG = -84.2807;
    private const CUSTOMER_LAT = 30.4500;
    private const CUSTOMER_LNG = -84.3000;

    private string $logPath = '';
    private string|false $previousLog = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = tempnam(sys_get_temp_dir(), 'keel_routing_log_');
        $this->previousLog = ini_set('error_log', $this->logPath);

        $_ENV['GOOGLE_MAPS_KEY'] = 'test-key';
        $_SERVER['GOOGLE_MAPS_KEY'] = 'test-key';
    }

    protected function tearDown(): void
    {
        if ($this->previousLog !== false) {
            ini_set('error_log', $this->previousLog);
        }

        if ($this->logPath !== '' && file_exists($this->logPath)) {
            unlink($this->logPath);
        }

        unset($_ENV['GOOGLE_MAPS_KEY'], $_SERVER['GOOGLE_MAPS_KEY']);

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // The fake
    // -----------------------------------------------------------------

    public function testTheFakeReturnsTheValueItWasGiven(): void
    {
        $routing = new FakeRoutingService(4.25);

        self::assertSame(4.25, $routing->miles(1.0, 2.0, 3.0, 4.0));

        $routing->setMiles(9.5);

        self::assertSame(9.5, $routing->miles(1.0, 2.0, 3.0, 4.0));
        self::assertSame(2, $routing->callCount());
    }

    public function testTheFakeRecordsTheCoordinatesItWasAskedFor(): void
    {
        $routing = new FakeRoutingService();

        $routing->miles(self::RESTAURANT_LAT, self::RESTAURANT_LNG, self::CUSTOMER_LAT, self::CUSTOMER_LNG);

        self::assertSame(
            [[
                'from_lat' => self::RESTAURANT_LAT,
                'from_lng' => self::RESTAURANT_LNG,
                'to_lat' => self::CUSTOMER_LAT,
                'to_lng' => self::CUSTOMER_LNG,
            ]],
            $routing->calls()
        );
    }

    // -----------------------------------------------------------------
    // A working Routes API
    // -----------------------------------------------------------------

    public function testAGoodResponseBecomesDrivingMiles(): void
    {
        $routing = new GoogleRoutingService(new ArrayRouteCache(), $this->respondWith(4828));

        // 4828 m is 3.0 miles to the hundredth the route is stored at.
        self::assertSame(3.0, $this->routeMiles($routing));
    }

    public function testTheRequestCarriesTheKeyFieldMaskAndBothEndpoints(): void
    {
        $seen = [];
        $transport = function (string $url, array $headers, string $body) use (&$seen): array {
            $seen = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];

            return ['status' => 200, 'body' => json_encode(['routes' => [['distanceMeters' => 4828]]])];
        };

        $this->routeMiles(new GoogleRoutingService(new ArrayRouteCache(), $transport));

        self::assertStringContainsString('routes.googleapis.com', $seen['url']);
        self::assertContains('X-Goog-Api-Key: test-key', $seen['headers']);
        self::assertContains('X-Goog-FieldMask: routes.distanceMeters', $seen['headers']);
        self::assertSame('DRIVE', $seen['body']['travelMode']);
        self::assertSame(self::RESTAURANT_LAT, $seen['body']['origin']['location']['latLng']['latitude']);
        self::assertSame(self::CUSTOMER_LNG, $seen['body']['destination']['location']['latLng']['longitude']);
    }

    // -----------------------------------------------------------------
    // The cache
    // -----------------------------------------------------------------

    public function testASecondLookupIsServedFromTheCache(): void
    {
        $calls = 0;
        $transport = function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => json_encode(['routes' => [['distanceMeters' => 4828]]])];
        };

        $routing = new GoogleRoutingService(new ArrayRouteCache(), $transport);

        self::assertSame(3.0, $this->routeMiles($routing));
        self::assertSame(3.0, $this->routeMiles($routing));
        self::assertSame(3.0, $this->routeMiles($routing));

        self::assertSame(1, $calls, 'only the first lookup should reach the API');
    }

    public function testCoordinatesAreRoundedToFourDecimalsBeforeTheyAreCached(): void
    {
        $calls = 0;
        $transport = function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => json_encode(['routes' => [['distanceMeters' => 4828]]])];
        };

        $routing = new GoogleRoutingService(new ArrayRouteCache(), $transport);

        $routing->miles(30.43831234, -84.28069876, self::CUSTOMER_LAT, self::CUSTOMER_LNG);
        // A few centimetres away: the same rounded key, so the same entry.
        $routing->miles(30.43830001, -84.28070499, self::CUSTOMER_LAT, self::CUSTOMER_LNG);

        self::assertSame(1, $calls);
    }

    public function testAPointBeyondTheRoundingIsItsOwnCacheEntry(): void
    {
        $calls = 0;
        $transport = function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => json_encode(['routes' => [['distanceMeters' => 4828]]])];
        };

        $cache = new ArrayRouteCache();
        $routing = new GoogleRoutingService($cache, $transport);

        $routing->miles(30.4383, -84.2807, self::CUSTOMER_LAT, self::CUSTOMER_LNG);
        $routing->miles(30.4390, -84.2807, self::CUSTOMER_LAT, self::CUSTOMER_LNG);

        self::assertSame(2, $calls);
        self::assertSame(2, $cache->count());
    }

    public function testACachedRouteExpiresAfterThirtyDays(): void
    {
        $now = 1_700_000_000;
        $calls = 0;
        $transport = function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => json_encode(['routes' => [['distanceMeters' => 4828]]])];
        };

        $cache = new ArrayRouteCache(static function () use (&$now): int {
            return $now;
        });
        $routing = new GoogleRoutingService($cache, $transport);

        $this->routeMiles($routing);

        // One day short of the TTL: still cached.
        $now += GoogleRoutingService::CACHE_TTL_SECONDS - 86400;
        $this->routeMiles($routing);
        self::assertSame(1, $calls);

        // Past it: looked up again.
        $now += 86400 * 2;
        $this->routeMiles($routing);
        self::assertSame(2, $calls);
    }

    public function testTheThirtyDayTtlIsWhatIsAdvertised(): void
    {
        self::assertSame(30 * 24 * 60 * 60, GoogleRoutingService::CACHE_TTL_SECONDS);
    }

    // -----------------------------------------------------------------
    // Failure, and the haversine fallback
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: callable}>
     */
    public static function failureModes(): array
    {
        return [
            'the request throws' => [static function (): array {
                throw new \RuntimeException('connection timed out');
            }],
            'a 500 comes back' => [static fn (): array => ['status' => 500, 'body' => 'upstream error']],
            'a 403 comes back' => [static fn (): array => ['status' => 403, 'body' => '{"error":{"status":"PERMISSION_DENIED"}}']],
            'the body is not JSON' => [static fn (): array => ['status' => 200, 'body' => '<html>nope</html>']],
            'there is no route' => [static fn (): array => ['status' => 200, 'body' => '{"routes":[]}']],
            'the distance is zero' => [static fn (): array => ['status' => 200, 'body' => '{"routes":[{"distanceMeters":0}]}']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failureModes')]
    public function testAnApiFailureFallsBackToHaversineWithoutThrowing(callable $transport): void
    {
        $routing = new GoogleRoutingService(new ArrayRouteCache(), $transport);

        $miles = $this->routeMiles($routing);

        self::assertSame($this->expectedFallbackMiles(), $miles);
        self::assertGreaterThan(0.0, $miles);
    }

    public function testAMissingApiKeyFallsBackRatherThanBreakingCheckout(): void
    {
        unset($_ENV['GOOGLE_MAPS_KEY'], $_SERVER['GOOGLE_MAPS_KEY']);
        putenv('GOOGLE_MAPS_KEY=');

        $routing = new GoogleRoutingService(new ArrayRouteCache(), static function (): array {
            throw new \LogicException('the transport must never be reached without a key');
        });

        self::assertSame($this->expectedFallbackMiles(), $this->routeMiles($routing));
        self::assertStringContainsString('GOOGLE_MAPS_KEY is not configured', $this->loggedOutput());
    }

    public function testTheFallbackIsStraightLineDistanceTimesOnePointThree(): void
    {
        $routing = new GoogleRoutingService(new ArrayRouteCache(), $this->alwaysFails());

        $straightLineMiles = ZoneService::haversineMeters(
            self::RESTAURANT_LAT,
            self::RESTAURANT_LNG,
            self::CUSTOMER_LAT,
            self::CUSTOMER_LNG
        ) / 1609.344;

        self::assertSame(1.3, GoogleRoutingService::FALLBACK_MULTIPLIER);
        self::assertEqualsWithDelta($straightLineMiles * 1.3, $this->routeMiles($routing), 0.005);
        // Roughly 1.4 straight-line miles across town, so about 1.9 driving.
        self::assertEqualsWithDelta(1.9, $this->routeMiles($routing), 0.1);
    }

    public function testTheFallbackLogsAWarning(): void
    {
        $routing = new GoogleRoutingService(new ArrayRouteCache(), $this->alwaysFails());

        $this->routeMiles($routing);

        self::assertStringContainsString('Routing fell back to haversine', $this->loggedOutput());
    }

    public function testAFallbackIsNeverCached(): void
    {
        $cache = new ArrayRouteCache();
        $routing = new GoogleRoutingService($cache, $this->alwaysFails());

        $this->routeMiles($routing);
        $this->routeMiles($routing);

        self::assertSame(0, $cache->count(), 'a degraded estimate must not be frozen for 30 days');
    }

    public function testACachedRouteIsStillServedWhenTheApiIsDown(): void
    {
        $cache = new ArrayRouteCache();

        $this->routeMiles(new GoogleRoutingService($cache, $this->respondWith(4828)));
        $miles = $this->routeMiles(new GoogleRoutingService($cache, $this->alwaysFails()));

        self::assertSame(3.0, $miles);
    }

    // -----------------------------------------------------------------
    // Geocoding
    // -----------------------------------------------------------------

    public function testGeocodingReturnsCoordinatesAndTheFormattedAddress(): void
    {
        $geocoder = new GoogleGeocoder(static fn (): array => ['status' => 200, 'body' => json_encode([
            'status' => 'OK',
            'results' => [[
                'formatted_address' => '400 S Monroe St, Tallahassee, FL 32399, USA',
                'geometry' => ['location' => ['lat' => 30.4383, 'lng' => -84.2807]],
            ]],
        ])]);

        self::assertSame(
            [
                'lat' => 30.4383,
                'lng' => -84.2807,
                'formatted_address' => '400 S Monroe St, Tallahassee, FL 32399, USA',
            ],
            $geocoder->geocode('400 S Monroe St, Tallahassee FL')
        );
    }

    public function testGeocodingBiasesToFlorida(): void
    {
        $seen = '';
        $geocoder = new GoogleGeocoder(function (string $url) use (&$seen): array {
            $seen = $url;

            return ['status' => 200, 'body' => '{"status":"ZERO_RESULTS","results":[]}'];
        });

        $geocoder->geocode('100 Main St');

        self::assertStringContainsString('administrative_area%3AFL', $seen);
        self::assertStringContainsString('key=test-key', $seen);
    }

    public function testAnAddressThatCannotBeFoundIsNullNotAnError(): void
    {
        $geocoder = new GoogleGeocoder(static fn (): array => [
            'status' => 200,
            'body' => '{"status":"ZERO_RESULTS","results":[]}',
        ]);

        self::assertNull($geocoder->geocode('not a real address at all'));
    }

    public function testAnEmptyAddressIsNeverSentToGoogle(): void
    {
        $geocoder = new GoogleGeocoder(static function (): array {
            throw new \LogicException('an empty address must not be looked up');
        });

        self::assertNull($geocoder->geocode('   '));
    }

    public function testAGeocodingFailureIsNullAndLogged(): void
    {
        $geocoder = new GoogleGeocoder(static fn (): array => ['status' => 500, 'body' => 'boom']);

        self::assertNull($geocoder->geocode('400 S Monroe St'));
        self::assertStringContainsString('Geocoding returned HTTP 500', $this->loggedOutput());
    }

    public function testGeocodingRefusesWithoutAKey(): void
    {
        unset($_ENV['GOOGLE_MAPS_KEY'], $_SERVER['GOOGLE_MAPS_KEY']);
        putenv('GOOGLE_MAPS_KEY=');

        $geocoder = new GoogleGeocoder(static fn (): array => ['status' => 200, 'body' => '{}']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GOOGLE_MAPS_KEY is not configured');

        $geocoder->geocode('400 S Monroe St');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function routeMiles(GoogleRoutingService $routing): float
    {
        return $routing->miles(
            self::RESTAURANT_LAT,
            self::RESTAURANT_LNG,
            self::CUSTOMER_LAT,
            self::CUSTOMER_LNG
        );
    }

    private function expectedFallbackMiles(): float
    {
        return (new GoogleRoutingService(new ArrayRouteCache(), $this->alwaysFails()))->haversineMiles(
            self::RESTAURANT_LAT,
            self::RESTAURANT_LNG,
            self::CUSTOMER_LAT,
            self::CUSTOMER_LNG
        );
    }

    private function respondWith(int $meters): callable
    {
        return static fn (): array => [
            'status' => 200,
            'body' => json_encode(['routes' => [['distanceMeters' => $meters]]]),
        ];
    }

    private function alwaysFails(): callable
    {
        return static function (): array {
            throw new \RuntimeException('connection refused');
        };
    }

    private function loggedOutput(): string
    {
        return file_exists($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }
}
