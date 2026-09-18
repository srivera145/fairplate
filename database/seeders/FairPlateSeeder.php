<?php

declare(strict_types=1);

namespace Keel\Database\Seeders;

use Keel\App\Models\Address;
use Keel\App\Models\DeliveryZone;
use Keel\App\Models\Driver;
use Keel\App\Models\ItemOption;
use Keel\App\Models\ItemOptionGroup;
use Keel\App\Models\MenuCategory;
use Keel\App\Models\MenuItem;
use Keel\App\Models\Restaurant;
use Keel\App\Models\RestaurantFeeTier;
use Keel\App\Models\RestaurantStaff;
use Keel\App\Models\Special;
use Keel\App\Models\User;
use Keel\App\Services\Settings;
use Keel\Core\Database;

/**
 * The development dataset: one delivery zone, every setting, the fee tiers,
 * three restaurants with menus and specials, and one signed-in-able account per
 * role.
 *
 * Re-running it wipes the FairPlate tables first, so counts stay exact instead
 * of doubling. It refuses to run outside local, dev and testing.
 */
class FairPlateSeeder
{
    public const ZONE_NAME = 'Tallahassee';

    /** Downtown Tallahassee, at the Capitol. */
    private const DOWNTOWN_LAT = '30.4383000';
    private const DOWNTOWN_LNG = '-84.2807000';
    private const ZONE_RADIUS_METERS = 12000;

    /** Leon County, FL: 6% state plus 1.5% discretionary surtax. */
    private const TAX_RATE = '0.0750';

    /** Seed values from the spec. Everything is editable in admin later. */
    private const SETTINGS = [
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
        ['comparison_commission_pct', '0.25', 'decimal'],
        ['offer_timeout_seconds', '45', 'int'],
        ['dispatch_max_rounds', '5', 'int'],
        ['dispatch_max_minutes', '8', 'int'],
        ['tip_adjust_window_hours', '24', 'int'],
        ['location_ping_online_seconds', '15', 'int'],
        ['location_ping_active_seconds', '10', 'int'],
    ];

    /** min_orders, max_orders, fee_cents, is_custom. Under 41 orders is free. */
    private const FEE_TIERS = [
        [0, 40, 0, 0],
        [41, 100, 24900, 0],
        [101, 250, 54900, 0],
        [251, 500, 99900, 0],
        [501, null, 0, 1],
    ];

    /**
     * Tables this seeder owns, child rows first.
     */
    private const OWNED_TABLES = [
        'cart_item_options', 'cart_items', 'carts', 'checkout_intents',
        'order_item_options', 'order_items', 'order_price_breakdown', 'tip_adjustments',
        'payouts', 'refunds', 'dispatch_offers', 'driver_locations', 'orders',
        'specials', 'item_options', 'item_option_groups', 'menu_items', 'menu_categories',
        'restaurant_monthly_statements', 'restaurant_fee_tiers', 'restaurant_staff',
        'restaurants', 'delivery_zones', 'drivers', 'memberships', 'addresses', 'settings',
    ];

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * @return array<string, int> what was created, by kind
     */
    public function run(): array
    {
        $this->counts = [];

        $this->truncateOwnedTables();

        $zoneId = $this->seedZone();
        $this->seedSettings();
        $this->seedFeeTiers();
        $this->seedRestaurants($zoneId);
        $this->seedDrivers();
        $this->seedAdmin();
        $this->seedCustomers();

        Settings::flush();

        return $this->counts;
    }

    private function seedZone(): int
    {
        $zoneId = DeliveryZone::create([
            'name' => self::ZONE_NAME,
            'type' => DeliveryZone::TYPE_RADIUS,
            'center_lat' => self::DOWNTOWN_LAT,
            'center_lng' => self::DOWNTOWN_LNG,
            'radius_m' => self::ZONE_RADIUS_METERS,
            'active' => 1,
        ]);

        $this->counts['zones'] = 1;

        return $zoneId;
    }

    private function seedSettings(): void
    {
        foreach (self::SETTINGS as [$key, $value, $type]) {
            Settings::put($key, $value, $type);
        }

        $this->counts['settings'] = count(self::SETTINGS);
    }

