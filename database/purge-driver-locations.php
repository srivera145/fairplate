<?php

declare(strict_types=1);

use Keel\App\Jobs\PurgeDriverLocationsJob;
use Keel\App\Models\DriverLocation;
use Keel\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

/**
 * The daily retention sweep, as a thing cron can actually call.
 *
 * Run inline rather than pushed onto the queue. A maintenance task that is
 * queued and then never run because the worker is down fails silently and
 * invisibly for as long as nobody looks at the table; run here it either
 * prints what it deleted or exits non-zero where cron can see it.
 *
 *   # Every day at 04:10, America/New_York
 *   10 4 * * *  php /path/to/fairplate/database/purge-driver-locations.php
 *
 * Takes an optional number of days, for a one-off backfill:
 *
 *   php database/purge-driver-locations.php --days=90
 */

Env::load(dirname(__DIR__));

$days = DriverLocation::RETENTION_DAYS;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--days=(\d+)$/', $argument, $matches) === 1) {
        $days = max(1, (int) $matches[1]);
        continue;
    }

    fwrite(STDERR, "Unknown argument \"{$argument}\".\n");
    exit(1);
}

try {
    $before = DriverLocation::count();
    (new PurgeDriverLocationsJob())->handle(['days' => $days]);
    $after = DriverLocation::count();
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Purge failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

printf(
    "Purged %d driver location rows older than %d days. %d remain.\n",
    $before - $after,
    $days,
    $after
);
