<?php

namespace Keel\App\Models;

class RestaurantMonthlyStatement extends Model
{
    protected const TABLE = 'restaurant_monthly_statements';

    protected const COLUMNS = [
        'restaurant_id', 'period', 'orders', 'sales_subtotal_cents', 'tier_id',
        'fee_cents', 'discount_cents', 'commission_equiv_cents', 'savings_cents',
        'stripe_invoice_id', 'due_on', 'invoice_pdf_url', 'hosted_invoice_url', 'status',
    ];

    /** Counted, not yet settled. Only the billing job sees this for long. */
    public const STATUS_DRAFT = 'draft';

    /** Under the first tier. Nothing to invoice, and nothing left to happen. */
    public const STATUS_FREE = 'free';

    /** The custom tier with no figure agreed. An admin has been told. */
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_INVOICED = 'invoiced';
    public const STATUS_PAID = 'paid';
    public const STATUS_PAYMENT_FAILED = 'payment_failed';
    public const STATUS_VOID = 'void';

    /**
     * Statuses the billing job will not touch again.
     *
     * A re-run for a month that already invoiced, settled or was written off
     * must not produce a second statement or a second invoice. needs_review is
     * deliberately absent: that is the one state a re-run is *for*, once an
     * admin has set the custom fee.
     */
    public const SETTLED_STATUSES = [
        self::STATUS_FREE,
        self::STATUS_INVOICED,
        self::STATUS_PAID,
        self::STATUS_PAYMENT_FAILED,
        self::STATUS_VOID,
    ];

    public static function forRestaurant(int $restaurantId): array
    {
        return self::allBy('restaurant_id', $restaurantId, 'period DESC');
    }

    public static function forPeriod(int $restaurantId, string $period): ?array
    {
        return self::queryOne(
            'SELECT * FROM restaurant_monthly_statements WHERE restaurant_id = ? AND period = ? LIMIT 1',
            [$restaurantId, $period]
        );
    }

    public static function inPeriod(string $period): array
    {
        return self::allBy('period', $period, 'restaurant_id ASC');
    }

    /**
     * The statement an invoice webhook is about.
     */
    public static function findByInvoice(string $invoiceId): ?array
    {
        return trim($invoiceId) === '' ? null : self::firstBy('stripe_invoice_id', $invoiceId);
    }

    /**
     * The most recent statement whose charge did not go through, which is what
     * the kitchen banner is asking about.
     *
     * A banner, not a block: the spec is explicit that a failed payment never
     * pauses a restaurant, so this is read by the chrome and by nothing that
     * decides whether orders may be taken.
     */
    public static function unpaidFor(int $restaurantId): ?array
    {
        return self::queryOne(
            'SELECT * FROM restaurant_monthly_statements
             WHERE restaurant_id = ? AND status = ?
             ORDER BY period DESC
             LIMIT 1',
            [$restaurantId, self::STATUS_PAYMENT_FAILED]
        );
    }

    /**
     * Writes this month's statement, or returns the one already there.
     *
     * The unique index on (restaurant_id, period) is what makes the billing job
     * idempotent, and it is load-bearing rather than decorative: two workers
     * that both decide the month needs billing race here, and the index decides
     * which one wrote it. The loser reads the row back and does nothing.
     *
     * @param array<string, mixed> $attributes
     * @return array{0: array, 1: bool} the row, and whether this call created it
     */
    public static function claim(int $restaurantId, string $period, array $attributes): array
    {
        $existing = self::forPeriod($restaurantId, $period);

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            self::create($attributes + ['restaurant_id' => $restaurantId, 'period' => $period]);
        } catch (\PDOException $exception) {
            // 23000 is the duplicate key: somebody else got there first, which
            // is the answer rather than an error.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            return [(array) self::forPeriod($restaurantId, $period), false];
        }

        return [(array) self::forPeriod($restaurantId, $period), true];
    }
}
