<?php

declare(strict_types=1);

namespace Tests\Support;

use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\OrderItem;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Models\Restaurant;
use Keel\App\Models\User;
use Keel\App\Services\Routing\FakeRoutingService;
use Keel\App\Services\Pricing\PricingService;
use Keel\App\Services\Settings;
use Keel\Core\Auth;
use Keel\Core\Database;
use Keel\Core\Session;

/**
 * What a driver or dispatch test needs before it can say anything.
 *
 * Two of these are worth reading rather than skimming.
 *
 * createPricedOrder() does not fabricate a breakdown. It runs a real
 * PricingService quote and writes the estimate and the authorization it
 * produces, because half of what these tests check is that the driver app's
 * numbers agree with the customer's — and a hand-written breakdown row would
 * make that agreement true by construction rather than by code.
 *
 * drainQueue() runs the queue in-process. Dispatch is queued rather than inline
 * so that a kitchen tapping Accept is not waiting on a driver search, which
 * means a test that stops at the tap has only proved that a row landed in the
 * jobs table. This runs it the way the worker would.
 */
trait DriverFixtures
{
    use CustomerFixtures;

    /** The spec's dispatch knobs, which are settings rather than constants. */
    protected const DISPATCH_SETTINGS = [
        ['offer_timeout_seconds', '45', 'int'],
        ['dispatch_max_rounds', '5', 'int'],
        ['dispatch_max_minutes', '8', 'int'],
        ['location_ping_online_seconds', '15', 'int'],
        ['location_ping_active_seconds', '10', 'int'],
    ];

    protected function seedDispatchSettings(): void
    {
        $this->seedPricingSettings();

        foreach (self::DISPATCH_SETTINGS as [$key, $value, $type]) {
            Settings::put($key, $value, $type);
        }

        Settings::flush();
    }

