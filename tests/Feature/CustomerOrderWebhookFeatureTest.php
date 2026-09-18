<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\CheckoutIntent;
use Keel\App\Models\Order;
use Keel\App\Models\OrderItem;
use Keel\App\Models\OrderItemOption;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\CartService;
use Keel\App\Services\Pricing\Breakdown;
use Tests\Support\CustomerFixtures;
use Tests\Support\FakeStripePayments;
use Tests\TestCase;

/**
 * The order is born in the webhook, or not at all.
 *
 * Paying is a thing that happens between a customer's browser and Stripe, and
 * this application is not in that conversation. What it gets is
 * payment_intent.amount_capturable_updated, some seconds later, possibly twice,
 * possibly while the customer's tab is already closed. So: signature, then
 * claim, then write — and a second delivery of the same event has to leave
 * exactly one order behind.
 */
class CustomerOrderWebhookFeatureTest extends TestCase
{
    use CustomerFixtures;

    private const AUTHORIZED_CENTS = 2132;
    private const ESTIMATE_CENTS = 1823;

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

    public function testTheAuthorizationWebhookCreatesTheOrderItsLinesAndBothBreakdowns(): void
    {
        $context = $this->readyCheckout();

        self::assertSame(0, Order::count(), 'Nothing exists before the webhook.');

        $response = $this->sendAuthorization($context['payment_intent_id'], 'evt_auth_001');

        self::assertSame(200, $response->status);
        self::assertTrue((bool) ($response->json()['received'] ?? false));
        self::assertSame(1, Order::count());

        $order = Order::forCustomer($context['user_id'])[0];

        self::assertSame(Order::STATUS_PLACED, (string) $order['status']);
        self::assertNotNull($order['placed_at']);
        self::assertSame($context['restaurant_id'], (int) $order['restaurant_id']);
        self::assertSame(self::AUTHORIZED_CENTS, (int) $order['authorized_cents']);
        self::assertNull($order['captured_cents'], 'An authorization is not a capture.');
        self::assertSame($context['payment_intent_id'], (string) $order['stripe_payment_intent_id']);
        self::assertSame('3.00', (string) $order['route_miles'], 'The route miles are locked at checkout.');

        $items = OrderItem::forOrder((int) $order['id']);

        self::assertCount(1, $items);
        self::assertSame('Al Pastor Taco', (string) $items[0]['name_snapshot']);
        self::assertSame(2, (int) $items[0]['quantity']);
        self::assertSame(750, (int) $items[0]['line_total_cents']);

        $estimate = OrderPriceBreakdown::forStage((int) $order['id'], OrderPriceBreakdown::STAGE_ESTIMATE);
        $authorized = OrderPriceBreakdown::forStage((int) $order['id'], OrderPriceBreakdown::STAGE_AUTHORIZED);

        self::assertNotNull($estimate);
        self::assertNotNull($authorized);
        self::assertSame(self::ESTIMATE_CENTS, (int) $estimate['total_cents']);
        self::assertSame(self::AUTHORIZED_CENTS, (int) $authorized['total_cents']);
        self::assertSame(0, (int) $estimate['wait_pay_cents'], 'The estimate assumes no delay.');
        self::assertSame(300, (int) $authorized['wait_pay_cents'], 'The authorization assumes the worst.');

        foreach ([$estimate, $authorized] as $row) {
            $breakdown = Breakdown::fromRow($row);

            self::assertTrue($breakdown->isBalanced(), 'A stored breakdown has to sum to its own total.');
            self::assertNotSame([], OrderPriceBreakdown::settingsSnapshot($row), 'The settings are frozen onto it.');
        }
    }

    public function testAReplayedWebhookDoesNotCreateASecondOrder(): void
    {
        $context = $this->readyCheckout();

        $first = $this->sendAuthorization($context['payment_intent_id'], 'evt_auth_replay');
        $second = $this->sendAuthorization($context['payment_intent_id'], 'evt_auth_replay');

        self::assertSame(200, $first->status);
        self::assertSame(200, $second->status);
        self::assertTrue((bool) ($second->json()['duplicate'] ?? false), 'A replay is answered, not worked.');
        self::assertSame(1, Order::count());
        self::assertSame(1, OrderItem::count());
        self::assertSame(2, OrderPriceBreakdown::count(), 'Two stages, once.');
    }

    public function testASecondEventForThePaymentIntentStillLeavesOneOrder(): void
    {
        $context = $this->readyCheckout();

        // Stripe can send amount_capturable_updated more than once with distinct
        // event ids; the event log cannot catch that one, so the intent's own
        // claim has to.
        $this->sendAuthorization($context['payment_intent_id'], 'evt_auth_a');
        $this->sendAuthorization($context['payment_intent_id'], 'evt_auth_b');

        self::assertSame(1, Order::count());
    }

