<?php

namespace Keel\App\Models;

use Keel\Core\Database;
use PDO;

/**
 * Shared table access for the FairPlate models.
 *
 * Keel's first two models hand-wrote every statement, which is fine for two and
 * unmaintainable for two dozen. This holds only the mechanical parts: find,
 * insert, update, delete, and a couple of read helpers. Each model still writes
 * the queries that carry meaning.
 *
 * Nothing here interpolates caller input into SQL. Table and column names come
 * from the subclass constants, values always go through bound parameters, and
 * an attribute whose column is not declared in COLUMNS is dropped rather than
 * written.
 */
abstract class Model
{
    /** The table this model reads and writes. */
    protected const TABLE = '';

    /** Columns that may be written. Anything else in an attribute array is ignored. */
    protected const COLUMNS = [];

    public static function table(): string
    {
        return static::TABLE;
    }

    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM ' . static::quoted() . ' WHERE id = ? LIMIT 1'
        );
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    public static function create(array $attributes): int
    {
        $attributes = static::writable($attributes);

        if ($attributes === []) {
            throw new \InvalidArgumentException('Nothing writable was supplied for ' . static::TABLE . '.');
        }

        $columns = array_keys($attributes);
        $quotedColumns = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $statement = Database::connection()->prepare(
            'INSERT INTO ' . static::quoted()
            . ' (' . implode(', ', $quotedColumns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
        );

        foreach ($attributes as $column => $value) {
            $statement->bindValue(':' . $column, static::normalize($value), static::paramType($value));
        }

        $statement->execute();

        return (int) Database::connection()->lastInsertId();
    }

    public static function update(int $id, array $attributes): bool
    {
        $attributes = static::writable($attributes);

        if ($attributes === []) {
            return false;
        }

        $assignments = [];

        foreach (array_keys($attributes) as $column) {
            $assignments[] = '`' . $column . '` = :' . $column;
        }

        $statement = Database::connection()->prepare(
            'UPDATE ' . static::quoted() . ' SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );

        foreach ($attributes as $column => $value) {
            $statement->bindValue(':' . $column, static::normalize($value), static::paramType($value));
        }

        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }

    public static function delete(int $id): bool
    {
        $statement = Database::connection()->prepare(
            'DELETE FROM ' . static::quoted() . ' WHERE id = ?'
        );

        return $statement->execute([$id]);
    }

    public static function count(): int
    {
        return (int) Database::connection()
            ->query('SELECT COUNT(*) FROM ' . static::quoted())
            ->fetchColumn();
    }

    /**
     * A single row matched on one declared column.
     */
    protected static function firstBy(string $column, mixed $value): ?array
    {
        static::assertColumn($column);

        $statement = Database::connection()->prepare(
            'SELECT * FROM ' . static::quoted() . ' WHERE `' . $column . '` = ? LIMIT 1'
        );
        $statement->execute([$value]);

        return $statement->fetch() ?: null;
    }

    /**
     * Every row matched on one declared column.
     */
    protected static function allBy(string $column, mixed $value, string $orderBy = 'id ASC'): array
    {
        static::assertColumn($column);

        $statement = Database::connection()->prepare(
            'SELECT * FROM ' . static::quoted() . ' WHERE `' . $column . '` = ? ORDER BY ' . $orderBy
        );
        $statement->execute([$value]);

        return $statement->fetchAll();
    }

    protected static function query(string $sql, array $bindings = []): array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    protected static function queryOne(string $sql, array $bindings = []): ?array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetch() ?: null;
    }

    protected static function quoted(): string
    {
        return '`' . static::TABLE . '`';
    }

    private static function writable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(static::COLUMNS));
    }

    private static function assertColumn(string $column): void
    {
        if (!in_array($column, static::COLUMNS, true) && $column !== 'id') {
            throw new \InvalidArgumentException("Unknown column \"{$column}\" on " . static::TABLE . '.');
        }
    }

    /** Booleans become the tinyint(1) the schema actually stores. */
    private static function normalize(mixed $value): mixed
    {
        return is_bool($value) ? (int) $value : $value;
    }

    private static function paramType(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_int($value), is_bool($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
    }
}
