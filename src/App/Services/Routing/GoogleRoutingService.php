<?php

namespace Keel\App\Services\Routing;

use Keel\App\Services\ZoneService;
use Keel\Core\Env;

/**
 * Driving miles from the Google Maps Routes API.
 *
 * Three things keep this cheap and safe to call from checkout:
 *
 * Coordinates are rounded to four decimals — about eleven metres — before they
 * are used for anything. That is the cache key and it is also what gets sent,
 * so a cached answer and a fresh one describe the same route rather than two
 * points a few metres apart.
 *
 * Nothing here throws. A missing key, a timeout, an error status, a malformed
 * body: all of it logs a warning and returns straight-line distance times 1.3,
 * the usual allowance for streets not running as the crow flies. A delivery
 * priced on an estimate is a small error; a checkout that 500s because Google
 * is slow is a much larger one.
 *
 * Only real API answers are cached. Caching a fallback would freeze a degraded
 * guess in place for a month.
 */
final class GoogleRoutingService implements RoutingService
{
    private const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    /** The field mask Google requires; asking for less is what keeps the call cheap. */
    private const FIELD_MASK = 'routes.distanceMeters';

    private const METERS_PER_MILE = 1609.344;

    /** Four decimals of latitude and longitude: roughly eleven metres. */
    private const COORDINATE_PRECISION = 4;

    /** Miles are stored as DECIMAL(6,2), so the quote and the stored route agree. */
    private const MILES_PRECISION = 2;

    public const CACHE_TTL_SECONDS = 2592000;

    /** Streets are longer than the straight line between their ends. */
    public const FALLBACK_MULTIPLIER = 1.3;

    private const TIMEOUT_SECONDS = 8;

    private readonly RouteCache $cache;

    /** @var (callable(string, list<string>, string): array{status: int, body: string})|null */
    private $transport;

    /**
     * @param (callable(string, list<string>, string): array{status: int, body: string})|null $transport
     *        POSTs the body to the URL with those headers. Defaults to curl;
     *        tests hand in a stub so no unit test ever touches the network.
     */
    public function __construct(?RouteCache $cache = null, ?callable $transport = null)
    {
        $this->cache = $cache ?? new DatabaseRouteCache();
        $this->transport = $transport;
    }

    public function miles(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $from = [$this->round($fromLat), $this->round($fromLng)];
        $to = [$this->round($toLat), $this->round($toLng)];

        $key = $this->cacheKey($from, $to);
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $meters = $this->requestDistanceMeters($from, $to);
        } catch (\RuntimeException $exception) {
            error_log('[Keel] Routing fell back to haversine: ' . $exception->getMessage());

            return $this->fallbackMiles($from, $to);
        }

        $miles = round($meters / self::METERS_PER_MILE, self::MILES_PRECISION);

        $this->cache->put($key, $miles, self::CACHE_TTL_SECONDS);

        return $miles;
    }

    /**
     * Straight-line distance with the street allowance applied.
     *
     * Public because the fallback is a documented behaviour, not an accident:
     * admin screens showing why an order priced the way it did need to be able
     * to reproduce it.
     */
    public function haversineMiles(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $meters = ZoneService::haversineMeters($fromLat, $fromLng, $toLat, $toLng);

        return round(
            ($meters / self::METERS_PER_MILE) * self::FALLBACK_MULTIPLIER,
            self::MILES_PRECISION
        );
    }

    /**
     * @param array{0: float, 1: float} $from
     * @param array{0: float, 1: float} $to
     */
    private function fallbackMiles(array $from, array $to): float
    {
        return $this->haversineMiles($from[0], $from[1], $to[0], $to[1]);
    }

    /**
     * @param array{0: float, 1: float} $from
     * @param array{0: float, 1: float} $to
     *
     * @throws \RuntimeException on anything that is not a usable distance.
     */
    private function requestDistanceMeters(array $from, array $to): float
    {
        $apiKey = trim((string) Env::get('GOOGLE_MAPS_KEY', ''));

        if ($apiKey === '') {
            throw new \RuntimeException('GOOGLE_MAPS_KEY is not configured.');
        }

        $body = json_encode([
            'origin' => ['location' => ['latLng' => ['latitude' => $from[0], 'longitude' => $from[1]]]],
            'destination' => ['location' => ['latLng' => ['latitude' => $to[0], 'longitude' => $to[1]]]],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
            'units' => 'IMPERIAL',
        ], JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new \RuntimeException('Failed to encode the Routes API request.');
        }

        $headers = [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $apiKey,
            'X-Goog-FieldMask: ' . self::FIELD_MASK,
        ];

        $response = ($this->transport ?? $this->curlTransport(...))(self::ENDPOINT, $headers, $body);

        $status = (int) ($response['status'] ?? 0);

        if ($status !== 200) {
            throw new \RuntimeException('Routes API returned HTTP ' . $status . '.');
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Routes API returned a body that is not JSON.');
        }

        $meters = $decoded['routes'][0]['distanceMeters'] ?? null;

        if (!is_int($meters) && !is_float($meters)) {
            throw new \RuntimeException('Routes API returned no drivable route.');
        }

        if ($meters <= 0) {
            throw new \RuntimeException('Routes API returned a non-positive distance.');
        }

        return (float) $meters;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private function curlTransport(string $url, array $headers, string $body): array
    {
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false || $error !== '') {
            throw new \RuntimeException('Routes API request failed: ' . ($error !== '' ? $error : 'no response'));
        }

        return ['status' => $status, 'body' => (string) $raw];
    }

    private function round(float $coordinate): float
    {
        return round($coordinate, self::COORDINATE_PRECISION);
    }

    /**
     * @param array{0: float, 1: float} $from
     * @param array{0: float, 1: float} $to
     */
    private function cacheKey(array $from, array $to): string
    {
        return sprintf(
            '%.4f,%.4f:%.4f,%.4f',
            $from[0],
            $from[1],
            $to[0],
            $to[1]
        );
    }
}
