<?php

namespace Keel\App\Services\Billing;

use Keel\App\Models\Restaurant;
use Keel\App\Models\RestaurantStaff;
use Keel\App\Services\StripeClientFactory;
use Stripe\SetupIntent;
use Stripe\StripeClient;

/**
 * The restaurant's side of the till: who Stripe bills, and what it bills.
 *
 * A restaurant has two Stripe objects and they face opposite ways. The
 * connected account in StripeConnectService is where FairPlate *sends* money —
 * the food and the tax, in full, on every delivery. The customer here is where
 * FairPlate *collects* the one monthly fee. Keeping them in separate classes is
 * not tidiness: charging the connected account, or paying out to the customer,
 * are both things that would silently half-work, and the type of the id is the
 * only thing that would have said so.
 *
 * The customer id is written back to the restaurant the moment it exists, the
 * same way StripeCustomer does it for a user, so an interrupted setup resumes
 * into the same customer instead of leaving a trail of empty ones.
 *
 * The card or bank account is collected with a SetupIntent rather than a
 * charge. Nothing is owed at the moment a restaurant sets billing up — the
 * first invoice is weeks away — so there is nothing to charge, and a
 * SetupIntent is the only way to get a mandate Stripe will accept off-session
 * later. us_bank_account is offered alongside card because a $999 monthly ACH
 * debit costs the platform about thirty cents and the same amount on a card
 * costs about thirty dollars, and the spec's whole argument is that FairPlate
 * does not take that out of the restaurant.
 */
class BillingCustomer
{
    /** What Stripe may collect for the monthly fee. */
    public const PAYMENT_METHOD_TYPES = ['card', 'us_bank_account'];

    /**
     * This restaurant's Stripe customer id, created on first use.
     *
     * Saved before anything else is done with it. A setup that dies between the
     * create and the save would otherwise orphan the customer and make a second
     * one next time, and the restaurant's billing history would be split across
     * both.
     */
    public function customerIdFor(array $restaurant): string
    {
        $existing = trim((string) ($restaurant['stripe_customer_id'] ?? ''));

        if ($existing !== '') {
            return $existing;
        }

        $restaurantId = (int) $restaurant['id'];

        $customer = $this->stripe()->customers->create(array_filter([
            'name' => trim((string) ($restaurant['name'] ?? '')) ?: null,
            'phone' => trim((string) ($restaurant['phone'] ?? '')) ?: null,
            'email' => $this->ownerEmail($restaurantId),
            'address' => $this->address($restaurant),
            'description' => 'FairPlate restaurant monthly fee',
            'metadata' => ['restaurant_id' => (string) $restaurantId],
        ]));

        Restaurant::update($restaurantId, ['stripe_customer_id' => (string) $customer->id]);

        return (string) $customer->id;
    }

    /**
     * A SetupIntent for the billing page's payment form.
     *
     * off_session usage is what matters here: the mandate has to still be good
     * on the first of a month at six in the morning, when nobody from the
     * restaurant is at the keyboard to approve anything.
     */
    public function setupIntentFor(array $restaurant): SetupIntent
    {
        return $this->stripe()->setupIntents->create([
            'customer' => $this->customerIdFor($restaurant),
            'payment_method_types' => self::PAYMENT_METHOD_TYPES,
            'usage' => 'off_session',
            'metadata' => ['restaurant_id' => (string) $restaurant['id']],
        ]);
    }

    /**
     * Makes the method a finished SetupIntent collected the one invoices use.
     *
     * The intent id arrives on a URL Stripe redirected the browser to, so it is
     * not trusted: the intent is re-read from Stripe, and its customer must be
     * the customer this restaurant already has. Without that check, pasting
     * somebody else's setup_intent into the return URL would point this
     * restaurant's invoices at their bank account.
     *
     * Returns null when the intent is not this restaurant's, or has not
     * succeeded — a bank account being verified by micro-deposits sits in
     * requires_action for days, and that is not a failure.
     */
    public function attachFromSetupIntent(array $restaurant, string $setupIntentId): ?string
    {
        $setupIntentId = trim($setupIntentId);

        if ($setupIntentId === '') {
            return null;
        }

        $customerId = trim((string) ($restaurant['stripe_customer_id'] ?? ''));

        if ($customerId === '') {
            return null;
        }

        $intent = $this->stripe()->setupIntents->retrieve($setupIntentId, []);

        if ((string) ($intent->customer ?? '') !== $customerId) {
            return null;
        }

        if ((string) ($intent->status ?? '') !== 'succeeded') {
            return null;
        }

        $paymentMethod = (string) ($intent->payment_method ?? '');

        if ($paymentMethod === '') {
            return null;
        }

        $this->stripe()->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethod],
        ]);

        return $paymentMethod;
    }

    /**
     * What the billing page shows about the method on file, or null when there
     * is none.
     *
     * Failure is null, not an exception. Stripe being unreachable is a reason
     * to stop describing the card; it is not a reason for a restaurant to lose
     * the page that tells it how many orders it has done this month.
     *
     * @return array{brand: string, last4: string, kind: string}|null
     */
    public function paymentMethodSummary(array $restaurant): ?array
    {
        $customerId = trim((string) ($restaurant['stripe_customer_id'] ?? ''));

        if ($customerId === '') {
            return null;
        }

        try {
            $customer = $this->stripe()->customers->retrieve(
                $customerId,
                ['expand' => ['invoice_settings.default_payment_method']]
            );

            $method = $customer->invoice_settings->default_payment_method ?? null;

            if ($method === null || is_string($method)) {
                return null;
            }

            $type = (string) ($method->type ?? '');

            if ($type === 'us_bank_account') {
                return [
                    'kind' => 'Bank account',
                    'brand' => (string) ($method->us_bank_account->bank_name ?? 'Bank account'),
                    'last4' => (string) ($method->us_bank_account->last4 ?? ''),
                ];
            }

            if ($type === 'card') {
                return [
                    'kind' => 'Card',
                    'brand' => ucfirst((string) ($method->card->brand ?? 'card')),
                    'last4' => (string) ($method->card->last4 ?? ''),
                ];
            }

            return ['kind' => 'Payment method', 'brand' => $type, 'last4' => ''];
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Could not read the billing payment method: ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * Has this restaurant given Stripe something to charge?
     */
    public function hasPaymentMethod(array $restaurant): bool
    {
        return $this->paymentMethodSummary($restaurant) !== null;
    }

    private function stripe(): StripeClient
    {
        return StripeClientFactory::make();
    }

    /**
     * Stripe emails the invoice receipt to the customer, so the address on it
     * should be a person who can act on a failed charge: the owner.
     */
    private function ownerEmail(int $restaurantId): ?string
    {
        foreach (RestaurantStaff::forRestaurant($restaurantId) as $member) {
            $email = trim((string) ($member['email'] ?? ''));

            if ($email !== '') {
                return $email;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function address(array $restaurant): ?array
    {
        $line1 = trim((string) ($restaurant['line1'] ?? ''));

        if ($line1 === '') {
            return null;
        }

        return array_filter([
            'line1' => $line1,
            'line2' => trim((string) ($restaurant['line2'] ?? '')) ?: null,
            'city' => trim((string) ($restaurant['city'] ?? '')) ?: null,
            'state' => trim((string) ($restaurant['state'] ?? '')) ?: null,
            'postal_code' => trim((string) ($restaurant['zip'] ?? '')) ?: null,
            'country' => 'US',
        ]);
    }
}
