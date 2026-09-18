<?php

namespace Keel\App\Services;

use Keel\App\Models\User;
use Keel\Core\Database;

/**
 * The Stripe customer behind a FairPlate account.
 *
 * Two things need one: a checkout that saves the card for a later tip
 * adjustment, and a membership subscription. Both must land on the *same*
 * customer, or a member's saved card is invisible to the subscription and the
 * portal shows an empty billing history. So the id is resolved in one place and
 * written back to users.stripe_customer_id the moment it exists.
 *
 * FairPlate signs in by phone, so email is optional here in a way it is not in
 * Keel's own billing: the phone is what identifies the customer in the Stripe
 * dashboard.
 */
class StripeCustomer
{
    /**
     * This user's Stripe customer id, created on first use.
     */
    public static function idFor(array $user): string
    {
        $existing = trim((string) ($user['stripe_customer_id'] ?? ''));

        if ($existing !== '') {
            return $existing;
        }

        $customer = StripeClientFactory::make()->customers->create(array_filter([
            'email' => trim((string) ($user['email'] ?? '')) ?: null,
            'name' => trim((string) ($user['name'] ?? '')) ?: null,
            'phone' => trim((string) ($user['phone'] ?? '')) ?: null,
            'metadata' => ['user_id' => (string) $user['id']],
        ]));

        $statement = Database::connection()->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ?');
        $statement->execute([$customer->id, (int) $user['id']]);

        return (string) $customer->id;
    }

    /**
     * The FairPlate user a Stripe customer belongs to, for webhooks that arrive
     * carrying only the customer id.
     */
    public static function userFor(string $customerId): ?array
    {
        $customerId = trim($customerId);

        return $customerId === '' ? null : User::findByStripeCustomerId($customerId);
    }
}
