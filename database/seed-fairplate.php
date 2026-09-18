<?php

declare(strict_types=1);

use Keel\Core\Env;
use Keel\Database\Seeders\FairPlateSeeder;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

Env::load(dirname(__DIR__));

$appEnv = strtolower((string) Env::get('APP_ENV', 'production'));

if (!in_array($appEnv, ['local', 'dev', 'development', 'testing'], true)) {
    fwrite(STDERR, "Refusing to seed FairPlate data outside local/dev/testing environments.\n");
    exit(1);
}

try {
    $counts = (new FairPlateSeeder())->run();
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "FairPlate seed complete.\n");

foreach ($counts as $kind => $count) {
    fwrite(STDOUT, sprintf("  %-20s %d\n", str_replace('_', ' ', $kind), $count));
}
