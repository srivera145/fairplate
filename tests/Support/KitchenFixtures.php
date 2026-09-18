<?php

declare(strict_types=1);

namespace Tests\Support;

use Keel\App\Models\DeliveryZone;
use Keel\App\Models\Driver;
use Keel\App\Models\ItemOption;
use Keel\App\Models\ItemOptionGroup;
use Keel\App\Models\MenuCategory;
use Keel\App\Models\MenuItem;
use Keel\App\Models\Order;
use Keel\App\Models\OrderItem;
use Keel\App\Models\OrderItemOption;
use Keel\App\Models\Restaurant;
use Keel\App\Models\RestaurantFeeTier;
use Keel\App\Models\RestaurantStaff;
use Keel\App\Models\User;
use Keel\Core\Database;

/**
 * The rows a kitchen test needs before it can say anything.
 *
 * Most of these tests are about two restaurants that must not see each other,
 * so building one takes a zone, a restaurant, an owner, a category, an item and
 * an order — six tables before the first assertion. This keeps that out of the
 * tests, where it would bury what each one is actually checking.
 */
trait KitchenFixtures
{
    /** Downtown Tallahassee, the middle of the seeded zone. */
    protected const INSIDE_ZONE = ['lat' => 30.4383, 'lng' => -84.2807];

    /** Jacksonville: a real Florida address, and nowhere near the zone. */
    protected const OUTSIDE_ZONE = ['lat' => 30.3322, 'lng' => -81.6557];

    protected function createZone(int $radiusMeters = 12000): int
    {
        return DeliveryZone::create([
            'name' => 'Tallahassee',
            'type' => DeliveryZone::TYPE_RADIUS,
            'center_lat' => (string) self::INSIDE_ZONE['lat'],
            'center_lng' => (string) self::INSIDE_ZONE['lng'],
            'radius_m' => $radiusMeters,
            'active' => 1,
        ]);
    }

    /**
     * A restaurant with an owner signed up to it.
     *
     * @return array{restaurant_id: int, owner_id: int}
     */
    protected function createRestaurantWithOwner(string $name, ?int $zoneId = null, array $overrides = []): array
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '-' . bin2hex(random_bytes(2));

        $restaurantId = Restaurant::create(array_merge([
            'name' => $name,
            'slug' => $slug,
            'line1' => '100 Main St',
            'city' => 'Tallahassee',
            'state' => 'FL',
            'zip' => '32301',
            'lat' => (string) self::INSIDE_ZONE['lat'],
            'lng' => (string) self::INSIDE_ZONE['lng'],
            'delivery_zone_id' => $zoneId,
            'tax_rate' => '0.0750',
            'status' => Restaurant::STATUS_ACTIVE,
        ], $overrides));

        $owner = $this->createUser([
            'role' => User::ROLE_RESTAURANT_STAFF,
            'name' => $name . ' Owner',
            'phone' => $this->uniquePhone(),
        ]);

        RestaurantStaff::create([
            'restaurant_id' => $restaurantId,
            'user_id' => (int) $owner['id'],
            'is_owner' => 1,
        ]);

