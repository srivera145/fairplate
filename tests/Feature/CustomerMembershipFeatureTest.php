<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Membership;
use Keel\App\Services\MembershipService;
use Keel\Core\Database;
use Tests\Support\CustomerFixtures;
use Tests\Support\FakeStripePayments;
use Tests\TestCase;

/**
 * Memberships: bought and cancelled on Stripe's screens, recorded from the
 * webhook, and never written from anywhere else.
 *
 * One Stripe account carries two kinds of subscription — Keel's own plans and
 * FairPlate's membership — so the interesting cases here are the ones where the
 * webhook has to tell them apart.
 */
class CustomerMembershipFeatureTest extends TestCase
{
    use CustomerFixtures;

    private FakeStripePayments $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPricingSettings();
        $this->stripe = $this->fakeStripe();
    }

    protected function tearDown(): void
    {
        $this->restoreCollaborators();

        parent::tearDown();
    }

    public function testThePageShowsThePriceAndTheBenefitsToANonMember(): void
    {
        $this->actingAsCustomer();

        $response = $this->get('/app/membership');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('$9.99', $response->body);
        self::assertStringContainsString('No platform fee on any order, ever.', $response->body);
        self::assertStringContainsString('Join for $9.99 a month', $response->body);
        self::assertStringNotContainsString('Manage or cancel in the billing portal', $response->body);
    }

    public function testSubscribingHandsTheCustomerToStripesOwnCheckout(): void
    {
        $customer = $this->actingAsCustomer();

        $response = $this->post('/app/membership/subscribe', ['_csrf' => $this->csrfToken()]);

        self::assertSame(302, $response->status);
        self::assertSame($this->stripe->checkoutUrl, $response->header('Location'));

        $params = $this->stripe->lastParamsFor('checkout.sessions', 'create');

        self::assertSame('subscription', (string) $params['mode']);
        self::assertSame('price_test_membership', (string) $params['line_items'][0]['price']);
        self::assertSame($this->stripe->customerId, (string) $params['customer']);
        self::assertSame(
            '1',
            (string) $params['subscription_data']['metadata'][MembershipService::METADATA_KEY],
            'The subscription itself has to carry the marker; the webhook reads it, not the session.'
        );
        self::assertSame((string) $customer['user']['id'], (string) $params['subscription_data']['metadata']['user_id']);
    }

    public function testAMemberIsSentToThePortalToCancelRatherThanToAFormWeWrote(): void
    {
        $customer = $this->actingAsCustomer();
        $this->makeMember((int) $customer['user']['id']);

        $page = $this->get('/app/membership');

        self::assertStringContainsString('Manage or cancel in the billing portal', $page->body);
        self::assertStringNotContainsString('Join for', $page->body);

        $response = $this->post('/app/membership/portal', ['_csrf' => $this->csrfToken()]);

        self::assertSame(302, $response->status);
        self::assertSame($this->stripe->portalUrl, $response->header('Location'));
    }

    public function testTheCreatedWebhookRecordsTheMembershipAndZeroesTheFeeFromThenOn(): void
    {
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        self::assertFalse(Membership::isActiveFor($userId));

        $response = $this->sendSubscriptionEvent('customer.subscription.created', 'evt_sub_created', [
            'id' => 'sub_member_001',
            'status' => 'active',
            'current_period_end' => time() + 2592000,
            'metadata' => ['user_id' => (string) $userId, MembershipService::METADATA_KEY => '1'],
        ]);

        self::assertSame(200, $response->status);

        $membership = Membership::forUser($userId);

        self::assertNotNull($membership);
        self::assertSame('sub_member_001', (string) $membership['stripe_subscription_id']);
        self::assertSame('active', (string) $membership['status']);
        self::assertTrue(Membership::isActiveFor($userId));
    }

    public function testACancellationScheduledForTheEndOfThePeriodIsSaidSoRatherThanShownAsActive(): void
    {
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $this->sendSubscriptionEvent('customer.subscription.created', 'evt_sub_a', [
            'id' => 'sub_member_002',
            'status' => 'active',
            'current_period_end' => time() + 2592000,
            'metadata' => ['user_id' => (string) $userId, MembershipService::METADATA_KEY => '1'],
        ]);

        $this->sendSubscriptionEvent('customer.subscription.updated', 'evt_sub_b', [
            'id' => 'sub_member_002',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'current_period_end' => time() + 2592000,
            'metadata' => ['user_id' => (string) $userId, MembershipService::METADATA_KEY => '1'],
        ]);

        self::assertTrue(Membership::isActiveFor($userId), 'A cancellation at period end is still a membership today.');
        self::assertTrue(Membership::isEnding(Membership::forUser($userId)));

        $body = $this->get('/app/membership')->body;

        self::assertStringContainsString('Ending', $body);
        self::assertStringContainsString('will not renew', $body);
    }

    public function testADeletedSubscriptionEndsTheMembership(): void
    {
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $this->sendSubscriptionEvent('customer.subscription.created', 'evt_sub_c', [
            'id' => 'sub_member_003',
            'status' => 'active',
            'current_period_end' => time() + 2592000,
            'metadata' => ['user_id' => (string) $userId, MembershipService::METADATA_KEY => '1'],
        ]);

        $this->sendSubscriptionEvent('customer.subscription.deleted', 'evt_sub_d', [
            'id' => 'sub_member_003',
            'status' => 'canceled',
            'metadata' => ['user_id' => (string) $userId, MembershipService::METADATA_KEY => '1'],
        ]);

        self::assertSame('canceled', (string) Membership::forUser($userId)['status']);
        self::assertFalse(Membership::isActiveFor($userId));
        self::assertStringContainsString('Join for $9.99 a month', $this->get('/app/membership')->body);
    }

    public function testAReplayedSubscriptionEventLeavesOneMembershipRow(): void
    {
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        $payload = [
            'id' => 'sub_member_004',
            'status' => 'active',
            'current_period_end' => time() + 2592000,
            'metadata' => ['user_id' => (string) $userId, MembershipService::METADATA_KEY => '1'],
        ];

        $this->sendSubscriptionEvent('customer.subscription.created', 'evt_sub_replay', $payload);
        $second = $this->sendSubscriptionEvent('customer.subscription.created', 'evt_sub_replay', $payload);

        self::assertTrue((bool) ($second->json()['duplicate'] ?? false));
        self::assertSame(1, Membership::count());
    }

    public function testAKeelPlanSubscriptionIsNotMistakenForAMembership(): void
    {
        $user = $this->createUser([
            'email' => 'planholder@example.test',
            'stripe_customer_id' => 'cus_keel_plan',
        ]);

        $response = $this->sendSubscriptionEvent('customer.subscription.updated', 'evt_plan_updated', [
            'id' => 'sub_keel_plan_001',
            'customer' => 'cus_keel_plan',
            'status' => 'active',
            'current_period_end' => time() + 2592000,
            'metadata' => ['plan' => 'pro_monthly'],
            'items' => ['object' => 'list', 'data' => [['price' => ['id' => 'price_pro_monthly']]]],
        ]);

        self::assertSame(200, $response->status);
        self::assertSame(0, Membership::count(), 'A Keel plan is not a FairPlate membership.');

        $statement = Database::connection()->prepare('SELECT * FROM subscriptions WHERE stripe_subscription_id = ?');
        $statement->execute(['sub_keel_plan_001']);

        $subscription = $statement->fetch();

        self::assertIsArray($subscription, 'It still belongs to Keel\'s own billing.');
        self::assertSame((int) $user['id'], (int) $subscription['user_id']);
    }

    public function testASubscriptionOnTheMembershipPriceCountsEvenWithoutTheMarker(): void
    {
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];

        // What a support agent creating a membership by hand in the Stripe
        // dashboard leaves behind: the right price, no metadata.
        Database::connection()
            ->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ?')
            ->execute(['cus_manual_member', $userId]);

        $this->sendSubscriptionEvent('customer.subscription.created', 'evt_sub_manual', [
            'id' => 'sub_member_manual',
            'customer' => 'cus_manual_member',
            'status' => 'active',
            'current_period_end' => time() + 2592000,
            'items' => ['object' => 'list', 'data' => [['price' => ['id' => 'price_test_membership']]]],
        ]);

        self::assertSame(1, Membership::count());
        self::assertTrue(Membership::isActiveFor($userId));
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function sendSubscriptionEvent(string $type, string $eventId, array $subscription): \Tests\Support\TestResponse
    {
        $raw = (string) json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => array_merge([
                    'object' => 'subscription',
                    'customer' => 'cus_fake_customer',
                    'cancel_at_period_end' => false,
                    'items' => ['object' => 'list', 'data' => [['price' => ['id' => 'price_test_membership']]]],
                ], $subscription),
            ],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $raw, 'whsec_feature_test');

        return $this->postRawJson('/webhooks/stripe', $raw, [
            'Stripe-Signature' => 't=' . $timestamp . ',v1=' . $signature,
        ]);
    }
}