    private function seedFeeTiers(): void
    {
        $sort = 0;

        foreach (self::FEE_TIERS as [$min, $max, $fee, $isCustom]) {
            RestaurantFeeTier::create([
                'min_orders' => $min,
                'max_orders' => $max,
                'fee_cents' => $fee,
                'is_custom' => $isCustom,
                'sort' => $sort++,
            ]);
        }

        $this->counts['fee_tiers'] = count(self::FEE_TIERS);
    }

    private function truncateOwnedTables(): void
    {
        $connection = Database::connection();
        $connection->exec('SET FOREIGN_KEY_CHECKS=0');

        foreach (self::OWNED_TABLES as $table) {
            $connection->exec('TRUNCATE TABLE `' . $table . '`');
        }

        // The seeded accounts, left behind by an earlier run.
        $connection->exec("DELETE FROM users WHERE email LIKE '%@fairplate.test'");

        $connection->exec('SET FOREIGN_KEY_CHECKS=1');

        Settings::flush();
    }

    private function bump(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    /**
     * Three restaurants near downtown, each with its own staff account, two to
     * three categories, eight to twelve items, option groups and one or two
     * specials. The first is a founding partner at 20% off its tier fee.
     */
    private function seedRestaurants(int $zoneId): void
    {
        foreach ($this->restaurantBlueprints() as $blueprint) {
            $restaurantId = Restaurant::create([
                'name' => $blueprint['name'],
                'slug' => $blueprint['slug'],
                'phone' => $blueprint['phone'],
                'line1' => $blueprint['line1'],
                'city' => 'Tallahassee',
                'state' => 'FL',
                'zip' => $blueprint['zip'],
                'lat' => $blueprint['lat'],
                'lng' => $blueprint['lng'],
                'delivery_zone_id' => $zoneId,
                'tax_rate' => self::TAX_RATE,
                'hours' => json_encode($this->standardHours(), JSON_THROW_ON_ERROR),
                'paused' => 0,
                'status' => Restaurant::STATUS_ACTIVE,
                'founding_discount_pct' => $blueprint['founding_discount_pct'],
            ]);

            $this->bump('restaurants');

            $staffUserId = $this->createUser(
                $blueprint['staff_name'],
                $blueprint['staff_phone'],
                $blueprint['slug'] . '-owner@fairplate.test',
                User::ROLE_RESTAURANT_STAFF
            );

            RestaurantStaff::create([
                'restaurant_id' => $restaurantId,
                'user_id' => $staffUserId,
                'is_owner' => 1,
            ]);

            $this->bump('restaurant_staff');

            $itemIdsByName = $this->seedMenu($restaurantId, $blueprint['categories']);
            $this->seedSpecials($restaurantId, $blueprint['specials'], $itemIdsByName);
        }
    }

    /**
     * @return array<string, int> item name to id, so a special can point at one
     */
    private function seedMenu(int $restaurantId, array $categories): array
    {
        $itemIds = [];
        $categorySort = 0;

        foreach ($categories as $categoryName => $items) {
            $categoryId = MenuCategory::create([
                'restaurant_id' => $restaurantId,
                'name' => $categoryName,
                'sort' => $categorySort++,
                'active' => 1,
            ]);

            $this->bump('menu_categories');

            $itemSort = 0;

            foreach ($items as $item) {
                $itemId = MenuItem::create([
                    'restaurant_id' => $restaurantId,
                    'menu_category_id' => $categoryId,
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'price_cents' => $item['price_cents'],
                    'active' => 1,
                    'in_stock' => 1,
                    'sort' => $itemSort++,
                ]);

                $this->bump('menu_items');
                $itemIds[$item['name']] = $itemId;

                foreach ($item['option_groups'] ?? [] as $group) {
                    $this->seedOptionGroup($itemId, $group);
                }
            }
        }

        return $itemIds;
    }

    private function seedOptionGroup(int $menuItemId, array $group): void
    {
        $groupId = ItemOptionGroup::create([
            'menu_item_id' => $menuItemId,
            'name' => $group['name'],
            'min_select' => $group['min_select'],
            'max_select' => $group['max_select'],
            'required' => $group['required'],
            'sort' => 0,
        ]);

        $this->bump('item_option_groups');

        $sort = 0;

        foreach ($group['options'] as [$optionName, $delta]) {
            ItemOption::create([
                'item_option_group_id' => $groupId,
                'name' => $optionName,
                'price_delta_cents' => $delta,
                'active' => 1,
                'sort' => $sort++,
            ]);

            $this->bump('item_options');
        }
    }

    private function seedSpecials(int $restaurantId, array $specials, array $itemIds): void
    {
        foreach ($specials as $special) {
            Special::create([
                'restaurant_id' => $restaurantId,
                'title' => $special['title'],
                'description' => $special['description'],
                'type' => $special['type'],
                'value_pct' => $special['value_pct'] ?? null,
                'value_cents' => $special['value_cents'] ?? null,
                'menu_item_id' => isset($special['item']) ? ($itemIds[$special['item']] ?? null) : null,
                'days' => json_encode($special['days'], JSON_THROW_ON_ERROR),
                'start_time' => $special['start_time'],
                'end_time' => $special['end_time'],
                'active' => 1,
            ]);

            $this->bump('specials');
        }
    }

    private function seedDrivers(): void
    {
        $drivers = [
            ['Dana Ruiz', '+18505550201', 'dana.ruiz@fairplate.test', 'Toyota', 'Corolla', 'Silver', 'LEON421'],
            ['Marcus Bell', '+18505550202', 'marcus.bell@fairplate.test', 'Honda', 'Civic', 'Blue', 'NOLE887'],
        ];

        foreach ($drivers as [$name, $phone, $email, $make, $model, $color, $plate]) {
            $userId = $this->createUser($name, $phone, $email, User::ROLE_DRIVER);

            Driver::create([
                'user_id' => $userId,
                'vehicle_make' => $make,
                'vehicle_model' => $model,
                'vehicle_color' => $color,
                'plate' => $plate,
                'approved' => 1,
                'online' => 0,
                'idle' => 1,
            ]);

            $this->bump('drivers');
        }
    }

    private function seedAdmin(): void
    {
        $this->createUser('FairPlate Admin', '+18505550100', 'admin@fairplate.test', User::ROLE_ADMIN);
        $this->bump('admins');
    }

    private function seedCustomers(): void
    {
        $customers = [
            [
                'name' => 'Ivy Chen',
                'phone' => '+18505550301',
                'email' => 'ivy.chen@fairplate.test',
                'address' => [
                    'label' => 'Home',
                    'line1' => '1104 Miccosukee Rd',
                    'zip' => '32308',
                    'lat' => '30.4560000',
                    'lng' => '-84.2570000',
                    'instructions' => 'Blue door round the side. Please knock.',
                ],
            ],
            [
                'name' => 'Theo Barnes',
                'phone' => '+18505550302',
                'email' => 'theo.barnes@fairplate.test',
                'address' => [
                    'label' => 'Apartment',
                    'line1' => '620 W Tennessee St',
                    'line2' => 'Apt 5C',
                    'zip' => '32304',
                    'lat' => '30.4436000',
                    'lng' => '-84.2932000',
                    'instructions' => 'Gate code 4417. Leave at the door.',
                ],
            ],
        ];

        foreach ($customers as $customer) {
            $userId = $this->createUser(
                $customer['name'],
                $customer['phone'],
                $customer['email'],
                User::ROLE_CUSTOMER
            );

            Address::create(array_merge(
                ['user_id' => $userId, 'city' => 'Tallahassee', 'state' => 'FL'],
                $customer['address']
            ));

            $this->bump('customers');
            $this->bump('addresses');
        }
    }

    private function createUser(string $name, string $phone, string $email, string $role): int
    {
        return User::createWithPhone($phone, $role, $name, $email);
    }

    /**
     * 11:00 to 22:00 every day, in the hours JSON shape the kitchen reads.
     *
     * @return array<string, array{open: string, close: string}>
     */
    private function standardHours(): array
    {
        $hours = [];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $hours[$day] = ['open' => '11:00', 'close' => '22:00'];
        }

        return $hours;
    }