        return ['restaurant_id' => $restaurantId, 'owner_id' => (int) $owner['id']];
    }

    /**
     * Signs in as the owner of a restaurant created by createRestaurantWithOwner.
     */
    protected function actingAsOwner(int $userId): void
    {
        \Keel\Core\Session::put('user_id', $userId);
        \Keel\Core\Auth::setUserId(null);
    }

    /**
     * The seeded fee scale, which the monthly panel reads. Truncated between
     * tests like everything else, so a test that shows a fee has to put it back.
     */
    protected function createFeeTiers(): void
    {
        $tiers = [
            [0, 40, 0, 0],
            [41, 100, 24900, 0],
            [101, 250, 54900, 0],
            [251, 500, 99900, 0],
            [501, null, 0, 1],
        ];

        foreach ($tiers as $sort => [$min, $max, $fee, $isCustom]) {
            RestaurantFeeTier::create([
                'min_orders' => $min,
                'max_orders' => $max,
                'fee_cents' => $fee,
                'is_custom' => $isCustom,
                'sort' => $sort,
            ]);
        }
    }

    protected function createCategory(int $restaurantId, string $name = 'Tacos'): int
    {
        return MenuCategory::create([
            'restaurant_id' => $restaurantId,
            'name' => $name,
            'sort' => 0,
            'active' => 1,
        ]);
    }

    protected function createItem(int $restaurantId, int $categoryId, array $overrides = []): int
    {
        return MenuItem::create(array_merge([
            'restaurant_id' => $restaurantId,
            'menu_category_id' => $categoryId,
            'name' => 'Al Pastor Taco',
            'price_cents' => 375,
            'active' => 1,
            'in_stock' => 1,
            'sort' => 0,
        ], $overrides));
    }

    protected function createOptionGroup(int $itemId, string $name = 'Which size?'): int
    {
        return ItemOptionGroup::create([
            'menu_item_id' => $itemId,
            'name' => $name,
            'min_select' => 0,
            'max_select' => 1,
            'required' => 0,
            'sort' => 0,
        ]);
    }

    protected function createOption(int $groupId, string $name = 'Large', int $deltaCents = 100): int
    {
        return ItemOption::create([
            'item_option_group_id' => $groupId,
            'name' => $name,
            'price_delta_cents' => $deltaCents,
            'active' => 1,
            'sort' => 0,
        ]);
    }

    /**
     * A placed order with one line, one option and a note — everything the
     * board's card has to render.
     */
    protected function createPlacedOrder(int $restaurantId, array $overrides = []): int
    {
        $customer = $this->createUser([
            'role' => User::ROLE_CUSTOMER,
            'name' => 'Marisol Vega',
            'phone' => $this->uniquePhone(),
        ]);

        $orderId = Order::create(array_merge([
            'customer_id' => (int) $customer['id'],
            'restaurant_id' => $restaurantId,
            'address_snapshot' => json_encode(['line1' => '9 Oak St', 'city' => 'Tallahassee']),
            'route_miles' => '2.40',
            // status is left to the column default so that nothing outside
            // OrderLifecycle ever writes it.
            'placed_at' => gmdate('Y-m-d H:i:s'),
            'authorized_cents' => 2100,
        ], $overrides));

        $orderItemId = OrderItem::create([
            'order_id' => $orderId,
            'name_snapshot' => 'Al Pastor Taco',
            'unit_price_cents' => 375,
            'quantity' => 2,
            'line_total_cents' => 750,
            'notes' => 'No onions, please',
        ]);

        OrderItemOption::create([
            'order_item_id' => $orderItemId,
            'group_name_snapshot' => 'Which size?',
            'name_snapshot' => 'Large',
            'price_delta_cents' => 100,
        ]);

        return $orderId;
    }

    protected function createDriver(string $name = 'Dee Rowan'): int
    {
        $user = $this->createUser([
            'role' => User::ROLE_DRIVER,
            'name' => $name,
            'phone' => $this->uniquePhone(),
        ]);

        return Driver::create([
            'user_id' => (int) $user['id'],
            'approved' => 1,
            'online' => 1,
            'idle' => 1,
        ]);
    }

    /**
     * The status column, read straight from the database rather than through a
     * model, so a test of OrderLifecycle is not checking its own work.
     */
    protected function orderStatus(int $orderId): string
    {
        $statement = Database::connection()->prepare('SELECT status FROM orders WHERE id = ?');
        $statement->execute([$orderId]);

        return (string) $statement->fetchColumn();
    }

    /**
     * Moves an order to a status directly, for setting up a test that starts
     * somewhere other than placed. Not something the application may do.
     */
    protected function forceStatus(int $orderId, string $status): void
    {
        $statement = Database::connection()->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $statement->execute([$status, $orderId]);
    }

    private function uniquePhone(): string
    {
        return '+1850' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    }
}
