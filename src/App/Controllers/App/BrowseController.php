<?php

namespace Keel\App\Controllers\App;

use Keel\App\Models\Address;
use Keel\App\Models\Restaurant;
use Keel\App\Models\Special;
use Keel\App\Services\RestaurantHours;
use Keel\App\Services\ZoneService;
use Keel\Core\Request;

/**
 * The first screen: what can bring me food, right now, here.
 *
 * "Here" is the reason this page needs an address before it can say anything.
 * Without a point there is no zone to filter by and no distance to sort on, so
 * a customer with an empty address book is asked for one rather than shown a
 * list that is wrong in a way they cannot see.
 *
 * Closed restaurants are not hidden. A favourite that opens at five is more
 * useful at four than a shorter list, so they sort to the bottom carrying the
 * hour they open. Everything above them is open, in the zone, and sorted by how
 * far the food has to travel.
 *
 * Distance here is straight-line, not driving miles. Sorting a page by twenty
 * routing calls would be slow and expensive for an ordering that a tenth of a
 * mile never changes; the real route miles are locked at checkout, once, for
 * the one restaurant that was chosen.
 */
class BrowseController extends CustomerController
{
    private const METERS_PER_MILE = 1609.344;

    public function index(Request $request): void
    {
        $userId = $this->userId();
        $search = trim(mb_substr((string) $request->input('q', ''), 0, 80));
        $address = Address::defaultForUser($userId);
        $zone = $this->zoneFor($address);

        $shell = $this->shell('browse', 'Order');

        if ($address === null || $zone === null) {
            $this->view('app.browse', array_merge($shell, [
                'search' => $search,
                'address' => $address,
                'zone' => null,
                'open' => [],
                'closed' => [],
                'needsAddress' => $address === null,
            ]));

            return;
        }

        [$open, $closed] = $this->cards($address, (int) $zone['id'], $search);

        $this->view('app.browse', array_merge($shell, [
            'search' => $search,
            'address' => $address,
            'zone' => $zone,
            'open' => $open,
            'closed' => $closed,
            'needsAddress' => false,
        ]));
    }

    /**
     * The cards, split into the ones that can cook now and the ones that cannot.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function cards(array $address, int $zoneId, string $search): array
    {
        $restaurants = Restaurant::discoverable($zoneId, $search);
        $specials = Special::activeForRestaurants(
            array_map(static fn (array $row): int => (int) $row['id'], $restaurants)
        );

        $lat = (float) $address['lat'];
        $lng = (float) $address['lng'];

        $open = [];
        $closed = [];

        foreach ($restaurants as $restaurant) {
            $running = Special::runningNow($specials[(int) $restaurant['id']] ?? []);
            $paused = Restaurant::isPaused($restaurant);
            $isOpen = !$paused && RestaurantHours::isOpenAt($restaurant['hours'] ?? null);

            $card = [
                'restaurant' => $restaurant,
                'miles' => $this->miles($lat, $lng, $restaurant),
                'has_special' => $running !== [],
                'special_count' => count($running),
                'is_open' => $isOpen,
                'status_label' => $this->statusLabel($restaurant, $paused),
            ];

            if ($isOpen) {
                $open[] = $card;
                continue;
            }

            $closed[] = $card;
        }

        return [$this->byDistance($open), $this->byDistance($closed)];
    }

    /**
     * Why a restaurant is not taking orders, in the words the card shows.
     */
    private function statusLabel(array $restaurant, bool $paused): string
    {
        if ($paused) {
            $minutes = Restaurant::pauseMinutesLeft($restaurant);

            return $minutes === null
                ? 'Paused — not taking orders'
                : 'Paused — back in ' . $minutes . ' min';
        }

        return RestaurantHours::nextOpeningLabel($restaurant['hours'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<array<string, mixed>>
     */
    private function byDistance(array $cards): array
    {
        usort($cards, static function (array $a, array $b): int {
            return $a['miles'] <=> $b['miles']
                ?: strcasecmp((string) $a['restaurant']['name'], (string) $b['restaurant']['name']);
        });

        return $cards;
    }

    /**
     * Straight-line miles, or a number large enough to sort last when the
     * restaurant has no coordinates to measure from.
     */
    private function miles(float $lat, float $lng, array $restaurant): float
    {
        $restaurantLat = $restaurant['lat'] ?? null;
        $restaurantLng = $restaurant['lng'] ?? null;

        if ($restaurantLat === null || $restaurantLng === null) {
            return PHP_FLOAT_MAX;
        }

        $meters = ZoneService::haversineMeters($lat, $lng, (float) $restaurantLat, (float) $restaurantLng);

        return round($meters / self::METERS_PER_MILE, 1);
    }

    private function zoneFor(?array $address): ?array
    {
        if ($address === null || $address['lat'] === null || $address['lng'] === null) {
            return null;
        }

        return ZoneService::zoneFor((float) $address['lat'], (float) $address['lng']);
    }
}
