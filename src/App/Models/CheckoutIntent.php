<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * A quote frozen against a PaymentIntent, waiting for the webhook to turn it
 * into an order.
 *
 * Everything the order needs is in here because the webhook has no session, no
 * cart and no browser to ask. claim() is the part that matters: it moves the row
 * out of `pending` in one conditional statement, so a redelivered event finds
 * nothing to claim and returns without writing a second order.
 */
class CheckoutIntent extends Model
{
    protected const TABLE = 'checkout_intents';

    protected const COLUMNS = [
        'user_id', 'restaurant_id', 'address_id', 'stripe_payment_intent_id',
        'address_snapshot', 'cart_snapshot', 'quote_snapshot', 'route_miles',
        'is_member', 'estimate_cents', 'authorized_cents', 'status', 'order_id',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PLACED = 'placed';
    public const STATUS_ABANDONED = 'abandoned';

    public static function findByPaymentIntent(string $paymentIntentId): ?array
    {
        return self::firstBy('stripe_payment_intent_id', $paymentIntentId);
    }

    public static function forUserAndPaymentIntent(int $userId, string $paymentIntentId): ?array
    {
        return self::queryOne(
            'SELECT * FROM checkout_intents WHERE stripe_payment_intent_id = ? AND user_id = ? LIMIT 1',
            [$paymentIntentId, $userId]
        );
    }

    /**
     * The customer's open intent, if they have left one behind.
     */
    public static function pendingForUser(int $userId): ?array
    {
        return self::queryOne(
            'SELECT * FROM checkout_intents WHERE user_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$userId, self::STATUS_PENDING]
        );
    }

    /**
     * Takes this intent out of `pending`, and says whether it was this caller
     * who took it.
     *
     * False means somebody got there first — a redelivered webhook, or two
     * arriving together — and the caller must not place an order.
     */
    public static function claim(int $intentId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE checkout_intents SET status = ? WHERE id = ? AND status = ?'
        );
        $statement->execute([self::STATUS_PLACED, $intentId, self::STATUS_PENDING]);

        return $statement->rowCount() === 1;
    }

    /**
     * Drops any open intent for this customer, so an abandoned checkout does not
     * leave a stale payment intent that a late webhook could still place.
     */
    public static function abandonOpenFor(int $userId, ?int $exceptId = null): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE checkout_intents SET status = ? WHERE user_id = ? AND status = ? AND id <> ?'
        );
        $statement->execute([self::STATUS_ABANDONED, $userId, self::STATUS_PENDING, $exceptId ?? 0]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(array $intent, string $column): array
    {
        $value = $intent[$column] ?? null;

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
