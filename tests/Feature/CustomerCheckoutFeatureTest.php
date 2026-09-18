<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Cart;
use Keel\App\Models\CheckoutIntent;
use Keel\App\Models\Order;
use Keel\App\Services\CartService;
use Keel\App\Services\CheckoutService;
use Tests\Support\CustomerFixtures;
use Tests\Support\FakeStripePayments;
use Tests\TestCase;

/**
 * Checkout: the money.
 *
 * The seeded settings and the fixture menu make the arithmetic knowable, so the
 * totals here are written out rather than recomputed from the service under
 * test. Two 375-cent tacos, 7.5% tax, three route miles and an 18% tip:
 *
 *   subtotal 750 + tax 56 + driver 600 + wait 0 + tip 135 + platform fee 199
 *     = 1740, grossed up to 1823, so the service fee is 83.
 *   with wait pay at its 300-cent cap: 2040, grossed up to 2132.
 *
 * A member pays no platform fee, so the same cart is 1618 and 1927.
 */
class CustomerCheckoutFeatureTest extends TestCase
{
    use CustomerFixtures;

    private const ESTIMATE_CENTS = 1823;
    private const AUTHORIZED_CENTS = 2132;
    private const MEMBER_ESTIMATE_CENTS = 1618;
    private const MEMBER_AUTHORIZED_CENTS = 1927;

