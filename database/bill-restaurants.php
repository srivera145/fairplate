<?php

declare(strict_types=1);

use Keel\App\Jobs\MonthlyRestaurantBillingJob;
use Keel\App\Models\Restaurant;
use Keel\App\Models\RestaurantMonthlyStatement;
use Keel\App\Services\Billing\TierBillingService;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

/**
 * The monthly restaurant billing run, as a thing cron can actually call.
 *
 * Run inline rather than pushed onto the queue, for the same reason the
 * retention and authorization sweeps are: work that is queued and then never
 * runs because the worker is down fails silently, and this is the one job in
 * the application whose silence costs the company a month of revenue. Here it
 * either prints what it billed or exits non-zero where cron can see it.
 *
 *   # 06:00 on the 1st, America/New_York
 *   CRON_TZ=America/New_York
 *   0 6 1 * *  php /path/to/fairplate/database/bill-restaurants.php
 *
 * The zone on that first line is not optional. Billing periods are
 * America/New_York calendar months, and a crontab running in UTC fires this at
 * 1am or 2am Eastern depending on the time of year — still the right month, but
 * only by luck, and on the wrong side of the boundary the day the host is moved
 * to a different zone. CRON_TZ is Vixie cron; on a host without it, use
 * `TZ=America/New_York php ...` or schedule at the UTC hour that matches.
 *
 * Running it twice for the same month produces one statement and one invoice,
 * so a retried cron entry, a re-run by hand and a host that fired it twice are
 * all harmless. That is what makes --period safe:
 *
 *   php database/bill-restaurants.php --period=2026-03
 *   php database/bill-restaurants.php --restaurant=12
 *
 * --restaurant is for the case the job leaves behind on purpose: a custom-tier
 * statement marked needs_review, which an admin picks up by setting
 * custom_fee_cents and then re-running for that one restaurant.
 */

Env::load(dirname(__DIR__));

$period = TierBillingService::previousPeriod();
$restaurantId = 0;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--period=(\d{4}-\d{2})$/', $argument, $matches) === 1) {
        $period = $matches[1];
        continue;
    }

    if (preg_match('/^--restaurant=(\d+)$/', $argument, $matches) === 1) {
        $restaurantId = (int) $matches[1];
        continue;
    }

    fwrite(STDERR, "Unknown argument \"{$argument}\".\n");
    fwrite(STDERR, "Usage: bill-restaurants.php [--period=YYYY-MM] [--restaurant=ID]\n");
    exit(1);
}

try {
    $before = statementsIn($period);

    (new MonthlyRestaurantBillingJob())->handle(array_filter([
        'period' => $period,
        'restaurant_id' => $restaurantId ?: null,
    ]));

    $after = statementsIn($period);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Billing failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

printf(
    "Billed %s. %d statement(s), %d new this run.\n",
    TierBillingService::periodName($period),
    count($after),
    max(0, count($after) - count($before))
);

foreach ($after as $statement) {
    $restaurant = Restaurant::find((int) $statement['restaurant_id']);

    printf(
        "  %-28s %4d orders  %10s  %-24s %s\n",
        substr((string) ($restaurant['name'] ?? '#' . $statement['restaurant_id']), 0, 28),
        (int) $statement['orders'],
        Money::usd((int) $statement['fee_cents']),
        (string) $statement['status'],
        (string) ($statement['stripe_invoice_id'] ?? '')
    );
}

$needsReview = array_filter(
    $after,
    static fn (array $statement): bool =>
        (string) $statement['status'] === RestaurantMonthlyStatement::STATUS_NEEDS_REVIEW
);

if ($needsReview !== []) {
    printf(
        "\n%d statement(s) need a custom fee set before they can be invoiced.\n",
        count($needsReview)
    );
}

/**
 * @return list<array<string, mixed>>
 */
function statementsIn(string $period): array
{
    return RestaurantMonthlyStatement::inPeriod($period);
}
