<?php

namespace Keel\App\Models;

/**
 * Raw row access for the settings table. Application code reads settings
 * through the Settings service, which caches and casts; this is for the admin
 * screens that list and edit them.
 */
class Setting extends Model
{
    protected const TABLE = 'settings';

    protected const COLUMNS = ['key', 'value', 'type'];

    public const TYPES = ['int', 'decimal', 'string', 'bool', 'json'];

    public static function all(): array
    {
        return self::query('SELECT * FROM settings ORDER BY `key` ASC');
    }

    public static function findByKey(string $key): ?array
    {
        return self::firstBy('key', $key);
    }
}
