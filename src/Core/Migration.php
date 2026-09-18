<?php

namespace Keel\Core;

/**
 * Splits a migration file into its up and down halves.
 *
 * Keel's migrations are plain .sql files applied in natural filename order. A
 * file may mark a rollback section with a `-- @down` line; everything above it
 * is the up migration, everything below it is the down migration. Files with no
 * marker are all up and cannot be rolled back, which keeps the ten migrations
 * that predate this helper working unchanged.
 */
class Migration
{
    private const DOWN_MARKER = '/^[ \t]*--[ \t]*@down[ \t]*$/mi';

    public static function up(string $sql): string
    {
        return trim(self::split($sql)[0]);
    }

    public static function down(string $sql): ?string
    {
        $down = self::split($sql)[1];

        return $down === null ? null : trim($down);
    }

    public static function isReversible(string $sql): bool
    {
        $down = self::down($sql);

        return $down !== null && $down !== '';
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function split(string $sql): array
    {
        $parts = preg_split(self::DOWN_MARKER, $sql, 2);

        if (!is_array($parts) || count($parts) < 2) {
            return [$sql, null];
        }

        return [$parts[0], $parts[1]];
    }
}
