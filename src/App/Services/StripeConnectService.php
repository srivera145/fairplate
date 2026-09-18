<?php

namespace Keel\App\Services;

use Keel\App\Models\Driver;
use Keel\App\Models\Restaurant;
use Keel\Core\Activity;
use Keel\Core\Env;
use Stripe\StripeClient;

/**
 * Stripe Connect Express onboarding, for a restaurant and for a driver.
 *
 * Both sides of the marketplace get paid, so both need a connected account, and
 * the mechanics are identical enough that a second class would be this one with
 * the nouns changed. What differs is only what Stripe is told about who it is
 * onboarding — a business with an address and a menu, or an individual with a
 * car — and which pair of URLs the owner is sent back to.
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

        return $this->accountLinkUrl($accountId, 'account_onboarding', '/kitchen');
    }

    /**
     * The same, for a driver.
     *
     * A driver onboards as an individual: Stripe collects the name, date of
     * birth, tax details and bank account and runs the identity checks, and
     * FairPlate never sees any of it. That is the point of Express here — the
     * spec has drivers as independent contractors, and the less of a
     * contractor's paperwork the platform holds the better for both sides.
     */
    public function driverOnboardingUrl(array $driver): string
    {
        $accountId = $this->ensureDriverAccount($driver);

        return $this->accountLinkUrl($accountId, 'account_onboarding', '/drive');
    }

    /**
     * A fresh link for a driver whose previous one expired.
     */
    public function driverRefreshUrl(array $driver): string
    {
        $accountId = trim((string) ($driver['stripe_account_id'] ?? ''));

        if ($accountId === '') {
            return $this->driverOnboardingUrl($driver);
        }

        return $this->accountLinkUrl($accountId, 'account_onboarding', '/drive');
    }

    /**
     * The connected account id for a driver, created on first use and saved
     * straight away, so an interrupted onboarding resumes into the same account
     * instead of leaving abandoned ones behind.
     */
    public function ensureDriverAccount(array $driver): string
    {
        $existing = trim((string) ($driver['stripe_account_id'] ?? ''));

        if ($existing !== '') {
            return $existing;
        }

        $driverId = (int) $driver['id'];

        $account = $this->stripe()->accounts->create([
            'type' => 'express',
            'country' => 'US',
            // A driver is a person, not a company. Saying so up front spares
            // them Stripe's first question.
            'business_type' => 'individual',
            'business_profile' => [
                'mcc' => '4121', // Taxicabs and limousines: Stripe's category for delivery earnings.
                'product_description' => 'Food delivery for FairPlate.',
            ],
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            'metadata' => [
                'driver_id' => (string) $driverId,
                'user_id' => (string) $driver['user_id'],
            ],
        ]);

        $accountId = (string) $account->id;

        Driver::update($driverId, ['stripe_account_id' => $accountId]);
        Activity::log('driver.stripe_account_created', 'Driver', $driverId, [
            'stripe_account_id' => $accountId,
        ]);

        return $accountId;
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

        return $this->accountLinkUrl($accountId, 'account_onboarding', '/kitchen');
    }

    /**
     * What Stripe currently says about the account.
     *
     * Takes any row carrying a stripe_account_id — a restaurant or a driver —
     * because the question and the answer are the same for both.
     *
     * @return array{connected: bool, details_submitted: bool, charges_enabled: bool, payouts_enabled: bool, requirements: list<string>}
     */
    public function status(array $owner): array
    {
        $accountId = trim((string) ($owner['stripe_account_id'] ?? ''));

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
        $payoutsEnabled = (bool) ($account->payouts_enabled ?? false);

        $this->rememberPayoutsEnabled($owner, $payoutsEnabled);

        return [
            'connected' => true,
            'details_submitted' => (bool) ($account->details_submitted ?? false),
            'charges_enabled' => (bool) ($account->charges_enabled ?? false),
            'payouts_enabled' => $payoutsEnabled,
            'requirements' => array_values(array_map('strval', (array) $requirements)),
        ];
    }

    /**
     * Writes back whether Stripe will pay this account out.
     *
     * account.updated is what keeps the flag current in the normal case, but a
     * webhook that never arrived — a misconfigured endpoint, an outage — would
     * leave the column stale forever. This is a read path, so it writes only
     * when the answer has changed, and it costs an UPDATE the first time
     * somebody opens their own onboarding screen after finishing.
     *
     * A row with no id is not persisted: status() takes anything carrying a
     * stripe_account_id, including rows a caller assembled by hand.
     *
     * @param array<string, mixed> $owner
     */
    private function rememberPayoutsEnabled(array $owner, bool $enabled): void
    {
        $id = (int) ($owner['id'] ?? 0);

        if ($id <= 0 || !array_key_exists('payouts_enabled', $owner)) {
            return;
        }

        if (((int) $owner['payouts_enabled'] === 1) === $enabled) {
            return;
        }

        // Restaurants and drivers both carry the column; which table it is comes
        // from what else the row has rather than from the caller telling us.
        $model = array_key_exists('slug', $owner) ? Restaurant::class : Driver::class;

        $model::update($id, ['payouts_enabled' => $enabled ? 1 : 0]);
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

    /**
     * A single-use hosted link, and the two URLs Stripe sends the person back
     * through.
     *
     * $area is the route group doing the onboarding — /kitchen or /drive — so
     * the owner lands back in their own application rather than in somebody
     * else's.
     */
    private function accountLinkUrl(string $accountId, string $type, string $area): string
    {
        $link = $this->stripe()->accountLinks->create([
            'account' => $accountId,
            'type' => $type,
            'refresh_url' => $this->appUrl($area . '/connect/refresh'),
            'return_url' => $this->appUrl($area . '/connect/return'),
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