    private FakeStripePayments $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPricingSettings();
        $this->stripe = $this->fakeStripe();
        $this->fakeRouting(3.0);
    }

    protected function tearDown(): void
    {
        $this->restoreCollaborators();

        parent::tearDown();
    }

    public function testTheBreakdownSumsToTheTotalAndThePaymentIntentHoldsTheAuthorization(): void
    {
        $this->readyCart();

        $response = $this->get('/app/checkout');

        self::assertSame(200, $response->status);

        // Every charge line, in the spec's order and wording.
        foreach (['Food subtotal', 'Sales tax', 'Driver pay', 'Wait pay', 'Tip', 'Platform fee', 'Service fee'] as $label) {
            self::assertStringContainsString($label, $response->body);
        }

        self::assertStringNotContainsString('surcharge', strtolower($response->body));
        self::assertStringNotContainsString('card fee', strtolower($response->body));

        $lines = $this->breakdownLines($response->body);

        self::assertSame(
            [750, 56, 600, 0, 135, 199, 83],
            array_values($lines),
            'The lines on screen are the spec\'s lines, to the cent.'
        );
        self::assertSame(
            self::ESTIMATE_CENTS,
            array_sum($lines),
            'The breakdown on screen sums exactly to the displayed total.'
        );

        self::assertStringContainsString('Charged now', $response->body);
        self::assertStringContainsString('$18.23', $response->body);
        self::assertStringContainsString(
            'Max <strong>$21.32</strong> if the restaurant is delayed',
            $response->body
        );
        self::assertStringContainsString(
            'Wait pay: $0 unless the restaurant is delayed (max $3.00).',
            $response->body
        );

        $created = $this->stripe->lastParamsFor('paymentIntents', 'create');

        self::assertIsArray($created);
        self::assertSame(
            self::AUTHORIZED_CENTS,
            (int) $created['amount'],
            'The PaymentIntent amount is the authorization total and comes only from the server quote.'
        );
    }

    public function testTheCardIsHeldRatherThanChargedAndTheMethodIsKeptForATipAdjustment(): void
    {
        $this->readyCart();
        $this->get('/app/checkout');

        $created = $this->stripe->lastParamsFor('paymentIntents', 'create');

        self::assertSame('manual', (string) $created['capture_method']);
        self::assertSame('off_session', (string) $created['setup_future_usage']);
        self::assertSame('usd', (string) $created['currency']);
        self::assertNotEmpty($created['customer'], 'A saved payment method needs a customer to hang from.');
        self::assertNotContains(
            'paymentIntents.capture',
            $this->stripe->calledMethods(),
            'Checkout authorizes; the capture belongs to delivery.'
        );
    }

    public function testAClientThatPostsItsOwnPricesChangesNothingAboutWhatIsCharged(): void
    {
        $this->readyCart();
        $this->get('/app/checkout');

        $honest = (int) $this->stripe->lastParamsFor('paymentIntents', 'create')['amount'];

        // A page trying to talk the total down: a cheaper tip, a cheaper line,
        // a cheaper everything. None of these keys is read by anything.
        $this->postJson('/app/checkout/quote', [
            'tip_mode' => 'percent',
            'tip_basis_points' => 1800,
            'subtotal_cents' => 1,
            'total_cents' => 1,
            'authorized_cents' => 1,
            'platform_fee_cents' => 0,
        ], ['X-CSRF-Token' => $this->csrfToken()]);

        $intent = CheckoutIntent::pendingForUser($this->userId());

        self::assertSame(self::AUTHORIZED_CENTS, (int) $intent['authorized_cents']);
        self::assertSame(self::ESTIMATE_CENTS, (int) $intent['estimate_cents']);
        self::assertSame(
            $honest,
            (int) $this->stripe->intent((string) $intent['stripe_payment_intent_id'])['amount'],
            'Stripe still holds the server\'s number.'
        );
    }

    public function testChangingTheTipRepricesEverythingAndMovesTheHoldWithIt(): void
    {
        $this->readyCart();
        $this->get('/app/checkout');

        $response = $this->postJson('/app/checkout/quote', [
            'tip_mode' => 'custom',
            'tip_dollars' => '5.00',
        ], ['X-CSRF-Token' => $this->csrfToken()]);

        self::assertSame(200, $response->status);

        $data = $response->json();
        $lines = $this->breakdownLines((string) $data['html']);

        self::assertSame(500, $lines['Tip']);
        self::assertSame((int) $data['estimate_cents'], array_sum($lines));

        $intent = CheckoutIntent::pendingForUser($this->userId());

        self::assertSame((int) $data['authorized_cents'], (int) $intent['authorized_cents']);
        self::assertSame(
            (int) $data['authorized_cents'],
            (int) $this->stripe->intent((string) $intent['stripe_payment_intent_id'])['amount']
        );
        self::assertContains('paymentIntents.update', $this->stripe->calledMethods());
    }

    public function testAMemberPaysNoPlatformFeeAndIsNotNudged(): void
    {
        $customer = $this->readyCart();
        $this->makeMember((int) $customer['user']['id']);

        $response = $this->get('/app/checkout');
        $lines = $this->breakdownLines($response->body);

        self::assertSame(0, $lines['Platform fee']);
        self::assertSame(self::MEMBER_ESTIMATE_CENTS, array_sum($lines));
        self::assertStringContainsString('Members pay no platform fee.', $response->body);
        self::assertStringNotContainsString("you'd have saved", $response->body);

        self::assertSame(
            self::MEMBER_AUTHORIZED_CENTS,
            (int) $this->stripe->lastParamsFor('paymentIntents', 'create')['amount']
        );
    }

    public function testTheNonMemberNudgeStaysQuietUntilTheMathIsActuallyTrue(): void
    {
        $customer = $this->readyCart();
        $userId = (int) $customer['user']['id'];
        $restaurantId = (int) Cart::forUser($userId)['restaurant_id'];

        // One order's worth of platform fee is $2.05 including the processing it
        // drags along — nowhere near the $9.99 membership.
        self::assertStringNotContainsString("you'd have saved", $this->get('/app/checkout')->body);

        // Four more this month brings the total to $10.25, which beats it by 26c.
        for ($i = 0; $i < 4; $i++) {
            $this->createChargedOrder($userId, $restaurantId);
        }

        $body = $this->get('/app/checkout')->body;

        self::assertStringContainsString('Members pay $0 platform fees', $body);
        self::assertStringContainsString("you'd have saved", $body);
        self::assertStringContainsString('$0.26', $body);
    }

    public function testAnOutOfZoneAddressStopsCheckoutWithTheSameSentenceTheAddressBookUses(): void
    {
        $customer = $this->readyCart();

        // The zone moved out from under a saved address, which is the only way
        // this happens once the address book has done its job.
        \Keel\App\Models\Address::update(
            (int) \Keel\App\Models\Address::defaultForUser((int) $customer['user']['id'])['id'],
            ['lat' => (string) self::OUTSIDE_ZONE['lat'], 'lng' => (string) self::OUTSIDE_ZONE['lng']]
        );

        $response = $this->get('/app/checkout');

        self::assertSame(200, $response->status);
        self::assertStringContainsString(CheckoutService::OUT_OF_ZONE, $response->body);
        self::assertSame([], $this->stripe->calls, 'Nothing reaches Stripe for an address we cannot deliver to.');
    }

    public function testNoOrderExistsUntilTheWebhookSaysTheCardIsHolding(): void
    {
        $this->readyCart();
        $this->get('/app/checkout');

        self::assertSame(0, Order::count(), 'Rendering checkout creates no order.');

        $intent = CheckoutIntent::pendingForUser($this->userId());

        self::assertNotNull($intent);
        self::assertSame(CheckoutIntent::STATUS_PENDING, (string) $intent['status']);
        self::assertNull($intent['order_id']);
    }

    public function testTheReturnFromStripeWaitsForTheWebhookRatherThanPlacingTheOrderItself(): void
    {
        $this->readyCart();
        $this->get('/app/checkout');

        $intent = CheckoutIntent::pendingForUser($this->userId());
        $paymentIntentId = (string) $intent['stripe_payment_intent_id'];

        $waiting = $this->get('/app/checkout/complete?payment_intent=' . $paymentIntentId);

        self::assertSame(200, $waiting->status);
        self::assertStringContainsString('Confirming your order', $waiting->body);
        self::assertStringContainsString('http-equiv="refresh"', $waiting->body, 'The wait works with no JavaScript.');
        self::assertSame(0, Order::count(), 'Coming back from Stripe creates nothing.');

        // Once the webhook has done its work, the same URL goes to the order.
        $orderId = \Keel\App\Models\Order::create([
            'customer_id' => $this->userId(),
            'restaurant_id' => (int) $intent['restaurant_id'],
            'address_snapshot' => (string) $intent['address_snapshot'],
            'placed_at' => gmdate('Y-m-d H:i:s'),
        ]);
        CheckoutIntent::update((int) $intent['id'], [
            'status' => CheckoutIntent::STATUS_PLACED,
            'order_id' => $orderId,
        ]);

        $arrived = $this->get('/app/checkout/complete?payment_intent=' . $paymentIntentId);

        self::assertSame(302, $arrived->status);
        self::assertSame('/app/orders/' . $orderId, $arrived->header('Location'));
    }

    public function testAWaitThatGoesOnTooLongStopsRefreshingAndOffersAWayOut(): void
    {
        $this->readyCart();
        $this->get('/app/checkout');

        $paymentIntentId = (string) CheckoutIntent::pendingForUser($this->userId())['stripe_payment_intent_id'];

        $response = $this->get('/app/checkout/complete?payment_intent=' . $paymentIntentId . '&waited=30');

        self::assertStringContainsString('Still confirming', $response->body);
        self::assertStringNotContainsString('http-equiv="refresh"', $response->body);
        self::assertStringContainsString('Go to your orders', $response->body);
    }

    public function testACustomerCannotWaitOnSomebodyElsesPayment(): void
    {
        $this->readyCart();
        $this->get('/app/checkout');

        $paymentIntentId = (string) CheckoutIntent::pendingForUser($this->userId())['stripe_payment_intent_id'];

        $this->actingAsCustomer(['name' => 'Someone Else']);

        $response = $this->get('/app/checkout/complete?payment_intent=' . $paymentIntentId);

        self::assertSame(302, $response->status);
        self::assertSame('/app/orders', $response->header('Location'));
    }

    /**
     * Signs in a customer with an address and one restaurant's food in the cart.
     *
     * @return array{user: array<string, mixed>, address_id: int}
     */
    private function readyCart(): array
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];
        $this->createAddress($userId);

        (new CartService())->add($userId, $shop['item_id'], 2, []);

        return $customer;
    }

    private function userId(): int
    {
        return (int) \Keel\Core\Auth::id();
    }

    /**
     * The rendered breakdown, read back out of the markup as label => cents.
     *
     * Parsing the page rather than asking the service again is the point: this
     * is what a customer actually sees, and it is what has to sum to the total
     * printed underneath it.
     *
     * @return array<string, int>
     */
    private function breakdownLines(string $html): array
    {
        if (!preg_match('#<dl class="breakdown">(.*?)</dl>#s', $html, $list)) {
            self::fail('No breakdown was rendered.');
        }

        preg_match_all(
            '#<dt(?![^>]*breakdown-total)[^>]*>(.*?)</dt>\s*<dd class="nums">\$([\d,]+\.\d{2})</dd>#s',
            $list[1],
            $matches,
            PREG_SET_ORDER
        );

        $lines = [];

        foreach ($matches as $match) {
            $label = trim(html_entity_decode(strip_tags($match[1])));
            $lines[$label] = (int) round((float) str_replace(',', '', $match[2]) * 100);
        }

        return $lines;
    }
}
