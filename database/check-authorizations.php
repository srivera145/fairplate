<?php

declare(strict_types=1);

use Keel\App\Jobs\AuthorizationExpiryCheckJob;
use Keel\App\Models\Order;
use Keel\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

/**
 * The hourly authorization sweep, as a thing cron can actually call.
 *
 * Run inline rather than pushed onto the queue, for the same reason the
 * retention sweep is: a check that is queued and then never runs because the
 * worker is down is a check that fails exactly when it was most needed. Here it
 * either prints what it found or exits non-zero where cron can see it.
 *
 *   # Every hour, on the hour
 *   0 * * * *  php /path/to/fairplate/database/check-authorizations.php
 *
 * Takes an optional age in days, for looking further back by hand:
 *
 *   php database/check-authorizations.php --days=3
 */

Env::load(dirname(__DIR__));

$days = AuthorizationExpiryCheckJob::WARN_AFTER_DAYS;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--days=(\d+)$/', $argument, $matches) === 1) {
        $days = max(1, (int) $matches[1]);
        continue;
    }

    fwrite(STDERR, "Unknown argument \"{$argument}\".\n");
    exit(1);
}

try {
    $stale = Order::staleAuthorizations($days);
    (new AuthorizationExpiryCheckJob())->handle(['days' => $days]);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Authorization check failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

printf(
    "%d order(s) have held an uncaptured authorization for more than %d days.\n",
    count($stale),
    $days
);

foreach ($stale as $order) {
    printf(
        "  #%d  %s  held since %s  (%s)\n",
        (int) $order['id'],
        (string) $order['status'],
        (string) $order['placed_at'],
        (string) $order['stripe_payment_intent_id']
    );
}
