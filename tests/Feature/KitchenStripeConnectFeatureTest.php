<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Restaurant;
use Keel\App\Services\StripeConnectService;
use Keel\Core\Csrf;
use Tests\Support\FakeStripeClient;
use Tests\Support\KitchenFixtures;
use Tests\TestCase;

/**
 * Stripe Connect Express onboarding, against a scripted client.
 *
 * What is checked here is the part FairPlate is responsible for: that an Express
 * account is asked for, that its id is saved before the owner is sent anywhere,
 * that the account link names both our routes, and that coming back through the
 * return URL does not make anything true on its own.
 *
 * What is not checked here is Stripe's hosted form, which needs a browser and
 * real test-mode keys. See the notes in this phase's summary.
 */
class KitchenStripeConnectFeatureTest extends TestCase
{
    use KitchenFixtures;

    private FakeStripeClient $stripe;
    private int $restaurantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeClient();
        StripeConnectService::swap($this->stripe);

        $zoneId = $this->createZone();
        $created = $this->createRestaurantWithOwner('Connect Kitchen', $zoneId, [
            'status' => Restaurant::STATUS_PENDING,
        ]);

        $this->restaurantId = $created['restaurant_id'];
        $this->actingAsOwner($created['owner_id']);

        $_ENV['APP_URL'] = 'https://fairplate.test';
        $_SERVER['APP_URL'] = 'https://fairplate.test';
    }

    protected function tearDown(): void
    {
        StripeConnectService::swap(null);

        parent::tearDown();
    }

    public function testStartingOnboardingCreatesAnExpressAccountAndSavesItsId(): void
    {
        $response = $this->post('/kitchen/connect/start', ['_csrf' => Csrf::token()]);

        self::assertSame(302, $response->status);
        self::assertSame($this->stripe->linkUrl, $response->header('Location'));

        self::assertSame(
            $this->stripe->accountId,
            (string) Restaurant::find($this->restaurantId)['stripe_account_id'],
            'the account id is saved'
        );

        $params = $this->stripe->lastParamsFor('accounts', 'create');
        self::assertSame('express', $params['type']);
        self::assertSame('US', $params['country']);
        self::assertSame('Connect Kitchen', $params['business_profile']['name']);
        self::assertSame((string) $this->restaurantId, $params['metadata']['restaurant_id']);
        self::assertTrue($params['capabilities']['transfers']['requested']);
    }

    public function testTheAccountLinkCarriesBothOfOurRoutes(): void
    {
        $this->post('/kitchen/connect/start', ['_csrf' => Csrf::token()]);

        $params = $this->stripe->lastParamsFor('accountLinks', 'create');

        self::assertSame($this->stripe->accountId, $params['account']);
        self::assertSame('account_onboarding', $params['type']);
        self::assertSame('https://fairplate.test/kitchen/connect/refresh', $params['refresh_url']);
        self::assertSame('https://fairplate.test/kitchen/connect/return', $params['return_url']);
    }

    /**
     * An interrupted onboarding must resume into the same account rather than
     * leaving a trail of abandoned ones behind.
     */
    public function testStartingAgainReusesTheAccountItAlreadyHas(): void
    {
        $this->post('/kitchen/connect/start', ['_csrf' => Csrf::token()]);
        $this->post('/kitchen/connect/start', ['_csrf' => Csrf::token()]);

        $created = array_filter(
            $this->stripe->calledMethods(),
            static fn (string $call): bool => $call === 'accounts.create'
        );

        self::assertCount(1, $created, 'only one account is ever created');
    }

    /**
     * Stripe's links are single use and expire in minutes, so the refresh route
     * mints another rather than showing anything.
     */
    public function testTheRefreshRouteMintsAnotherLink(): void
    {
        Restaurant::update($this->restaurantId, ['stripe_account_id' => $this->stripe->accountId]);

        $response = $this->get('/kitchen/connect/refresh');

        self::assertSame(302, $response->status);
        self::assertSame($this->stripe->linkUrl, $response->header('Location'));
        self::assertContains('accountLinks.create', $this->stripe->calledMethods());
        self::assertNotContains('accounts.create', $this->stripe->calledMethods());
    }

    /**
     * Arriving at the return URL means the owner closed Stripe's form. It does
     * not mean Stripe accepted anything, so the route asks.
     */
    public function testTheReturnRouteAsksStripeRatherThanAssuming(): void
    {
        Restaurant::update($this->restaurantId, ['stripe_account_id' => $this->stripe->accountId]);

        $response = $this->get('/kitchen/connect/return');

        self::assertSame(302, $response->status);
        self::assertContains('accounts.retrieve', $this->stripe->calledMethods());
    }

    public function testAnIncompleteAccountSaysWhatIsStillMissing(): void
    {
        Restaurant::update($this->restaurantId, ['stripe_account_id' => $this->stripe->accountId]);

        $this->get('/kitchen/connect/return');
        $body = $this->get('/kitchen/onboarding')->body;

        self::assertStringContainsString('Stripe still needs', $body);
        self::assertStringContainsString('external account', $body);
    }

    /**
     * The line the spec is explicit about: finishing with Stripe does not open
     * the restaurant.
     */
    public function testAFinishedStripeAccountStillLeavesTheRestaurantPending(): void
    {
        Restaurant::update($this->restaurantId, ['stripe_account_id' => $this->stripe->accountId]);

        $this->stripe->accountState = [
            'details_submitted' => true,
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'requirements' => ['currently_due' => []],
        ];

        $response = $this->get('/kitchen/connect/return');

        self::assertSame(302, $response->status);
        self::assertSame(
            Restaurant::STATUS_PENDING,
            (string) Restaurant::find($this->restaurantId)['status']
        );

        $body = $this->get('/kitchen/onboarding')->body;
        self::assertStringContainsString('review and approve your restaurant', $body);
        self::assertStringContainsString('Payouts on', $body);
    }

    /**
     * A Stripe outage should grey out one panel, not take the profile screen
     * down with it.
     */
    public function testAStripeOutageDoesNotBreakTheProfileScreen(): void
    {
        Restaurant::update($this->restaurantId, ['stripe_account_id' => $this->stripe->accountId]);
        $this->stripe->failWith = new \RuntimeException('Stripe is down');

        $response = $this->get('/kitchen/onboarding');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Set up payouts with Stripe', $response->body);
    }

    public function testAFailureToStartOnboardingIsAMessageRatherThanACrash(): void
    {
        $this->stripe->failWith = new \RuntimeException('Stripe is down');

        $response = $this->post('/kitchen/connect/start', ['_csrf' => Csrf::token()]);

        self::assertSame(302, $response->status);
        self::assertSame('/kitchen/onboarding', $response->header('Location'));
        self::assertNull(Restaurant::find($this->restaurantId)['stripe_account_id']);
    }

    /**
     * The Connect routes are scoped like every other kitchen route.
     */
    public function testAStaffMemberWithNoRestaurantCannotStartOnboarding(): void
    {
        $stranger = $this->createUser([
            'role' => \Keel\App\Models\User::ROLE_RESTAURANT_STAFF,
            'phone' => '+18505559999',
        ]);
        $this->actingAsOwner((int) $stranger['id']);

        $response = $this->post('/kitchen/connect/start', ['_csrf' => Csrf::token()]);

        self::assertSame(403, $response->status);
        self::assertSame([], $this->stripe->calls);
    }
}
