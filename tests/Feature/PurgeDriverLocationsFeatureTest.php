<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Jobs\PurgeDriverLocationsJob;
use Keel\App\Models\DriverLocation;
use Keel\Core\Database;
use Tests\Support\DriverFixtures;
use Tests\TestCase;

/**
 * The retention sweep.
 *
 * driver_locations is the one table here that grows with the clock rather than
 * with the business, and none of it is load-bearing once the order it belonged
 * to is settled. The test that matters is the boundary: thirty days is the line
 * and the sweep must not take anything a dispute could still need.
 */
class PurgeDriverLocationsFeatureTest extends TestCase
{
    use DriverFixtures;

    public function testItKeepsThirtyDaysAndDropsTheRest(): void
    {
        $this->seedDispatchSettings();
        $driverId = $this->createDispatchableDriver();

        $today = $this->pingAgedDays($driverId, 0);
        $recent = $this->pingAgedDays($driverId, 29);
        $old = $this->pingAgedDays($driverId, 31);
        $ancient = $this->pingAgedDays($driverId, 400);

        (new PurgeDriverLocationsJob())->handle([]);

        self::assertNotNull(DriverLocation::find($today));
        self::assertNotNull(DriverLocation::find($recent), '29 days old is inside the window');
        self::assertNull(DriverLocation::find($old), '31 days old is outside it');
        self::assertNull(DriverLocation::find($ancient));
    }

    public function testRunningItTwiceIsHarmless(): void
    {
        $this->seedDispatchSettings();
        $driverId = $this->createDispatchableDriver();

        $kept = $this->pingAgedDays($driverId, 1);
        $this->pingAgedDays($driverId, 90);

        $job = new PurgeDriverLocationsJob();
        $job->handle([]);
        $job->handle([]);

        self::assertSame(1, DriverLocation::count());
        self::assertNotNull(DriverLocation::find($kept));
    }

    public function testTheWindowCanBeOverriddenForABackfill(): void
    {
        $this->seedDispatchSettings();
        $driverId = $this->createDispatchableDriver();

        $this->pingAgedDays($driverId, 5);
        $kept = $this->pingAgedDays($driverId, 1);

        (new PurgeDriverLocationsJob())->handle(['days' => 3]);

        self::assertSame(1, DriverLocation::count());
        self::assertNotNull(DriverLocation::find($kept));
    }

    /**
     * A ping, then its timestamp moved back. The table has no way to make a row
     * old, so the row is made and then aged.
     */
    private function pingAgedDays(int $driverId, int $days): int
    {
        $id = DriverLocation::record($driverId, 30.4383, -84.2807);

        if ($days > 0) {
            $statement = Database::connection()->prepare(
                'UPDATE driver_locations SET recorded_at = DATE_SUB(recorded_at, INTERVAL ? DAY) WHERE id = ?'
            );
            $statement->execute([$days, $id]);
        }

        return $id;
    }
}