    /**
     * The three seeded restaurants. Prices are in cents and are meant to read
     * like real in-store menu prices, because in FairPlate they are: the app
     * never marks a menu up.
     */
    private function restaurantBlueprints(): array
    {
        return [
            [
                'name' => 'Railroad Square Tacos',
                'slug' => 'railroad-square-tacos',
                'phone' => '+18505550411',
                'line1' => '648 McDonnell Dr',
                'zip' => '32310',
                'lat' => '30.4341000',
                'lng' => '-84.2934000',
                // The founding partner: 20% off whatever tier fee it lands in.
                'founding_discount_pct' => '0.2000',
                'staff_name' => 'Rosa Delgado',
                'staff_phone' => '+18505550412',
                'categories' => $this->tacoMenu(),
                'specials' => [
                    [
                        'title' => 'Taco Tuesday',
                        'description' => 'All street tacos, 20% off, all day Tuesday.',
                        'type' => Special::TYPE_PERCENT,
                        'value_pct' => '0.2000',
                        'days' => [2],
                        'start_time' => null,
                        'end_time' => null,
                    ],
                    [
                        'title' => 'Late lunch horchata',
                        'description' => 'Horchata for a dollar, weekdays 2pm to 4pm.',
                        'type' => Special::TYPE_PRICE,
                        'value_cents' => 100,
                        'item' => 'Horchata',
                        'days' => [1, 2, 3, 4, 5],
                        'start_time' => '14:00:00',
                        'end_time' => '16:00:00',
                    ],
                ],
            ],
            [
                'name' => 'Gaines Street Grill',
                'slug' => 'gaines-street-grill',
                'phone' => '+18505550421',
                'line1' => '905 W Gaines St',
                'zip' => '32304',
                'lat' => '30.4398000',
                'lng' => '-84.2949000',
                'founding_discount_pct' => '0.0000',
                'staff_name' => 'Wes Okafor',
                'staff_phone' => '+18505550422',
                'categories' => $this->grillMenu(),
                'specials' => [
                    [
                        'title' => 'Two dollars off any burger',
                        'description' => 'Every burger, two dollars off, Monday through Thursday.',
                        'type' => Special::TYPE_AMOUNT,
                        'value_cents' => 200,
                        'days' => [1, 2, 3, 4],
                        'start_time' => null,
                        'end_time' => null,
                    ],
                ],
            ],
            [
                'name' => 'Lake Ella Noodle Bar',
                'slug' => 'lake-ella-noodle-bar',
                'phone' => '+18505550431',
                'line1' => '1641 N Monroe St',
                'zip' => '32303',
                'lat' => '30.4657000',
                'lng' => '-84.2837000',
                'founding_discount_pct' => '0.0000',
                'staff_name' => 'Mei Tran',
                'staff_phone' => '+18505550432',
                'categories' => $this->noodleMenu(),
                'specials' => [
                    [
                        'title' => 'Weekend pho',
                        'description' => 'Beef pho at a flat twelve dollars, Saturday and Sunday.',
                        'type' => Special::TYPE_PRICE,
                        'value_cents' => 1200,
                        'item' => 'Beef Pho',
                        'days' => [6, 7],
                        'start_time' => null,
                        'end_time' => null,
                    ],
                    [
                        'title' => 'Happy hour bao',
                        'description' => 'Fifteen percent off all bao, weekdays 3pm to 6pm.',
                        'type' => Special::TYPE_PERCENT,
                        'value_pct' => '0.1500',
                        'days' => [1, 2, 3, 4, 5],
                        'start_time' => '15:00:00',
                        'end_time' => '18:00:00',
                    ],
                ],
            ],
        ];
    }