    /**
     * A driver who is approved, online, idle and has just reported a position.
     *
     * Everything dispatch requires, so a test that wants one of those things to
     * be false says so in the overrides rather than in six lines of setup.
     */
    protected function createDispatchableDriver(
        string $name = 'Dana Ruiz',
        ?float $lat = null,
        ?float $lng = null,
        array $overrides = []
    ): int {
        $user = $this->createUser([
            'role' => User::ROLE_DRIVER,
            'name' => $name,
            'phone' => $this->uniquePhone(),
        ]);

        return Driver::create(array_merge([
            'user_id' => (int) $user['id'],
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Corolla',
            'vehicle_color' => 'Silver',
            'plate' => 'LEON' . random_int(100, 999),
            'approved' => 1,
            'online' => 1,
            'idle' => 1,
            'last_lat' => (string) ($lat ?? self::INSIDE_ZONE['lat']),
            'last_lng' => (string) ($lng ?? self::INSIDE_ZONE['lng']),
            'last_seen_at' => gmdate('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * Signs in as a driver's user.
     */
    protected function actingAsDriver(int $driverId): array
    {
        $driver = Driver::find($driverId) ?? [];

        Session::put('user_id', (int) $driver['user_id']);
        Auth::setUserId(null);

        return $driver;
    }

    /**
     * Signs in a driver-role user who has no driver record at all — the state a
     * brand new driver arrives in.
     */
    protected function actingAsUnregisteredDriver(): array
    {
        $user = $this->createUser([
            'role' => User::ROLE_DRIVER,
            'name' => 'Nobody Yet',
            'phone' => $this->uniquePhone(),
        ]);

        Session::put('user_id', (int) $user['id']);
        Auth::setUserId(null);

        return $user;
    }

    /**
     * A placed order with a real estimate and authorization on it.
     *
     * @return array{order_id: int, customer_id: int, authorization: \Keel\App\Services\Pricing\Breakdown, miles: float}
     */
    protected function createPricedOrder(
        int $restaurantId,
        float $miles = 3.0,
        int $tipCents = 200,
        array $overrides = []
    ): array {
        $restaurant = Restaurant::find($restaurantId) ?? [];
        $customer = $this->createUser([
            'role' => User::ROLE_CUSTOMER,
            'name' => 'Marisol Vega',
            'phone' => $this->uniquePhone(),
        ]);

        $address = [
            'label' => 'Home',
            'line1' => '9 Oak St',
            'city' => 'Tallahassee',
            'state' => 'FL',
            'zip' => '32301',
            'lat' => self::INSIDE_ZONE['lat'],
            'lng' => self::INSIDE_ZONE['lng'],
            'instructions' => $overrides['instructions'] ?? null,
        ];

        $quote = (new PricingService(new FakeRoutingService($miles)))->quote(
            [
                'items' => [[
                    'price_cents' => 750,
                    'quantity' => 1,
                    'menu_item_id' => null,
                    'taxable' => true,
                ]],
                'tip_cents' => $tipCents,
            ],
            $restaurant + ['specials' => []],
            $address,
            ['is_member' => false]
        );

        $orderId = Order::create(array_merge([
            'customer_id' => (int) $customer['id'],
            'restaurant_id' => $restaurantId,
            'address_snapshot' => (string) json_encode($address),
            'route_miles' => number_format($miles, 2, '.', ''),
            // status is left to the column default so nothing outside
            // OrderLifecycle ever writes an order status.
            'placed_at' => gmdate('Y-m-d H:i:s'),
            'authorized_cents' => $quote['authorization']->total(),
        ], array_diff_key($overrides, ['instructions' => null])));

        OrderItem::create([
            'order_id' => $orderId,
            'name_snapshot' => 'Al Pastor Taco',
            'unit_price_cents' => 750,
            'quantity' => 1,
            'line_total_cents' => 750,
        ]);

        OrderPriceBreakdown::create($quote['estimate']->toRow($orderId));
        OrderPriceBreakdown::create($quote['authorization']->toRow($orderId));

        return [
            'order_id' => $orderId,
            'customer_id' => (int) $customer['id'],
            'authorization' => $quote['authorization'],
            'miles' => $miles,
        ];
    }

    /**
     * Moves a priced order to accepted without going through the kitchen
     * screens, for a test that is about what happens next.
     */
    protected function acceptOrder(int $orderId, int $prepMinutes = 15): array
    {
        return \Keel\App\Services\OrderLifecycle::accept($orderId, $prepMinutes);
    }

    /**
     * An offer, aged so that its clock has already run out.
     *
     * A test cannot wait forty-five seconds, and sleeping through the timeout
     * would prove only that PHP can sleep. Moving the timestamps back is the
     * same state the worker would find.
     */
    protected function ageOffer(int $offerId, int $seconds): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE dispatch_offers
             SET offered_at = DATE_SUB(offered_at, INTERVAL ? SECOND),
                 expires_at = DATE_SUB(expires_at, INTERVAL ? SECOND)
             WHERE id = ?'
        );
        $statement->execute([$seconds, $seconds, $offerId]);
    }

    /**
     * Moves an order's timestamps back, for the clocks that are read off it —
     * the dispatch deadline and the wait between arriving and picking up.
     */
    protected function backdate(int $orderId, string $column, int $seconds): void
    {
        if (!in_array($column, Order::writableColumns(), true)) {
            throw new \InvalidArgumentException("Unknown order column \"{$column}\".");
        }

        $statement = Database::connection()->prepare(
            'UPDATE orders SET `' . $column . '` = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id = ?'
        );
        $statement->execute([$seconds, $orderId]);
    }

    /**
     * Runs the queue the way the worker does, in this process. Returns how many
     * jobs ran.
     *
     * Jobs that put themselves back with a delay are left alone, which is what
     * stops a retry loop from running forever here.
     */
    protected function drainQueue(int $max = 25): int
    {
        $connection = Database::connection();
        $ran = 0;

        for ($i = 0; $i < $max; $i++) {
            $row = $connection
                ->query('SELECT * FROM jobs WHERE available_at <= NOW() ORDER BY id ASC LIMIT 1')
                ->fetch();

            if (!$row) {
                break;
            }

            $delete = $connection->prepare('DELETE FROM jobs WHERE id = ?');
            $delete->execute([(int) $row['id']]);

            $jobClass = (string) $row['job_class'];
            $payload = json_decode((string) $row['payload'], true);

            (new $jobClass())->handle(is_array($payload) ? $payload : []);
            $ran++;
        }

        return $ran;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function queuedJobs(?string $jobClass = null): array
    {
        if ($jobClass === null) {
            return Database::connection()
                ->query('SELECT * FROM jobs ORDER BY id ASC')
                ->fetchAll();
        }

        $statement = Database::connection()->prepare('SELECT * FROM jobs WHERE job_class = ? ORDER BY id ASC');
        $statement->execute([$jobClass]);

        return $statement->fetchAll();
    }

    /**
     * The live offer for an order, read straight from the database.
     */
    protected function pendingOffer(int $orderId): ?array
    {
        return DispatchOffer::pendingForOrder($orderId);
    }
}
