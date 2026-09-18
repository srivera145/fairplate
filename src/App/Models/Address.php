<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class Address extends Model
{
    protected const TABLE = 'addresses';

    protected const COLUMNS = [
        'user_id', 'label', 'line1', 'line2', 'city', 'state', 'zip',
        'lat', 'lng', 'instructions', 'is_default',
    ];

    /**
     * The address book, default first, so the list reads the way checkout
     * behaves.
     */
    public static function forUser(int $userId): array
    {
        return self::query(
            'SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id ASC',
            [$userId]
        );
    }

    public static function forUserAndId(int $userId, int $addressId): ?array
    {
        return self::queryOne(
            'SELECT * FROM addresses WHERE id = ? AND user_id = ? LIMIT 1',
            [$addressId, $userId]
        );
    }

    /**
     * Where an order goes unless the customer says otherwise.
     *
     * Falls back to the oldest saved address, because a customer with one
     * address and no flag set still has an obvious answer.
     */
    public static function defaultForUser(int $userId): ?array
    {
        return self::queryOne(
            'SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id ASC LIMIT 1',
            [$userId]
        );
    }

    /**
     * Makes one address the default and clears the flag from the rest, in that
     * order, so the book is never briefly without one.
     *
     * The set is scoped to the user and the clear only runs if it matched, so an
     * id belonging to somebody else cannot leave this customer with no default
     * at all.
     */
    public static function makeDefault(int $userId, int $addressId): void
    {
        $connection = Database::connection();

        $set = $connection->prepare('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?');
        $set->execute([$addressId, $userId]);

        if ($set->rowCount() === 0 && self::forUserAndId($userId, $addressId) === null) {
            return;
        }

        $clear = $connection->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ? AND id <> ?');
        $clear->execute([$userId, $addressId]);
    }

    /**
     * Hands the default to whatever is left after one is deleted. A book with
     * nothing in it simply has no default.
     */
    public static function ensureDefault(int $userId): void
    {
        $current = self::queryOne(
            'SELECT id FROM addresses WHERE user_id = ? AND is_default = 1 LIMIT 1',
            [$userId]
        );

        if ($current !== null) {
            return;
        }

        $next = self::queryOne('SELECT id FROM addresses WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$userId]);

        if ($next !== null) {
            self::makeDefault($userId, (int) $next['id']);
        }
    }

    /**
     * One line, the way a card or a receipt shows it.
     */
    public static function oneLine(array $address): string
    {
        $parts = array_filter([
            trim((string) ($address['line1'] ?? '')),
            trim((string) ($address['line2'] ?? '')),
            trim((string) ($address['city'] ?? '')),
            trim(trim((string) ($address['state'] ?? '')) . ' ' . trim((string) ($address['zip'] ?? ''))),
        ], static fn (string $part): bool => $part !== '');

        return implode(', ', $parts);
    }

    /**
     * The frozen copy written onto an order at checkout.
     */
    public static function snapshot(array $address): array
    {
        return [
            'label' => $address['label'] ?? null,
            'line1' => $address['line1'] ?? null,
            'line2' => $address['line2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'zip' => $address['zip'] ?? null,
            'lat' => $address['lat'] ?? null,
            'lng' => $address['lng'] ?? null,
            'instructions' => $address['instructions'] ?? null,
        ];
    }
}