    /** Ten items across three categories. */
    private function tacoMenu(): array
    {
        $salsa = [
            'name' => 'Salsa',
            'min_select' => 1,
            'max_select' => 2,
            'required' => 1,
            'options' => [['Verde', 0], ['Roja', 0], ['Habanero', 50]],
        ];

        $tortilla = [
            'name' => 'Tortilla',
            'min_select' => 1,
            'max_select' => 1,
            'required' => 1,
            'options' => [['Corn', 0], ['Flour', 0]],
        ];

        return [
            'Street Tacos' => [
                ['name' => 'Al Pastor Taco', 'description' => 'Pork shoulder, pineapple, onion, cilantro.', 'price_cents' => 375, 'option_groups' => [$tortilla, $salsa]],
                ['name' => 'Carne Asada Taco', 'description' => 'Grilled steak, onion, cilantro.', 'price_cents' => 425, 'option_groups' => [$tortilla, $salsa]],
                ['name' => 'Pollo Asado Taco', 'description' => 'Citrus-marinated chicken thigh.', 'price_cents' => 375, 'option_groups' => [$tortilla, $salsa]],
                ['name' => 'Mushroom Taco', 'description' => 'Roasted oyster mushrooms, salsa macha.', 'price_cents' => 350, 'option_groups' => [$tortilla, $salsa]],
            ],
            'Plates' => [
                ['name' => 'Burrito Grande', 'description' => 'Rice, beans, cheese, your protein.', 'price_cents' => 1150, 'option_groups' => [
                    ['name' => 'Protein', 'min_select' => 1, 'max_select' => 1, 'required' => 1, 'options' => [['Al Pastor', 0], ['Carne Asada', 150], ['Pollo', 0], ['Black Bean', -100]]],
                    ['name' => 'Add-ons', 'min_select' => 0, 'max_select' => 3, 'required' => 0, 'options' => [['Guacamole', 200], ['Queso', 150], ['Sour Cream', 75]]],
                ]],
                ['name' => 'Taco Plate', 'description' => 'Three tacos, rice and beans.', 'price_cents' => 1295],
                ['name' => 'Loaded Nachos', 'description' => 'Queso, pico, jalapeno, crema.', 'price_cents' => 1050, 'option_groups' => [
                    ['name' => 'Protein', 'min_select' => 0, 'max_select' => 1, 'required' => 0, 'options' => [['Al Pastor', 250], ['Carne Asada', 350], ['No meat', 0]]],
                ]],
                ['name' => 'Elote', 'description' => 'Grilled corn, crema, cotija, chile.', 'price_cents' => 500],
            ],
            'Drinks' => [
                ['name' => 'Horchata', 'description' => 'Cinnamon rice milk, served cold.', 'price_cents' => 350],
                ['name' => 'Jarritos', 'description' => 'Mexican soda, assorted flavours.', 'price_cents' => 300, 'option_groups' => [
                    ['name' => 'Flavour', 'min_select' => 1, 'max_select' => 1, 'required' => 1, 'options' => [['Lime', 0], ['Mandarin', 0], ['Tamarind', 0]]],
                ]],
            ],
        ];
    }