    public function testAnUnsignedWebhookCreatesNothing(): void
    {
        $context = $this->readyCheckout();

        $payload = (string) json_encode($this->eventPayload($context['payment_intent_id'], 'evt_forged'));

        $response = $this->postRawJson('/webhooks/stripe', $payload, [
            'Stripe-Signature' => 't=' . time() . ',v1=deadbeef',
        ]);

        self::assertSame(400, $response->status);
        self::assertSame(0, Order::count());
    }

    public function testAHoldForTheWrongAmountIsRefusedRatherThanTurnedIntoAnOrder(): void
    {
        $context = $this->readyCheckout();

        $payload = $this->eventPayload($context['payment_intent_id'], 'evt_auth_short');
        // A hold for a dollar against a quote of twenty-one.
        $payload['data']['object']['amount'] = 100;
        $payload['data']['object']['amount_capturable'] = 100;

        $response = $this->sendPayload($payload);

        self::assertSame(200, $response->status, 'Stripe is told we heard it; it must not retry forever.');
        self::assertSame(0, Order::count());

        $intent = CheckoutIntent::findByPaymentIntent($context['payment_intent_id']);

        self::assertSame(CheckoutIntent::STATUS_PENDING, (string) $intent['status'], 'The quote is untouched.');
    }

    public function testPlacingAnOrderEmptiesTheCartItCameFrom(): void
    {
        $context = $this->readyCheckout();

        self::assertSame(2, (new CartService())->count($context['user_id']));

        $this->sendAuthorization($context['payment_intent_id'], 'evt_auth_clears');

        self::assertSame(0, (new CartService())->count($context['user_id']), 'A placed cart is not still a cart.');
    }

    public function testTheChosenOptionsAreFrozenOntoTheOrderLine(): void
    {
        $shop = $this->createOrderableRestaurant();
        $groupId = $this->createOptionGroup($shop['item_id'], 'Which size?');
        $optionId = $this->createOption($groupId, 'Large', 100);

        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];
        $this->createAddress($userId);

        (new CartService())->add($userId, $shop['item_id'], 1, [$optionId]);
        $this->get('/app/checkout');

        $paymentIntentId = (string) CheckoutIntent::pendingForUser($userId)['stripe_payment_intent_id'];
        $this->sendAuthorization($paymentIntentId, 'evt_auth_options');

        $order = Order::forCustomer($userId)[0];
        $item = OrderItem::forOrder((int) $order['id'])[0];
        $options = OrderItemOption::forOrderItem((int) $item['id']);

        self::assertCount(1, $options);
        self::assertSame('Large', (string) $options[0]['name_snapshot']);
        self::assertSame('Which size?', (string) $options[0]['group_name_snapshot']);
        self::assertSame(100, (int) $options[0]['price_delta_cents']);
        self::assertSame(475, (int) $item['line_total_cents'], '375 for the taco plus 100 for the size.');
    }

    /**
     * A signed-in customer with a cart, an address, and a payment intent waiting.
     *
     * @return array{user_id: int, restaurant_id: int, payment_intent_id: string}
     */
    private function readyCheckout(): array
    {
        $shop = $this->createOrderableRestaurant();
        $customer = $this->actingAsCustomer();
        $userId = (int) $customer['user']['id'];
        $this->createAddress($userId);

        (new CartService())->add($userId, $shop['item_id'], 2, []);
        $this->get('/app/checkout');

        $intent = CheckoutIntent::pendingForUser($userId);

        self::assertNotNull($intent, 'Checkout has to have readied a payment before the webhook can land.');

        return [
            'user_id' => $userId,
            'restaurant_id' => $shop['restaurant_id'],
            'payment_intent_id' => (string) $intent['stripe_payment_intent_id'],
        ];
    }

    private function sendAuthorization(string $paymentIntentId, string $eventId): \Tests\Support\TestResponse
    {
        return $this->sendPayload($this->eventPayload($paymentIntentId, $eventId));
    }

    private function sendPayload(array $payload): \Tests\Support\TestResponse
    {
        $raw = (string) json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $raw, 'whsec_feature_test');

        return $this->postRawJson('/webhooks/stripe', $raw, [
            'Stripe-Signature' => 't=' . $timestamp . ',v1=' . $signature,
        ]);
    }

    /**
     * The event Stripe sends once the card is holding the authorization.
     *
     * @return array<string, mixed>
     */
    private function eventPayload(string $paymentIntentId, string $eventId): array
    {
        $intent = $this->stripe->intent($paymentIntentId);

        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.amount_capturable_updated',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'object' => 'payment_intent',
                    'status' => 'requires_capture',
                    'capture_method' => 'manual',
                    'currency' => 'usd',
                    'amount' => (int) ($intent['amount'] ?? 0),
                    'amount_capturable' => (int) ($intent['amount'] ?? 0),
                    'amount_received' => 0,
                ],
            ],
        ];
    }
}
