<?php

namespace Keel\App\Services;

use Keel\App\Models\Restaurant;
use Keel\Core\Activity;
use Keel\Core\Env;
use Stripe\StripeClient;

/**
 * Stripe Connect Express onboarding for a restaurant.
 *
 * FairPlate charges the customer and then pays the restaurant, so the money
 * lands in the restaurant's own Stripe account and the platform never holds it.
 * Express is the right account type: Stripe collects the tax details and the
 * bank account and handles the identity checks, and the owner sees a hosted
 * form rather than a form we had to build and keep compliant.
 *
 * Two facts about account links shape the flow, and both are Stripe's rules
 * rather than ours:
 *
 *   - a link is single use and expires in minutes, which is why there is a
 *     refresh route whose only job is to mint another one and bounce;
 *   - coming back through the return URL means the owner finished the form, not
 *     that Stripe finished checking it. So the return route re-reads the
 *     account and records what Stripe actually says.
 *
 * The account id is saved the moment the account is created, before the owner
 * has seen the form. An interrupted onboarding then resumes into the same
 * account instead of leaving a trail of abandoned ones behind.
 *
 * Approval is separate and stays manual: a fully onboarded Stripe account moves
 * the restaurant no further than pending, because the spec has an admin approve
 * it.
 */
class StripeConnectService
{
    private static ?StripeClient $override = null;

    private ?StripeClient $stripe = null;

    /**
     * Replaces the Stripe client for the rest of the process. Tests only.
     */
    public static function swap(?StripeClient $client): void
    {
        self::$override = $client;
    }

    /**
     * The hosted onboarding URL for this restaurant, creating the connected
     * account first if it does not have one.
     */
    public function onboardingUrl(array $restaurant): string
    {
        $accountId = $this->ensureAccount($restaurant);

        return $this->accountLinkUrl($accountId, 'account_onboarding');
    }

    /**
     * A fresh link for a restaurant that already has an account — what the
     * refresh route returns when the previous link expired.
     */
    public function refreshUrl(array $restaurant): string
    {
        $accountId = trim((string) ($restaurant['stripe_account_id'] ?? ''));

        if ($accountId === '') {
            return $this->onboardingUrl($restaurant);
        }

        return $this->accountLinkUrl($accountId, 'account_onboarding');
    }

    /**
     * What Stripe currently says about the account.
     *
     * @return array{connected: bool, details_submitted: bool, charges_enabled: bool, payouts_enabled: bool, requirements: list<string>}
     */
    public function status(array $restaurant): array
    {
        $accountId = trim((string) ($restaurant['stripe_account_id'] ?? ''));

        $empty = [
            'connected' => false,
            'details_submitted' => false,
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'requirements' => [],
        ];

        if ($accountId === '') {
            return $empty;
        }

        try {
            $account = $this->stripe()->accounts->retrieve($accountId, []);
        } catch (\Throwable $exception) {
            // A Stripe outage should grey out one panel, not take the kitchen
            // board down with it.
            error_log('[FairPlate] Stripe account lookup failed: ' . $exception->getMessage());

            return $empty;
        }

        $requirements = $account->requirements?->currently_due ?? [];

        return [
            'connected' => true,
            'details_submitted' => (bool) ($account->details_submitted ?? false),
            'charges_enabled' => (bool) ($account->charges_enabled ?? false),
            'payouts_enabled' => (bool) ($account->payouts_enabled ?? false),
            'requirements' => array_values(array_map('strval', (array) $requirements)),
        ];
    }

    /**
     * The connected account id, created on first use and saved straight away.
     */
    public function ensureAccount(array $restaurant): string
    {
        $existing = trim((string) ($restaurant['stripe_account_id'] ?? ''));

        if ($existing !== '') {
            return $existing;
        }

        $restaurantId = (int) $restaurant['id'];

        // business_type is left out on purpose: a taqueria run by one person and
        // a three-location group answer that question differently, and Stripe's
        // own form asks it better than a field on our onboarding screen would.
        $account = $this->stripe()->accounts->create([
            'type' => 'express',
            'country' => 'US',
            'business_profile' => [
                'name' => (string) $restaurant['name'],
                'mcc' => '5812', // Eating places and restaurants.
                'url' => $this->appUrl('/r/' . (string) $restaurant['slug']),
            ],
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            // Separate charges and transfers: the platform takes the payment and
            // the restaurant is paid by transfer, so the platform carries the
            // dispute and refund liability rather than the kitchen.
            'metadata' => [
                'restaurant_id' => (string) $restaurantId,
                'restaurant_slug' => (string) $restaurant['slug'],
            ],
        ]);

        $accountId = (string) $account->id;

        Restaurant::update($restaurantId, ['stripe_account_id' => $accountId]);
        Activity::log('restaurant.stripe_account_created', 'Restaurant', $restaurantId, [
            'stripe_account_id' => $accountId,
        ]);

        return $accountId;
    }

    private function accountLinkUrl(string $accountId, string $type): string
    {
        $link = $this->stripe()->accountLinks->create([
            'account' => $accountId,
            'type' => $type,
            'refresh_url' => $this->appUrl('/kitchen/connect/refresh'),
            'return_url' => $this->appUrl('/kitchen/connect/return'),
        ]);

        return (string) $link->url;
    }

    private function stripe(): StripeClient
    {
        if (self::$override !== null) {
            return self::$override;
        }

        if ($this->stripe !== null) {
            return $this->stripe;
        }

        $secretKey = trim((string) Env::get('STRIPE_SECRET_KEY', ''));

        if ($secretKey === '') {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured.');
        }

        return $this->stripe = new StripeClient($secretKey);
    }

    private function appUrl(string $path): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/') . $path;
    }
}
