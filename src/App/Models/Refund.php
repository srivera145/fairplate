<?php

namespace Keel\App\Models;

class Refund extends Model
{
    protected const TABLE = 'refunds';

    protected const COLUMNS = [
        'order_id', 'idempotency_key', 'amount_cents', 'reason', 'restaurant_error',
        'processing_absorbed_cents', 'reversed_restaurant_cents', 'stripe_refund_id',
        'stripe_transfer_reversal_id',
    ];

    public static function forOrder(int $orderId): array
    {
        return self::allBy('order_id', $orderId, 'id ASC');
    }

    public static function findByIdempotencyKey(string $key): ?array
    {
        return trim($key) === '' ? null : self::firstBy('idempotency_key', $key);
    }

    public static function findByStripeRefund(string $refundId): ?array
    {
        return trim($refundId) === '' ? null : self::firstBy('stripe_refund_id', $refundId);
    }

    public static function totalForOrderCents(int $orderId): int
    {
        $row = self::queryOne(
            'SELECT COALESCE(SUM(amount_cents), 0) AS total FROM refunds WHERE order_id = ?',
            [$orderId]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * The processing already written off against this order.
     *
     * Stripe keeps the whole original fee on the first refund and nothing more
     * on the ones after it, so a second refund on the same charge absorbs zero.
     * PaymentService needs this to know which case it is in.
     */
    public static function absorbedForOrderCents(int $orderId): int
    {
        $row = self::queryOne(
            'SELECT COALESCE(SUM(processing_absorbed_cents), 0) AS total FROM refunds WHERE order_id = ?',
            [$orderId]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * How much of the restaurant's transfer has already been pulled back.
     */
    public static function reversedRestaurantCents(int $orderId): int
    {
        $row = self::queryOne(
            'SELECT COALESCE(SUM(reversed_restaurant_cents), 0) AS total FROM refunds WHERE order_id = ?',
            [$orderId]
        );

        return (int) ($row['total'] ?? 0);
    }
}
