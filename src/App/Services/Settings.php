<?php

namespace Keel\App\Services;

use Keel\Core\Database;

/**
 * Typed reader for the settings table.
 *
 * Every row is loaded once per request and held in a static cache, so a page
 * that reads a dozen pricing knobs still costs one query. Nothing here falls
 * back to a default: a missing key is a deployment mistake, not a value, and
 * throws. That is deliberate — the spec forbids hardcoded prices and rates, so
 * there is nowhere else for a default to legitimately live.
 *
 * Decimals come back as exact strings. Rates like processing_pct feed money
 * math, and handing that math a float is how cents go missing.
 */
class Settings
{
    /** @var array<string, array{value: string, type: string}>|null */
    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function has(string $key): bool
    {
        return isset(self::load()[$key]);
    }

    /**
     * The value cast by its declared type.
     */
    public static function get(string $key): int|string|bool|array
    {
        $row = self::row($key);

        return match ($row['type']) {
            'int' => (int) $row['value'],
            'bool' => self::toBool($row['value']),
            'json' => self::toJson($key, $row['value']),
            default => (string) $row['value'],
        };
    }

    public static function int(string $key): int
    {
        return (int) self::typed($key, 'int')['value'];
    }

    /**
     * An exact decimal string, never a float.
     */
    public static function decimal(string $key): string
    {
        return (string) self::typed($key, 'decimal')['value'];
    }

    public static function string(string $key): string
    {
        return (string) self::typed($key, 'string')['value'];
    }

    public static function bool(string $key): bool
    {
        return self::toBool(self::typed($key, 'bool')['value']);
    }

    public static function json(string $key): array
    {
        return self::toJson($key, self::typed($key, 'json')['value']);
    }

    /**
     * The declared type of a key, for admin screens that render an editor.
     */
    public static function typeOf(string $key): string
    {
        return self::row($key)['type'];
    }

    /**
     * Every key and its cast value, for the snapshot written onto an order.
     *
     * @return array<string, int|string|bool|array>
     */
    public static function snapshot(array $keys): array
    {
        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = self::get($key);
        }

        return $snapshot;
    }

    public static function put(string $key, string $value, string $type = 'string'): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO settings (`key`, `value`, type) VALUES (:key, :value, :type)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), type = VALUES(type)'
        );
        $statement->execute(['key' => $key, 'value' => $value, 'type' => $type]);

        self::flush();
    }

    /**
     * @return array{value: string, type: string}
     */
    private static function row(string $key): array
    {
        $rows = self::load();

        if (!isset($rows[$key])) {
            throw MissingSettingException::forKey($key);
        }

        return $rows[$key];
    }

    /**
     * @return array{value: string, type: string}
     */
    private static function typed(string $key, string $expected): array
    {
        $row = self::row($key);

        if ($row['type'] !== $expected) {
            throw MissingSettingException::forType($key, $expected, $row['type']);
        }

        return $row;
    }

    /**
     * @return array<string, array{value: string, type: string}>
     */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $statement = Database::connection()->query('SELECT `key`, `value`, type FROM settings');

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['key']] = [
                'value' => (string) $row['value'],
                'type' => (string) $row['type'],
            ];
        }

        return self::$cache = $rows;
    }

    private static function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function toJson(string $key, string $value): array
    {
        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            throw new MissingSettingException("Setting \"{$key}\" does not hold valid JSON.");
        }

        return $decoded;
    }
}
