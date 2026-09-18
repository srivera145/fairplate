<?php

declare(strict_types=1);

use Keel\Core\Env;
use Keel\Core\Migration;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$basePath = dirname(__DIR__);
Env::load($basePath);

$pretend = in_array('--pretend', $argv, true);
$all = in_array('--all', $argv, true);
$steps = $all ? PHP_INT_MAX : max(1, rollbackSteps($argv));

try {
    $pdo = connectForRollback();

    $applied = appliedMigrationsNewestFirst($pdo);

    if ($applied === []) {
        fwrite(STDOUT, "Nothing to roll back.\n");
        exit(0);
    }

    $rolledBack = 0;

    foreach ($applied as $migrationName) {
        if ($rolledBack >= $steps) {
            break;
        }

        $migrationFile = $basePath . '/database/migrations/' . $migrationName;

        if (!is_file($migrationFile)) {
            fwrite(STDERR, "Cannot roll back {$migrationName}: the migration file is missing.\n");
            exit(1);
        }

        $contents = (string) file_get_contents($migrationFile);

        if (!Migration::isReversible($contents)) {
            fwrite(STDERR, "Cannot roll back {$migrationName}: it has no -- @down section.\n");
            exit(1);
        }

        if ($pretend) {
            fwrite(STDOUT, "Would roll back {$migrationName}\n");
            $rolledBack++;
            continue;
        }

        fwrite(STDOUT, "Rolling back {$migrationName}...\n");

        try {
            $pdo->exec((string) Migration::down($contents));

            $statement = $pdo->prepare('DELETE FROM migrations WHERE name = :name');
            $statement->execute(['name' => $migrationName]);

            fwrite(STDOUT, "Rolled back {$migrationName}\n");
        } catch (\Throwable $exception) {
            fwrite(STDERR, "Rollback failed for {$migrationName}: {$exception->getMessage()}\n");
            exit(1);
        }

        $rolledBack++;
    }

    fwrite(STDOUT, 'Rolled back ' . $rolledBack . " migration(s).\n");
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

function rollbackSteps(array $argv): int
{
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, '--step=')) {
            return (int) substr((string) $argument, 7);
        }
    }

    return 1;
}

function connectForRollback(): \PDO
{
    $host = (string) Env::get('DB_HOST', '127.0.0.1');
    $port = (string) Env::get('DB_PORT', '3306');
    $name = (string) Env::get('DB_DATABASE');
    $user = (string) Env::get('DB_USERNAME');
    $pass = (string) Env::get('DB_PASSWORD');
    $charset = (string) Env::get('DB_CHARSET', 'utf8mb4');

    if ($name === '') {
        throw new \RuntimeException('DB_DATABASE must be set before rolling back migrations.');
    }

    return new \PDO("mysql:host={$host};port={$port};dbname={$name};charset={$charset}", $user, $pass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function appliedMigrationsNewestFirst(\PDO $pdo): array
{
    $tableExists = $pdo->query("SHOW TABLES LIKE 'migrations'")->fetchColumn();

    if ($tableExists === false) {
        return [];
    }

    $names = $pdo->query('SELECT name FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
    $names = array_map('strval', $names);

    sort($names, SORT_NATURAL);

    return array_reverse($names);
}
