<?php

declare(strict_types=1);

namespace Tests\Support;

use Keel\App\Models\Address;
use Keel\App\Models\Membership;
use Keel\App\Models\User;
use Keel\App\Services\Geo\FakeGeocoder;
use Keel\App\Services\Geo\GeocoderFactory;
use Keel\App\Services\Routing\FakeRoutingService;
use Keel\App\Services\Routing\RoutingFactory;
use Keel\App\Services\Settings;
use Keel\App\Services\StripeClientFactory;
use Keel\Core\Auth;
use Keel\Core\Session;

/**
 * What a customer test needs before it can say anything.
 *
 * A checkout test needs settings, a zone, a restaurant, a menu, an address, a
 * cart and a Stripe client — seven things before the first assertion, none of
 * which is what the test is about. This keeps that out of the tests, where it
 * would bury the one line that matters.
 *
 * It also owns the three swaps. Geocoding, routing and Stripe all reach the
 * network in production and none of them may in a test, so every fixture that
 * needs one installs a fake and the tests never touch a factory directly.
 */
trait CustomerFixtures
{
    use KitchenFixtures;

    /** What the spec seeds. Tests that price anything need all of it. */
    protected const PRICING_SETTINGS = [
        ['driver_base_cents', '300', 'int'],
        ['driver_per_mile_cents', '100', 'int'],
        ['driver_min_payout_cents', '500', 'int'],
        ['driver_wait_free_minutes', '10', 'int'],
        ['driver_wait_per_min_cents', '20', 'int'],
        ['driver_wait_cap_cents', '300', 'int'],
        ['processing_pct', '0.029', 'decimal'],
        ['processing_fixed_cents', '30', 'int'],
        ['platform_fee_cents', '199', 'int'],
        ['membership_price_cents', '999', 'int'],
    ];

    /**
     * Settings the customer app reads that are not prices.
     *
     * The receipt asks how long a tip may still be raised for, so every
     * customer test needs it for the same reason it needs the platform fee:
     * Settings has no defaults on purpose, and a screen that reads a key the
     * seeder writes has to have it.
     */
    protected const CUSTOMER_SETTINGS = [
        ['tip_adjust_window_hours', '24', 'int'],
    ];

    protected function seedPricingSettings(): void
    {
        foreach (array_merge(self::PRICING_SETTINGS, self::CUSTOMER_SETTINGS) as [$key, $value, $type]) {
            Settings::put($key, $value, $type);
        }

        Settings::flush();
    }

    /**
     * Signs in a customer and gives them a default address inside the zone.
     *
     * @return array{user: array<string, mixed>, address_id: int}
     */
    protected function actingAsCustomer(array $overrides = []): array
    {
        $user = $this->createUser(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'name' => 'Marisol Vega',
            'phone' => '+1850' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        ], $overrides));

        Session::put('user_id', (int) $user['id']);
        Auth::setUserId(null);

        return ['user' => $user, 'address_id' => 0];
    }

    /**
     * An address for this customer, inside the delivery zone by default.
     */
    protected function createAddress(int $userId, array $overrides = []): int
    {
        $addressId = Address::create(array_merge([
            'user_id' => $userId,
            'label' => 'Home',
            'line1' => '9 Oak St',
            'city' => 'Tallahassee',
            'state' => 'FL',
            'zip' => '32301',
            'lat' => (string) self::INSIDE_ZONE['lat'],
            'lng' => (string) self::INSIDE_ZONE['lng'],
        ], $overrides));

        Address::makeDefault($userId, $addressId);

        return $addressId;
    }

    /**
     * A whole orderable restaurant: zone, restaurant, category, one item.
     *
     * Hours cover every day around the clock, so a test never fails because it
     * ran at four in the morning.
     *
     * @return array{zone_id: int, restaurant_id: int, category_id: int, item_id: int}
     */
    protected function createOrderableRestaurant(string $name = 'Taqueria Uno', array $overrides = []): array
    {
        $zoneId = $this->createZone();
        $created = $this->createRestaurantWithOwner($name, $zoneId, array_merge([
            'hours' => $this->alwaysOpenHours(),
        ], $overrides));

        $restaurantId = $created['restaurant_id'];
        $categoryId = $this->createCategory($restaurantId);
        $itemId = $this->createItem($restaurantId, $categoryId);

        return [
            'zone_id' => $zoneId,
            'restaurant_id' => $restaurantId,
            'owner_id' => $created['owner_id'],
            'category_id' => $categoryId,
            'item_id' => $itemId,
        ];
    }

    protected function alwaysOpenHours(): string
    {
        $days = [];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $days[$day] = [['open' => '00:00', 'close' => '23:59']];
        }

        return (string) json_encode(['days' => $days, 'closures' => []]);
    }

    /**
     * A past order for this customer, with a breakdown that charged a platform
     * fee. What the membership nudge counts.
     */
    protected function createChargedOrder(
        int $userId,
        int $restaurantId,
        int $platformFeeCents = 199,
        int $chargeableCents = 1740,
        int $totalCents = 1823
    ): int {
        $orderId = \Keel\App\Models\Order::create([
            'customer_id' => $userId,
            'restaurant_id' => $restaurantId,
            'address_snapshot' => (string) json_encode(['line1' => '9 Oak St', 'city' => 'Tallahassee']),
            'route_miles' => '3.00',
            'placed_at' => gmdate('Y-m-d H:i:s'),
            'authorized_cents' => $totalCents,
        ]);

        \Keel\App\Models\OrderPriceBreakdown::create([
            'order_id' => $orderId,
            'stage' => \Keel\App\Models\OrderPriceBreakdown::STAGE_AUTHORIZED,
            'subtotal_cents' => 750,
            'tax_cents' => 56,
            'driver_guaranteed_cents' => 600,
            'wait_pay_cents' => 0,
            'tip_cents' => 135,
            'platform_fee_cents' => $platformFeeCents,
            'service_fee_cents' => $totalCents - $chargeableCents,
            'total_cents' => $totalCents,
            'settings_snapshot' => (string) json_encode([
                'processing_pct' => '0.029',
                'processing_fixed_cents' => 30,
            ]),
        ]);

        return $orderId;
    }

    protected function makeMember(int $userId): int
    {
        return Membership::create([
            'user_id' => $userId,
            'stripe_subscription_id' => 'sub_test_' . $userId,
            'status' => Membership::STATUS_ACTIVE,
            'current_period_end' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
    }

    /**
     * Installs the fakes and hands back the Stripe one, which is what tests
     * assert against.
     */
    protected function fakeStripe(): FakeStripePayments
    {
        $stripe = new FakeStripePayments();
        StripeClientFactory::swap($stripe);

        return $stripe;
    }

    protected function fakeRouting(float $miles = 3.0): FakeRoutingService
    {
        $routing = new FakeRoutingService($miles);
        RoutingFactory::swap($routing);

        return $routing;
    }

    /**
     * @param array{lat: float, lng: float}|null $result null means "address not found"
     */
    protected function fakeGeocoder(?array $result): FakeGeocoder
    {
        $geocoder = new FakeGeocoder($result);
        GeocoderFactory::swap($geocoder);

        return $geocoder;
    }

    /**
     * Puts every swapped collaborator back. Call it from tearDown, or the next
     * test in the process inherits a fake it never asked for.
     */
    protected function restoreCollaborators(): void
    {
        StripeClientFactory::swap(null);
        RoutingFactory::swap(null);
        GeocoderFactory::swap(null);
    }
}
