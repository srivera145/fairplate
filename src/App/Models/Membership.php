<?php

namespace Keel\App\Models;

class Membership extends Model
{
    protected const TABLE = 'memberships';

    protected const COLUMNS = ['user_id', 'stripe_subscription_id', 'status', 'current_period_end'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELED = 'canceled';

    /** Statuses that still zero the platform fee at checkout. */
    public const ENTITLED_STATUSES = [self::STATUS_ACTIVE, self::STATUS_TRIALING];

    public static function forUser(int $userId): ?array
    {
        return self::queryOne(
            'SELECT * FROM memberships WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    public static function findByStripeSubscriptionId(string $subscriptionId): ?array
    {
        return self::firstBy('stripe_subscription_id', $subscriptionId);
    }

    /**
     * Whether this user pays no platform fee right now.
     */
    public static function isActiveFor(int $userId): bool
    {
        $placeholders = implode(', ', array_fill(0, count(self::ENTITLED_STATUSES), '?'));

        $row = self::queryOne(
            'SELECT id FROM memberships
             WHERE user_id = ?
               AND status IN (' . $placeholders . ')
               AND (current_period_end IS NULL OR current_period_end > UTC_TIMESTAMP())
             LIMIT 1',
            array_merge([$userId], self::ENTITLED_STATUSES)
        );

        return $row !== null;
    }
}
