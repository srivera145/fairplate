<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Driver;
use Keel\App\Services\StripeConnectService;
use Keel\Core\Csrf;
use Tests\Support\DriverFixtures;
use Tests\Support\FakeStripeClient;
use Tests\TestCase;

/**
 * A driver's Stripe Connect Express onboarding, against a scripted client.
 *
 * The same shape as the kitchen's, because it is the same flow with different
 * nouns — and that is exactly why it is worth testing separately. A driver is
 * onboarded as an individual rather than a business, and is sent back to
 * /drive rather than /kitchen; both are one word in a parameter array and
 * neither would fail loudly if it were wrong.
 *
 * What is not checked here is Stripe's hosted form, which needs a browser and
 * real test-mode keys.
 */
class DriverStripeConnectFeatureTest extends TestCase
{
    use DriverFixtures;

    private FakeStripeClient $stripe;
    private int $driverId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDispatchSettings();

        $this->stripe = new FakeStripeClient();
        StripeConnectService::swap($this->stripe);

        $this->driverId = $this->createDispatchableDriver('Dana Ruiz', null, null, [
            'approved' => 0,
            'online' => 0,
        ]);
        $this->actingAsDriver($this->driverId);

        $_ENV['APP_URL'] = 'https://fairplate.test';
        $_SERVER['APP_URL'] = 'https://fairplate.test';
    }

    protected function tearDown(): void
    {
        StripeConnectService::swap(null);
        $this->restoreCollaborators();

        parent::tearDown();
    }

    /**
     * A driver is a person, and the account says so. Saying it up front spares
     * them Stripe's first question.
     */
    public function testStartingOnboardingCreatesAnIndividualExpressAccount(): void
    {
        $response = $this->post('/drive/connect/start', ['_csrf' => Csrf::token()]);

        self::assertSame(302, $response->status);
        self::assertSame($this->stripe->linkUrl, $response->header('Location'));

        self::assertSame(
            $this->stripe->accountId,
            (string) Driver::find($this->driverId)['stripe_account_id'],
            'the account id is saved before the driver is sent anywhere'
        );

        $params = $this->stripe->lastParamsFor('accounts', 'create');

        self::assertSame('express', $params['type']);
        self::assertSame('US', $params['country']);
        self::assertSame('individual', $params['business_type']);
        self::assertSame((string) $this->driverId, $params['metadata']['driver_id']);
        self::assertTrue($params['capabilities']['transfers']['requested']);
    }

    /**
     * The link has to bring the driver back to the driver app, not the kitchen.
     */
    public function testTheAccountLinkCarriesTheDriverRoutes(): void
    {
        $this->post('/drive/connect/start', ['_csrf' => Csrf::token()]);

        $params = $this->stripe->lastParamsFor('accountLinks', 'create');

        self::assertSame($this->stripe->accountId, $params['account']);
        self::assertSame('account_onboarding', $params['type']);
        self::assertSame('https://fairplate.test/drive/connect/refresh', $params['refresh_url']);
        self::assertSame('https://fairplate.test/drive/connect/return', $params['return_url']);
    }

    public function testStartingAgainReusesTheAccountItAlreadyHas(): void
    {
        $this->post('/drive/connect/start', ['_csrf' => Csrf::token()]);
        $this->post('/drive/connect/start', ['_csrf' => Csrf::token()]);

        $created = array_filter(
            $this->stripe->calledMethods(),
            static fn (string $call): bool => $call === 'accounts.create'
        );

        self::assertCount(1, $created, 'only one account is ever created');
    }

    public function testTheRefreshRouteMintsAnotherLink(): void
    {
        Driver::update($this->driverId, ['stripe_account_id' => $this->stripe->accountId]);

        $response = $this->get('/drive/connect/refresh');

        self::assertSame(302, $response->status);
        self::assertSame($this->stripe->linkUrl, $response->header('Location'));
        self::assertContains('accountLinks.create', $this->stripe->calledMethods());
        self::assertNotContains('accounts.create', $this->stripe->calledMethods());
    }

    /**
     * Arriving at the return URL means the driver closed Stripe's form. It does
     * not mean Stripe accepted anything, so the route asks.
     */
    public function testTheReturnRouteAsksStripeRatherThanAssuming(): void
    {
        Driver::update($this->driverId, ['stripe_account_id' => $this->stripe->accountId]);

        $response = $this->get('/drive/connect/return');

        self::assertSame(302, $response->status);
        self::assertContains('accounts.retrieve', $this->stripe->calledMethods());
    }

    /**
     * The spec's line, from the driver's side: finishing with Stripe does not
     * put anybody on the road.
     */
    public function testAFinishedStripeAccountStillLeavesTheDriverPending(): void
    {
        Driver::update($this->driverId, ['stripe_account_id' => $this->stripe->accountId]);

        $this->stripe->accountState = [
            'details_submitted' => true,
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'requirements' => ['currently_due' => []],
        ];

        $this->get('/drive/connect/return');

        self::assertSame(0, (int) Driver::find($this->driverId)['approved']);

        $body = $this->get('/drive/onboarding')->body;

        self::assertStringContainsString('Pending approval', $body);
        self::assertStringContainsString('FairPlate will review your application', $body);
    }

    /**
     * A Stripe outage greys out one panel rather than taking the account screen
     * down with it.
     */
    public function testAStripeOutageStillRendersTheAccountScreen(): void
    {
        Driver::update($this->driverId, ['stripe_account_id' => $this->stripe->accountId]);
        $this->stripe->failWith = new \RuntimeException('Stripe is down');

        $response = $this->get('/drive/onboarding');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Payouts', $response->body);
    }

    /**
     * A driver who has not saved a profile yet has nothing to attach an account
     * to, and is told so rather than being handed a half-made one.
     */
    public function testADriverWithNoProfileCannotStartOnboarding(): void
    {
        $this->actingAsUnregisteredDriver();

        $response = $this->post('/drive/connect/start', ['_csrf' => Csrf::token()]);

        self::assertSame(403, $response->status);
        self::assertNotContains('accounts.create', $this->stripe->calledMethods());
    }
}