    /** Nine items across two categories. */
    private function grillMenu(): array
    {
        $doneness = [
            'name' => 'Cooked to',
            'min_select' => 1,
            'max_select' => 1,
            'required' => 1,
            'options' => [['Medium rare', 0], ['Medium', 0], ['Well done', 0]],
        ];

        $burgerExtras = [
            'name' => 'Extras',
            'min_select' => 0,
            'max_select' => 4,
            'required' => 0,
            'options' => [['Bacon', 200], ['Extra patty', 400], ['Fried egg', 150], ['No pickles', 0]],
        ];

        return [
            'Burgers' => [
                ['name' => 'Gaines Classic', 'description' => 'Quarter pound, American cheese, house sauce.', 'price_cents' => 995, 'option_groups' => [$doneness, $burgerExtras]],
                ['name' => 'Smokehouse Burger', 'description' => 'Cheddar, bacon, crispy onion, barbecue.', 'price_cents' => 1250, 'option_groups' => [$doneness, $burgerExtras]],
                ['name' => 'Green Chile Burger', 'description' => 'Hatch chiles, pepper jack.', 'price_cents' => 1195, 'option_groups' => [$doneness, $burgerExtras]],
                ['name' => 'Black Bean Burger', 'description' => 'House-made patty, avocado, sprouts.', 'price_cents' => 1050, 'option_groups' => [$burgerExtras]],
                ['name' => 'Patty Melt', 'description' => 'Rye, swiss, caramelised onion.', 'price_cents' => 1150, 'option_groups' => [$doneness]],
            ],
            'Sides and Drinks' => [
                ['name' => 'Hand-cut Fries', 'description' => 'Sea salt, malt vinegar on request.', 'price_cents' => 450, 'option_groups' => [
                    ['name' => 'Make it loaded', 'min_select' => 0, 'max_select' => 1, 'required' => 0, 'options' => [['Chili cheese', 300], ['Garlic parmesan', 200]]],
                ]],
                ['name' => 'Onion Rings', 'description' => 'Beer battered, buttermilk dip.', 'price_cents' => 550],
                ['name' => 'House Salad', 'description' => 'Greens, tomato, cucumber, vinaigrette.', 'price_cents' => 650],
                ['name' => 'Fountain Drink', 'description' => 'Free refills in store.', 'price_cents' => 275, 'option_groups' => [
                    ['name' => 'Size', 'min_select' => 1, 'max_select' => 1, 'required' => 1, 'options' => [['Regular', 0], ['Large', 75]]],
                ]],
            ],
        ];
    }

