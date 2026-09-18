<?php

namespace Keel\App\Jobs;

use Keel\App\Models\DriverLocation;
use Keel\Core\Activity;

/**
 * Drop location pings older than the retention window. Run once a day.
 *
 * driver_locations is the one table here that grows with the clock rather than
 * with the business: four to six pings a minute for every driver on shift,
 * whether or not they are carrying anything. Left alone it is the largest table
 * in the database within a month and the largest by an order of magnitude
 * within a year, and none of it is load-bearing after the order it belonged to
 * is settled.
 *
 * Thirty days is long enough to answer "where was the driver" about any
 * delivery somebody is still disputing, and short enough that FairPlate is not
 * quietly keeping a permanent record of everywhere its drivers have ever been.
 * That second half is the point: drivers here are independent, and a trail kept
 * forever is a thing the company would eventually be asked to hand over.
 *
 * Scheduling is the deployment's job — cron, or whatever the host runs — the
 * same way the queue worker is. database/purge-driver-locations.php is the
 * entry point and runs this inline rather than queueing it, so a worker that is
 * down cannot turn the sweep into something that silently never happens:
 *
 *   10 4 * * *  php /path/to/fairplate/database/purge-driver-locations.php
 *
 * It is idempotent, so a day that runs it twice, or a week that misses it,
 * costs nothing but the rows.
 */
class PurgeDriverLocationsJob implements Job
{
    public function handle(array $data): void
    {
        $days = (int) ($data['days'] ?? DriverLocation::RETENTION_DAYS);
        $deleted = DriverLocation::purgeOlderThan($days);

        if ($deleted > 0) {
            Activity::log('driver_locations.purged', null, null, [
                'deleted' => $deleted,
                'older_than_days' => $days,
            ]);
        }
    }
}
