<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\User;
use Keel\App\Services\ZoneService;
use Keel\Core\Database;
use Keel\Core\Session;
use Keel\Database\Seeders\FairPlateSeeder;
use Tests\TestCase;

/**
 * The seeded dataset is what every later phase develops against, so its shape
 * is worth pinning down.
 */
class FairPlateSeedFeatureTest extends TestCase
{
    public function testSeedCountsMatchTheSpec(): void
    {
        $counts = (new FairPlateSeeder())->run();

        self::assertSame(1, $counts['zones']);
        self::assertSame(17, $counts['settings']);
        self::assertSame(5, $counts['fee_tiers']);
        self::assertSame(3, $counts['restaurants']);
        self::assertSame(3, $counts['restaurant_staff']);
        self::assertSame(2, $counts['drivers']);
        self::assertSame(1, $counts['admins']);
        self::assertSame(2, $counts['customers']);
        self::assertSame(2, $counts['addresses']);
    }

    public function testEachRestaurantHasTheMenuShapeTheSpecAsksFor(): void
    {
        (new FairPlateSeeder())->run();

        $rows = Database::connection()->query(
            'SELECT r.id, r.name, r.founding_discount_pct,
                    (SELECT COUNT(*) FROM menu_categories c WHERE c.restaurant_id = r.id) AS categories,
                    (SELECT COUNT(*) FROM menu_items i WHERE i.restaurant_id = r.id) AS items,
                    (SELECT COUNT(*) FROM specials s WHERE s.restaurant_id = r.id) AS specials
             FROM restaurants r'
        )->fetchAll();

        self::assertCount(3, $rows);

        foreach ($rows as $row) {
            self::assertGreaterThanOrEqual(2, (int) $row['categories'], $row['name'] . ' categories');
            self::assertLessThanOrEqual(3, (int) $row['categories'], $row['name'] . ' categories');
            self::assertGreaterThanOrEqual(8, (int) $row['items'], $row['name'] . ' items');
            self::assertLessThanOrEqual(12, (int) $row['items'], $row['name'] . ' items');
            self::assertGreaterThanOrEqual(1, (int) $row['specials'], $row['name'] . ' specials');
            self::assertLessThanOrEqual(2, (int) $row['specials'], $row['name'] . ' specials');
        }

        $founding = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (float) $row['founding_discount_pct'] > 0
        ));

        self::assertCount(1, $founding, 'exactly one founding partner');
        self::assertSame('0.2000', (string) $founding[0]['founding_discount_pct']);
    }

    public function testEveryItemHasAtLeastOneOptionGroupSomewhereInTheMenu(): void
    {
        (new FairPlateSeeder())->run();

        $groups = (int) Database::connection()->query('SELECT COUNT(*) FROM item_option_groups')->fetchColumn();
        $options = (int) Database::connection()->query('SELECT COUNT(*) FROM item_options')->fetchColumn();

        self::assertGreaterThan(0, $groups);
        self::assertGreaterThan($groups, $options, 'every group needs options');
    }

    public function testFeeTiersMatchTheSpecLadder(): void
    {
        (new FairPlateSeeder())->run();

        $tiers = Database::connection()
            ->query('SELECT min_orders, max_orders, fee_cents, is_custom FROM restaurant_fee_tiers ORDER BY sort')
            ->fetchAll();

        self::assertSame(
            [
                ['min' => 0, 'max' => 40, 'fee' => 0, 'custom' => 0],
                ['min' => 41, 'max' => 100, 'fee' => 24900, 'custom' => 0],
                ['min' => 101, 'max' => 250, 'fee' => 54900, 'custom' => 0],
                ['min' => 251, 'max' => 500, 'fee' => 99900, 'custom' => 0],
                ['min' => 501, 'max' => null, 'fee' => 0, 'custom' => 1],
            ],
            array_map(static fn (array $t): array => [
                'min' => (int) $t['min_orders'],
                'max' => $t['max_orders'] === null ? null : (int) $t['max_orders'],
                'fee' => (int) $t['fee_cents'],
                'custom' => (int) $t['is_custom'],
            ], $tiers)
        );
    }

    public function testTheSeededZoneCoversDowntownButNotAPointThirtyKilometresAway(): void
    {
        (new FairPlateSeeder())->run();

        self::assertTrue(ZoneService::contains(30.4383, -84.2807), 'downtown Tallahassee');

        $thirtyKmNorth = 30.4383 + (30000 / 111320);
        self::assertFalse(ZoneService::contains($thirtyKmNorth, -84.2807), '30 km away');
    }

    public function testSeedingTwiceLeavesTheSameCounts(): void
    {
        $first = (new FairPlateSeeder())->run();
        $second = (new FairPlateSeeder())->run();

        self::assertSame($first, $second);
        self::assertSame(3, (int) Database::connection()->query('SELECT COUNT(*) FROM restaurants')->fetchColumn());
    }

    public function testOneAccountExistsForEachRole(): void
    {
        (new FairPlateSeeder())->run();

        foreach (User::ROLES as $role) {
            self::assertNotEmpty(User::withRole($role), "a {$role} should be seeded");
        }
    }

    /**
     * Renders each placeholder dashboard as a real seeded account, so the
     * populated branches of the views run, not just the empty states.
     */
    public function testEachSeededRoleRendersItsDashboard(): void
    {
        $expectations = [
            User::ROLE_CUSTOMER => ['/app', 'Railroad Square Tacos'],
            User::ROLE_RESTAURANT_STAFF => ['/kitchen', 'Railroad Square Tacos'],
            // The seeded drivers are approved and offline, so this is the branch
            // of the home screen that only renders for somebody an admin has
            // already cleared — the vehicle itself now lives on the account
            // screen rather than on a screen a driver reads at arm's length.
            User::ROLE_DRIVER => ['/drive', 'Tap to go online'],
            User::ROLE_ADMIN => ['/admin', 'platform_fee_cents'],
        ];

        foreach ($expectations as $role => [$path, $needle]) {
            $this->setUp();
            (new FairPlateSeeder())->run();

            $user = User::withRole($role)[0];
            Session::put('user_id', (int) $user['id']);

            $response = $this->get($path);

            self::assertSame(200, $response->status, "{$role} dashboard should render");
            self::assertStringContainsString($needle, $response->body, "{$path} should show seeded data");
        }
    }
}
