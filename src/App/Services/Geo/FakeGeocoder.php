<?php

namespace Keel\App\Services\Geo;

/**
 * A geocoder that answers with whatever it was told to answer.
 *
 * Onboarding cares whether a point is inside the delivery zone, not how the
 * point was found, so tests hand this in and stay off the network — the same
 * arrangement FakeRoutingService has with the pricing tests.
 */
final class FakeGeocoder implements Geocoder
{
    /** @var list<string> */
    private array $calls = [];

    /**
     * @param array{lat: float, lng: float, formatted_address?: string}|null $result
     *        what every lookup returns; null means "address not found"
     */
    public function __construct(private ?array $result = null)
    {
    }

    public function willReturn(?array $result): void
    {
        $this->result = $result;
    }

    /**
     * @return array{lat: float, lng: float, formatted_address: string}|null
     */
    public function geocode(string $address): ?array
    {
        $this->calls[] = $address;

        if ($this->result === null) {
            return null;
        }

        return [
            'lat' => (float) $this->result['lat'],
            'lng' => (float) $this->result['lng'],
            'formatted_address' => (string) ($this->result['formatted_address'] ?? $address),
        ];
    }

    /** @return list<string> */
    public function calls(): array
    {
        return $this->calls;
    }
}
