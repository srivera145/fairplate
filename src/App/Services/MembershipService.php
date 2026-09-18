<?php

namespace Keel\App\Services;

use Keel\App\Models\Membership;
use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\Pricing\Breakdown;
use Keel\App\Services\Pricing\PricingService;
use Keel\Core\Activity;
use Keel\Core\Env;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Subscription;

/**
 * The FairPlate membership: what it costs, what it saves, and how it is bought
 * and cancelled.
 *
 * Buying and cancelling are both Stripe's own screens — a Checkout Session to
 * subscribe, the Customer Portal to change or cancel — so this application
 * never holds a card for a subscription and never has to build a cancel flow
 * that has to stay correct. What comes back is a webhook, and
 * syncFromSubscription() is the only thing that writes the memberships table.
 *
 * The savings figure is the interesting part. The nudge on the checkout screen
 * is only allowed to appear when it is true, so it is arithmetic on orders that
 * were actually placed and actually charged a platform fee — never a guess, and
 * never a projection. PricingService does the per-order sum from each order's
 * own frozen snapshot; this class only adds them up and takes the membership
 * price off.
 */
class MembershipService
{
    /** Stripe subscriptions carrying this are FairPlate memberships. */
    public const METADATA_KEY = 'fairplate_membership';

    public const PRICE_SETTING = 'membership_price_cents';

    public function __construct(private readonly ?PricingService $pricing = null)
    {
    }

    // -----------------------------------------------------------------
    // What it is
    // -----------------------------------------------------------------

    /**
     * The monthly price, in cents, from settings. No default: a missing price is
     * a deployment mistake, not a free membership.
     */
    public function priceCents(): int
    {
        return Settings::int(self::PRICE_SETTING);
    }

    /**
     * What a member gets. The platform fee is the whole product; the rest is
     * consequence, so the list stays short and true.
     *
     * @return list<string>
     */
    public function benefits(): array
    {
        return [
            'No platform fee on any order, ever.',
            'The same menu prices as the restaurant charges in store.',
            'Cancel any time from your billing portal.',
        ];
    }

    public function membershipFor(int $userId): ?array
    {
        return Membership::forUser($userId);
    }

    public function isMember(int $userId): bool
    {
        return Membership::isActiveFor($userId);
    }

    // -----------------------------------------------------------------
    // Buying and cancelling
    // -----------------------------------------------------------------

