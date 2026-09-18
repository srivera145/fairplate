<?php

namespace Keel\App\Services\Routing;

/**
 * An in-memory route cache, for tests and one-off scripts.
 *
 * The clock is injectable so a test can prove a 30-day entry actually expires
 * without waiting 30 days for it.
 */
final class ArrayRouteCache implements RouteCache
{
    /** @var array<string, array{miles: float, expires_at: int}> */
    private array $entries = [];

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock Unix seconds; defaults to time().
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function get(string $key): ?float
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expires_at'] <= ($this->clock)()) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry['miles'];
    }

    public function put(string $key, float $miles, int $ttlSeconds): void
    {
        $this->entries[$key] = [
            'miles' => $miles,
            'expires_at' => ($this->clock)() + max(1, $ttlSeconds),
        ];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @return array<string, array{miles: float, expires_at: int}>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
