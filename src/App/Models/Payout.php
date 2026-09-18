<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * One row per transfer out, and the memory that makes the transfer safe to
 * retry.
 *
 * idempotency_key is the row's identity rather than a detail on it. It is
 * computed from the order and what the payout is for, it is unique across the
 * table, and it is the same string handed to Stripe. So the second attempt at a
 * payout — a replayed webhook, a job the worker released, a person clicking
 * twice — finds the row rather than making one, and Stripe returns the original
 * transfer rather than making a second.
 */
class Payout extends Model
{
    protected const TABLE = 'payouts';

    protected const COLUMNS = [
        'order_id', 'type', 'idempotency_key', 'recipient_account', 'amount_cents',
        'reversed_cents', 'stripe_transfer_id', 'status', 'attempts', 'last_error',
    ];

    public const TYPE_RESTAURANT = 'restaurant';
    public const TYPE_DRIVER = 'driver';
    public const TYPE_DRIVER_TIP_ADJUST = 'driver_tip_adjust';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    /** Held: the recipient cannot be paid yet. Never dropped, never retried blind. */
    public const STATUS_HELD = 'held';

    public static function forOrder(int $orderId): array
    {
        return self::allBy('order_id', $orderId, 'id ASC');
    }

    public static function forOrderAndType(int $orderId, string $type): ?array
    {
        return self::queryOne(
            'SELECT * FROM payouts WHERE order_id = ? AND type = ? ORDER BY id DESC LIMIT 1',
            [$orderId, $type]
        );
    }

    public static function findByIdempotencyKey(string $key): ?array
    {
        return trim($key) === '' ? null : self::firstBy('idempotency_key', $key);
    }

    public static function findByTransfer(string $transferId): ?array
    {
        return trim($transferId) === '' ? null : self::firstBy('stripe_transfer_id', $transferId);
    }

    public static function pending(): array
    {
        return self::allBy('status', self::STATUS_PENDING, 'id ASC');
    }

    public static function held(): array
    {
        return self::allBy('status', self::STATUS_HELD, 'id ASC');
    }

    /**
     * Payouts that are not going to move on their own.
     *
     * Held is waiting on a connected account somebody has to fix; failed gave
     * up after its retries. Both are money still owed, and the admin screen
     * exists so that neither sits in a table nobody reads.
     */
    public static function needingAttention(): array
    {
        return self::query(
            'SELECT p.*, o.restaurant_id, r.name AS restaurant_name
             FROM payouts p
             INNER JOIN orders o ON o.id = p.order_id
             INNER JOIN restaurants r ON r.id = o.restaurant_id
             WHERE p.status IN (?, ?)
             ORDER BY p.id ASC',
            [self::STATUS_HELD, self::STATUS_FAILED]
        );
    }

    /**
     * Every held payout waiting on one connected account.
     *
     * What account.updated reads when Stripe says a recipient can be paid
     * again: the work that was parked is exactly the work to put back.
     */
    public static function heldForAccount(string $accountId): array
    {
        return self::query(
            'SELECT * FROM payouts WHERE status = ? AND recipient_account = ? ORDER BY id ASC',
            [self::STATUS_HELD, $accountId]
        );
    }

    /**
     * The row for this key, created if it is not there yet, without a race.
     *
     * INSERT IGNORE then read, rather than SELECT then INSERT: two deliveries of
     * the same delivered-order event can be in flight at once, and only the
     * unique index settles which of them made the row.
     *
     * @param array<string, mixed> $attributes
     * @return array{0: array<string, mixed>, 1: bool} the row, and whether this
     *         caller is the one that created it
     */
    public static function claim(string $idempotencyKey, array $attributes): array
    {
        $attributes['idempotency_key'] = $idempotencyKey;

        $columns = ['order_id', 'type', 'idempotency_key', 'recipient_account', 'amount_cents'];
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $statement = Database::connection()->prepare(
            'INSERT IGNORE INTO payouts (`' . implode('`, `', $columns) . '`)'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
        );

        foreach ($columns as $column) {
            $statement->bindValue(':' . $column, $attributes[$column] ?? null);
        }

        $statement->execute();

        $created = $statement->rowCount() === 1;
        $row = self::findByIdempotencyKey($idempotencyKey);

        if ($row === null) {
            throw new \RuntimeException("Payout \"{$idempotencyKey}\" could not be claimed.");
        }

        return [$row, $created];
    }

    /**
     * What has actually left the platform for this order, per recipient type,
     * net of anything reversed.
     */
    public static function paidCents(int $orderId, string $type): int
    {
        $row = self::queryOne(
            'SELECT COALESCE(SUM(amount_cents - reversed_cents), 0) AS total
             FROM payouts
             WHERE order_id = ? AND type = ? AND status = ?',
            [$orderId, $type, self::STATUS_PAID]
        );

        return (int) ($row['total'] ?? 0);
    }
}
