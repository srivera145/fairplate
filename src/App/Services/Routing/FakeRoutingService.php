<?php

namespace Keel\App\Services\Routing;

/**
 * A routing service that answers with whatever it was told to answer.
 *
 * Pricing tests care about what a given number of miles costs, not about how
 * the miles were measured, so they hand this in and keep the whole suite off
 * the network. It records its calls so a test can also assert that a quote
 * routed from the restaurant to the customer and not the other way round.
 */
final class FakeRoutingService implements RoutingService
{
    /** @var list<array{from_lat: float, from_lng: float, to_lat: float, to_lng: float}> */
    private array $calls = [];

    public function __construct(private float $miles = 3.0)
    {
    }

    public function setMiles(float $miles): void
    {
        $this->miles = $miles;
    }

    public function miles(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $this->calls[] = [
            'from_lat' => $fromLat,
            'from_lng' => $fromLng,
            'to_lat' => $toLat,
            'to_lng' => $toLng,
        ];

        return $this->miles;
    }

    /**
     * @return list<array{from_lat: float, from_lng: float, to_lat: float, to_lng: float}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
