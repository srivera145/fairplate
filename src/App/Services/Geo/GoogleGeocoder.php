<?php

namespace Keel\App\Services\Geo;

use Keel\Core\Env;

/**
 * Address lookup through the Google Geocoding API.
 *
 * Results are biased to Florida and to the region FairPlate serves, so a bare
 * "100 Main St" resolves in Tallahassee rather than in whichever Main St
 * Google likes best that day. ZERO_RESULTS, a network failure and a malformed
 * body all come back as null with a logged warning: the caller's job is to ask
 * the customer to check the address, not to handle three kinds of failure.
 */
final class GoogleGeocoder implements Geocoder
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** The market FairPlate launched in; a bare street name resolves here first. */
    private const REGION_BIAS = 'us';
    private const COMPONENT_FILTER = 'country:US|administrative_area:FL';

    private const TIMEOUT_SECONDS = 8;

    /** @var (callable(string): array{status: int, body: string})|null */
    private $transport;

    /**
     * @param (callable(string): array{status: int, body: string})|null $transport
     *        GETs the URL. Defaults to curl; tests hand in a stub.
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * @return array{lat: float, lng: float, formatted_address: string}|null
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);

        if ($address === '') {
            return null;
        }

        $apiKey = trim((string) Env::get('GOOGLE_MAPS_KEY', ''));

        if ($apiKey === '') {
            throw new \RuntimeException('GOOGLE_MAPS_KEY is not configured.');
        }

        $url = self::ENDPOINT . '?' . http_build_query([
            'address' => $address,
            'components' => self::COMPONENT_FILTER,
            'region' => self::REGION_BIAS,
            'key' => $apiKey,
        ]);

        try {
            $response = ($this->transport ?? $this->curlTransport(...))($url);
        } catch (\RuntimeException $exception) {
            error_log('[Keel] Geocoding request failed: ' . $exception->getMessage());

            return null;
        }

        $status = (int) ($response['status'] ?? 0);

        if ($status !== 200) {
            error_log('[Keel] Geocoding returned HTTP ' . $status . '.');

            return null;
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        if (!is_array($decoded)) {
            error_log('[Keel] Geocoding returned a body that is not JSON.');

            return null;
        }

        $apiStatus = (string) ($decoded['status'] ?? '');

        if ($apiStatus === 'ZERO_RESULTS') {
            return null;
        }

        if ($apiStatus !== 'OK') {
            error_log('[Keel] Geocoding returned status "' . $apiStatus . '": ' . (string) ($decoded['error_message'] ?? ''));

            return null;
        }

        $result = $decoded['results'][0] ?? null;
        $location = $result['geometry']['location'] ?? null;

        if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
            error_log('[Keel] Geocoding returned a result with no location.');

            return null;
        }

        return [
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
            'formatted_address' => (string) ($result['formatted_address'] ?? $address),
        ];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function curlTransport(string $url): array
    {
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false || $error !== '') {
            throw new \RuntimeException($error !== '' ? $error : 'no response');
        }

        return ['status' => $status, 'body' => (string) $raw];
    }
}