    /** Eleven items across three categories. */
    private function noodleMenu(): array
    {
        $spice = [
            'name' => 'Spice level',
            'min_select' => 1,
            'max_select' => 1,
            'required' => 1,
            'options' => [['Mild', 0], ['Medium', 0], ['Thai hot', 0]],
        ];

        $noodleAdds = [
            'name' => 'Add to bowl',
            'min_select' => 0,
            'max_select' => 3,
            'required' => 0,
            'options' => [['Soft egg', 175], ['Extra noodles', 250], ['Brisket', 400], ['Tofu', 200]],
        ];

        return [
            'Bowls' => [
                ['name' => 'Beef Pho', 'description' => 'Twelve-hour broth, brisket, rice noodles.', 'price_cents' => 1395, 'option_groups' => [$noodleAdds]],
                ['name' => 'Chicken Pho', 'description' => 'Clear broth, poached chicken, herbs.', 'price_cents' => 1295, 'option_groups' => [$noodleAdds]],
                ['name' => 'Tonkotsu Ramen', 'description' => 'Pork bone broth, chashu, soft egg.', 'price_cents' => 1495, 'option_groups' => [$spice, $noodleAdds]],
                ['name' => 'Spicy Miso Ramen', 'description' => 'Miso tare, chili oil, corn, scallion.', 'price_cents' => 1450, 'option_groups' => [$spice, $noodleAdds]],
                ['name' => 'Veggie Ramen', 'description' => 'Mushroom dashi, seasonal greens.', 'price_cents' => 1250, 'option_groups' => [$spice, $noodleAdds]],
            ],
            'Bao and Small Plates' => [
                ['name' => 'Pork Belly Bao', 'description' => 'Two buns, pickled cucumber, hoisin.', 'price_cents' => 750],
                ['name' => 'Crispy Tofu Bao', 'description' => 'Two buns, sesame slaw.', 'price_cents' => 700],
                ['name' => 'Pork Gyoza', 'description' => 'Six pieces, pan fried.', 'price_cents' => 825],
                ['name' => 'Smashed Cucumber', 'description' => 'Garlic, black vinegar, chili crisp.', 'price_cents' => 550],
            ],
            'Drinks' => [
                ['name' => 'Vietnamese Iced Coffee', 'description' => 'Condensed milk, slow drip.', 'price_cents' => 475],
                ['name' => 'Jasmine Iced Tea', 'description' => 'Unsweetened unless you say otherwise.', 'price_cents' => 325, 'option_groups' => [
                    ['name' => 'Sweetness', 'min_select' => 1, 'max_select' => 1, 'required' => 1, 'options' => [['None', 0], ['Half', 0], ['Full', 0]]],
                ]],
            ],
        ];
    }
}