    /**
     * The hosted subscribe page.
     */
    public function checkoutSession(array $user): CheckoutSession
    {
        $priceId = trim((string) Env::get('STRIPE_PRICE_MEMBERSHIP', ''));

        if ($priceId === '') {
            throw new \RuntimeException('STRIPE_PRICE_MEMBERSHIP is not configured.');
        }

        return StripeClientFactory::make()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => StripeCustomer::idFor($user),
            'client_reference_id' => (string) $user['id'],
            'success_url' => $this->appUrl('/app/membership?subscribed=1'),
            'cancel_url' => $this->appUrl('/app/membership'),
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'metadata' => [
                'user_id' => (string) $user['id'],
                self::METADATA_KEY => '1',
            ],
            // The webhook reads the subscription, not the session, so the marker
            // has to be on the subscription itself.
            'subscription_data' => [
                'metadata' => [
                    'user_id' => (string) $user['id'],
                    self::METADATA_KEY => '1',
                ],
            ],
        ]);
    }

    /**
     * The hosted billing portal, which is also where a membership is cancelled.
     */
    public function portalSession(array $user): PortalSession
    {
        return StripeClientFactory::make()->billingPortal->sessions->create([
            'customer' => StripeCustomer::idFor($user),
            'return_url' => $this->appUrl('/app/membership'),
        ]);
    }

    // -----------------------------------------------------------------
    // What the webhook tells us
    // -----------------------------------------------------------------

    /**
     * True when this Stripe subscription is a FairPlate membership rather than
     * one of Keel's own plans.
     *
     * Metadata first, because that is what this service writes. The price id is
     * the fallback for a subscription created in the Stripe dashboard by hand,
     * which is how a support agent fixes a botched signup.
     */
    public static function isMembershipSubscription(Subscription $subscription): bool
    {
        if ((string) (self::metadata($subscription)[self::METADATA_KEY] ?? '') !== '') {
            return true;
        }

        $priceId = trim((string) Env::get('STRIPE_PRICE_MEMBERSHIP', ''));

        return $priceId !== '' && (string) ($subscription->items->data[0]->price->id ?? '') === $priceId;
    }

    /**
     * Writes what Stripe says about a membership. The only writer of this table.
     *
     * @return int|null the membership row id, or null when the subscription
     *         belongs to nobody this application knows
     */
    public function syncFromSubscription(Subscription $subscription): ?int
    {
        $userId = $this->resolveUserId($subscription);

        if ($userId === null) {
            error_log('[FairPlate] Membership webhook for an unknown customer: ' . (string) $subscription->id);

            return null;
        }

        $attributes = [
            'user_id' => $userId,
            'stripe_subscription_id' => (string) $subscription->id,
            'status' => (string) $subscription->status,
            'current_period_end' => $this->utc($subscription->current_period_end ?? null),
            'cancel_at_period_end' => !empty($subscription->cancel_at_period_end),
        ];

        $existing = Membership::findByStripeSubscriptionId((string) $subscription->id);

        if ($existing !== null) {
            Membership::update((int) $existing['id'], $attributes);
            $membershipId = (int) $existing['id'];
        } else {
            $membershipId = Membership::create($attributes);
        }

        Activity::log('membership.' . (string) $subscription->status, 'Membership', $membershipId, [
            'stripe_subscription_id' => (string) $subscription->id,
            'cancel_at_period_end' => !empty($subscription->cancel_at_period_end),
        ]);

        return $membershipId;
    }

    // -----------------------------------------------------------------
    // The nudge
    // -----------------------------------------------------------------

    /**
     * What a membership would have been worth to this customer this month.
     *
     * "Worth" is the platform fees they actually paid, plus the processing those
     * fees dragged along, minus one month's membership. A pending checkout is
     * counted too, because the question on the checkout screen is about this
     * order as much as the ones before it.
     *
     * Zero or less means the nudge must not appear. That is the point of
     * returning the number rather than a sentence: there is exactly one place
     * that decides whether the claim is true, and it is arithmetic.
     */
    public function savingsThisMonthCents(int $userId, ?Breakdown $pending = null, ?\DateTimeImmutable $at = null): int
    {
        $pricing = $this->pricing ?? new PricingService();
        $period = ($at ?? new \DateTimeImmutable('now'))
            ->setTimezone(new \DateTimeZone(PricingService::TIMEZONE))
            ->format('Y-m');

        $saved = 0;

        foreach (Order::forCustomerInPeriod($userId, $period) as $order) {
            $row = $this->breakdownRowFor((int) $order['id']);

            if ($row !== null) {
                $saved += $pricing->memberSavings($row);
            }
        }

        if ($pending !== null) {
            $saved += $pricing->memberSavings($pending->toRow(0, OrderPriceBreakdown::STAGE_ESTIMATE));
        }

        return $saved - $this->priceCents();
    }

    /**
     * The breakdown a receipt would show: the latest stage that exists.
     */
    private function breakdownRowFor(int $orderId): ?array
    {
        foreach ([
            OrderPriceBreakdown::STAGE_FINAL,
            OrderPriceBreakdown::STAGE_AUTHORIZED,
            OrderPriceBreakdown::STAGE_ESTIMATE,
        ] as $stage) {
            $row = OrderPriceBreakdown::forStage($orderId, $stage);

            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A subscription's metadata as a plain array.
     *
     * Reading an absent property off a StripeObject works but logs a notice, and
     * a webhook that quietly fills the error log every time a Keel subscription
     * goes past is a bad trade for two lines.
     *
     * @return array<string, mixed>
     */
    private static function metadata(Subscription $subscription): array
    {
        $metadata = $subscription->metadata ?? null;

        if ($metadata instanceof \Stripe\StripeObject) {
            return $metadata->toArray();
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function resolveUserId(Subscription $subscription): ?int
    {
        $fromMetadata = (int) (self::metadata($subscription)['user_id'] ?? 0);

        if ($fromMetadata > 0) {
            return $fromMetadata;
        }

        $user = StripeCustomer::userFor((string) ($subscription->customer ?? ''));

        return $user === null ? null : (int) $user['id'];
    }

    private function utc(?int $timestamp): ?string
    {
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }

    private function appUrl(string $path): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/') . $path;
    }
}
