<?php

namespace Keel\App\Services;

use Keel\App\Models\Address;
use Keel\App\Models\CheckoutIntent;
use Keel\App\Models\Membership;
use Keel\App\Models\Order;
use Keel\App\Models\OrderItem;
use Keel\App\Models\OrderItemOption;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Models\Restaurant;
use Keel\App\Models\User;
use Keel\App\Services\Pricing\Breakdown;
use Keel\App\Services\Pricing\PricingService;
use Keel\App\Services\Routing\RoutingFactory;
use Keel\Core\Activity;
use Keel\Core\Database;
use Stripe\PaymentIntent;

/**
 * Checkout, in two halves that never meet in the same request.
 *
 * The first half runs while the customer is looking at the screen. It re-prices
 * the cart from the server's own rows, creates or updates a PaymentIntent for
 * the authorization total, and writes everything the second half will need into
 * checkout_intents. Nothing about an order exists yet.
 *
 * The second half runs in the webhook, minutes or milliseconds later, with no
 * session and no browser. It reads the frozen row, checks that Stripe is holding
 * exactly the amount that row says it should be, and writes the order.
 *
 * Splitting it this way is not caution, it is the requirement: the order is
 * created on payment_intent.amount_capturable_updated and by nothing else. A
 * customer who closes the tab on the Stripe redirect still gets their order; a
 * customer whose card is declined never gets one; and a page that lies about
 * what it is paying changes nothing, because the amount came from here.
 *
 * The authorization is the total with wait pay at its cap, which is what makes
 * "you'll be charged $X, max $Y" a promise rather than an estimate.
 */
class CheckoutService
{
    public const CURRENCY = 'usd';

    /** Why a cart cannot be paid for yet. */
    public const OUT_OF_ZONE = 'Not in our delivery area yet.';

    public function __construct(
        private readonly ?CartService $cart = null,
        private readonly ?PricingService $pricing = null,
    ) {
    }

    // -----------------------------------------------------------------
    // Before payment
    // -----------------------------------------------------------------

    /**
     * Prices the cart and readies a PaymentIntent for the authorization total.
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     summary: array<string, mixed>,
     *     address: array<string, mixed>|null,
     *     quote: array<string, mixed>|null,
     *     intent: array<string, mixed>|null,
     *     client_secret: string|null
     * }
     */
    public function prepare(int $userId, bool $withPaymentIntent = true): array
    {
        $summary = $this->cart()->summary($userId);
        $errors = $this->blockers($summary);
        $address = $this->addressFor($userId, $summary['cart']);

        if ($address === null) {
            $errors[] = 'Add a delivery address before checking out.';
        } elseif (($zoneError = $this->zoneError($address, $summary['restaurant'])) !== null) {
            $errors[] = $zoneError;
        }

        if ($errors !== []) {
            return $this->unpayable($summary, $address, $errors);
        }

        $quote = $this->quote($userId, $summary, $address);

        if (!$withPaymentIntent) {
            return [
                'ok' => true,
                'errors' => [],
                'summary' => $summary,
                'address' => $address,
                'quote' => $quote,
                'intent' => null,
                'client_secret' => null,
            ];
        }

        [$intent, $clientSecret] = $this->syncPaymentIntent($userId, $summary, $address, $quote);

        return [
            'ok' => true,
            'errors' => [],
            'summary' => $summary,
            'address' => $address,
            'quote' => $quote,
            'intent' => $intent,
            'client_secret' => $clientSecret,
        ];
    }

    /**
     * The two breakdowns for this cart, with no Stripe involved.
     *
     * The screen, the nudge and the payment all read this one answer, so the
     * total on the button is the total the card is authorized for by
     * construction rather than by agreement.
     *
     * @return array<string, mixed>
     */
    public function quote(int $userId, array $summary, array $address): array
    {
        $cart = $summary['cart'];

        return $this->pricing()->quote(
            ['items' => $this->cart()->pricingItems($summary['lines'])] + $this->cart()->tipFor($cart),
            $summary['restaurant'],
            $address,
            ['id' => $userId, 'is_member' => Membership::isActiveFor($userId)]
        );
    }

    /**
     * Why this cart cannot be paid for.
     *
     * @return list<string>
     */
    public function blockers(array $summary): array
    {
        $errors = [];

        if ($summary['restaurant'] === null || $summary['lines'] === []) {
            $errors[] = 'Your cart is empty.';

            return $errors;
        }

        if ($summary['has_problems']) {
            $errors[] = 'Something in your cart has changed. Check it before paying.';
        }

        $restaurant = $summary['restaurant'];

        if ((string) $restaurant['status'] !== Restaurant::STATUS_ACTIVE) {
            $errors[] = 'This restaurant is not taking orders right now.';
        } elseif (Restaurant::isPaused($restaurant)) {
            $errors[] = 'This restaurant has paused orders. Try again shortly.';
        } elseif (!RestaurantHours::isOpenAt($restaurant['hours'] ?? null)) {
            $errors[] = 'This restaurant is closed right now.';
        }

        return $errors;
    }

    /**
     * The delivery address for this checkout: whatever the cart points at, or
     * the customer's default.
     */
    public function addressFor(int $userId, array $cart): ?array
    {
        $addressId = $cart['address_id'] ?? null;

        if ($addressId !== null) {
            $address = Address::forUserAndId($userId, (int) $addressId);

            if ($address !== null) {
                return $address;
            }
        }

        return Address::defaultForUser($userId);
    }

    /**
     * Whether this address can be delivered to from this restaurant.
     *
     * Two questions, and the customer-facing wording only answers the first,
     * because "we do not go there yet" is the useful half.
     */
    public function zoneError(array $address, ?array $restaurant): ?string
    {
        $lat = $address['lat'] ?? null;
        $lng = $address['lng'] ?? null;

        if ($lat === null || $lng === null) {
            return self::OUT_OF_ZONE;
        }

        $zone = ZoneService::zoneFor((float) $lat, (float) $lng);

        if ($zone === null) {
            return self::OUT_OF_ZONE;
        }

        $restaurantZoneId = $restaurant['delivery_zone_id'] ?? null;

        if ($restaurantZoneId !== null && (int) $restaurantZoneId !== (int) $zone['id']) {
            return 'This restaurant does not deliver to that address.';
        }

        return null;
    }

    // -----------------------------------------------------------------
    // After payment: the webhook's half
    // -----------------------------------------------------------------

    /**
     * Turns an authorized PaymentIntent into an order, once.
     *
     * Three gates before anything is written: the intent must be one this
     * application created, Stripe must be holding the exact amount that intent
     * was quoted at, and the claim on the row must be ours. A redelivered event
     * fails the third and returns the order that already exists.
     *
     * @return int|null the order id, or null when there was nothing to place
     */
    public function placeFromPaymentIntent(PaymentIntent $paymentIntent): ?int
    {
        $intent = CheckoutIntent::findByPaymentIntent((string) $paymentIntent->id);

        if ($intent === null) {
            error_log('[FairPlate] No checkout intent for payment intent ' . (string) $paymentIntent->id);

            return null;
        }

        if ((string) $intent['status'] !== CheckoutIntent::STATUS_PENDING) {
            return $intent['order_id'] === null ? null : (int) $intent['order_id'];
        }

        $capturable = (int) ($paymentIntent->amount_capturable ?? 0);
        $authorized = (int) $intent['authorized_cents'];

        if ($capturable !== $authorized) {
            error_log(sprintf(
                '[FairPlate] Payment intent %s holds %d but was quoted %d; not placing.',
                (string) $paymentIntent->id,
                $capturable,
                $authorized
            ));

            return null;
        }

        if (!CheckoutIntent::claim((int) $intent['id'])) {
            $fresh = CheckoutIntent::find((int) $intent['id']);

            return $fresh === null || $fresh['order_id'] === null ? null : (int) $fresh['order_id'];
        }

        $orderId = $this->writeOrder($intent, $paymentIntent);

        CheckoutIntent::update((int) $intent['id'], ['order_id' => $orderId]);
        $this->cart()->clear((int) $intent['user_id']);

        Activity::log('order.placed', 'Order', $orderId, [
            'payment_intent' => (string) $paymentIntent->id,
            'authorized_cents' => $authorized,
        ]);

        return $orderId;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Creates the PaymentIntent, or moves an existing one to the new amount.
     *
     * Reusing the intent is what lets the tip change without abandoning a card
     * the customer has already typed. The amount is always the server's, which
     * is the whole point: there is no path by which a number from the page
     * reaches Stripe.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function syncPaymentIntent(int $userId, array $summary, array $address, array $quote): array
    {
        /** @var Breakdown $authorization */
        $authorization = $quote['authorization'];
        /** @var Breakdown $estimate */
        $estimate = $quote['estimate'];

        $user = User::find($userId) ?? [];
        $customerId = StripeCustomer::idFor($user);
        $existing = CheckoutIntent::pendingForUser($userId);
        $stripe = StripeClientFactory::make();

        $metadata = [
            'user_id' => (string) $userId,
            'restaurant_id' => (string) $summary['restaurant']['id'],
            'estimate_cents' => (string) $estimate->total(),
            'authorized_cents' => (string) $authorization->total(),
        ];

        $paymentIntent = null;

        if ($existing !== null) {
            $paymentIntent = $this->updatePaymentIntent(
                (string) $existing['stripe_payment_intent_id'],
                $authorization->total(),
                $metadata
            );
        }

        if ($paymentIntent === null) {
            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => $authorization->total(),
                'currency' => self::CURRENCY,
                // The card is held, not taken. Delivery captures the real total,
                // which can never exceed what is held here.
                'capture_method' => 'manual',
                'customer' => $customerId,
                // A tip raised within the adjust window is charged separately,
                // off session, so the card has to survive this order.
                'setup_future_usage' => 'off_session',
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => $metadata,
            ]);

            if ($existing !== null) {
                CheckoutIntent::update((int) $existing['id'], ['status' => CheckoutIntent::STATUS_ABANDONED]);
                $existing = null;
            }
        }

        $row = $this->intentRow($userId, $summary, $address, $quote, (string) $paymentIntent->id);

        if ($existing !== null) {
            CheckoutIntent::update((int) $existing['id'], $row);
            $intentId = (int) $existing['id'];
        } else {
            $intentId = CheckoutIntent::create($row);
        }

        CheckoutIntent::abandonOpenFor($userId, $intentId);

        return [CheckoutIntent::find($intentId) ?? [], (string) $paymentIntent->client_secret];
    }

    /**
     * Moves an existing intent to a new amount, or gives up on it.
     *
     * Stripe refuses an amount change once an intent has moved past accepting
     * one — it has been confirmed, cancelled or already authorized. That is not
     * an error worth showing anybody: the answer is a fresh intent, so this
     * returns null and the caller makes one.
     */
    private function updatePaymentIntent(string $paymentIntentId, int $amount, array $metadata): ?PaymentIntent
    {
        if (trim($paymentIntentId) === '') {
            return null;
        }

        try {
            $stripe = StripeClientFactory::make();
            $paymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId, []);

            if (!in_array((string) $paymentIntent->status, ['requires_payment_method', 'requires_confirmation'], true)) {
                return null;
            }

            if ((int) $paymentIntent->amount === $amount) {
                return $paymentIntent;
            }

            return $stripe->paymentIntents->update($paymentIntentId, [
                'amount' => $amount,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Could not reuse payment intent ' . $paymentIntentId . ': ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * Everything the webhook will need, as the checkout_intents row.
     *
     * @return array<string, mixed>
     */
    private function intentRow(int $userId, array $summary, array $address, array $quote, string $paymentIntentId): array
    {
        /** @var Breakdown $authorization */
        $authorization = $quote['authorization'];
        /** @var Breakdown $estimate */
        $estimate = $quote['estimate'];

        return [
            'user_id' => $userId,
            'restaurant_id' => (int) $summary['restaurant']['id'],
            'address_id' => (int) $address['id'],
            'stripe_payment_intent_id' => $paymentIntentId,
            'address_snapshot' => (string) json_encode(Address::snapshot($address), JSON_UNESCAPED_SLASHES),
            'cart_snapshot' => (string) json_encode($this->cartSnapshot($summary, $quote), JSON_UNESCAPED_SLASHES),
            'quote_snapshot' => (string) json_encode([
                'estimate' => $estimate->toArray(),
                'authorization' => $authorization->toArray(),
            ], JSON_UNESCAPED_SLASHES),
            'route_miles' => number_format((float) $quote['route_miles'], 2, '.', ''),
            'is_member' => (bool) $quote['is_member'],
            'estimate_cents' => $estimate->total(),
            'authorized_cents' => $authorization->total(),
            'status' => CheckoutIntent::STATUS_PENDING,
            'order_id' => null,
        ];
    }

    /**
     * The lines as they will be written onto the order.
     *
     * Names and prices are frozen here rather than looked up again in the
     * webhook, so a menu edited between the authorization and the delivery
     * cannot rewrite what somebody agreed to pay.
     *
     * @return list<array<string, mixed>>
     */
    private function cartSnapshot(array $summary, array $quote): array
    {
        $pricedLines = $quote['subtotal']['items'] ?? [];
        $snapshot = [];

        foreach (array_values($summary['lines']) as $index => $line) {
            $snapshot[] = [
                'menu_item_id' => $line['menu_item_id'],
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'unit_price_cents' => $line['unit_price_cents'],
                'line_total_cents' => (int) ($pricedLines[$index]['line_cents'] ?? 0),
                'discount_cents' => (int) ($pricedLines[$index]['discount_cents'] ?? 0),
                'notes' => $line['notes'],
                'options' => array_map(static fn (array $option): array => [
                    'item_option_id' => $option['id'],
                    'group_name' => $option['group_name'],
                    'name' => $option['name'],
                    'price_delta_cents' => $option['price_delta_cents'],
                ], $line['options']),
            ];
        }

        return $snapshot;
    }

    /**
     * The order, its lines and both breakdowns, in one transaction.
     *
     * All of it or none of it: an order without its authorized breakdown cannot
     * be captured, and a breakdown without its order is a row nobody can read.
     */
    private function writeOrder(array $intent, PaymentIntent $paymentIntent): int
    {
        $connection = Database::connection();
        $connection->beginTransaction();

        try {
            // status is left to the column default, so nothing outside
            // OrderLifecycle ever writes an order status.
            $orderId = Order::create([
                'customer_id' => (int) $intent['user_id'],
                'restaurant_id' => (int) $intent['restaurant_id'],
                'address_snapshot' => (string) $intent['address_snapshot'],
                'route_miles' => (string) $intent['route_miles'],
                'placed_at' => gmdate('Y-m-d H:i:s'),
                'authorized_cents' => (int) $intent['authorized_cents'],
                'stripe_payment_intent_id' => (string) $paymentIntent->id,
                'is_member_order' => (int) $intent['is_member'] === 1,
            ]);

            foreach (CheckoutIntent::json($intent, 'cart_snapshot') as $line) {
                $orderItemId = OrderItem::create([
                    'order_id' => $orderId,
                    'menu_item_id' => (int) $line['menu_item_id'],
                    'name_snapshot' => (string) $line['name'],
                    'unit_price_cents' => (int) $line['unit_price_cents'],
                    'quantity' => (int) $line['quantity'],
                    'line_total_cents' => (int) $line['line_total_cents'],
                    'notes' => ($line['notes'] ?? '') === '' ? null : (string) $line['notes'],
                ]);

                foreach ($line['options'] ?? [] as $option) {
                    OrderItemOption::create([
                        'order_item_id' => $orderItemId,
                        'item_option_id' => (int) $option['item_option_id'],
                        'group_name_snapshot' => (string) $option['group_name'],
                        'name_snapshot' => (string) $option['name'],
                        'price_delta_cents' => (int) $option['price_delta_cents'],
                    ]);
                }
            }

            $quote = CheckoutIntent::json($intent, 'quote_snapshot');

            foreach ([OrderPriceBreakdown::STAGE_ESTIMATE, OrderPriceBreakdown::STAGE_AUTHORIZED] as $stage) {
                $key = $stage === OrderPriceBreakdown::STAGE_ESTIMATE ? 'estimate' : 'authorization';
                $breakdown = $this->breakdownFromSnapshot($quote[$key] ?? []);

                OrderPriceBreakdown::create($breakdown->toRow($orderId, $stage));
            }

            $connection->commit();

            return $orderId;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * @param array{lines?: array<string, int>, total?: int, meta?: array<string, mixed>} $snapshot
     */
    private function breakdownFromSnapshot(array $snapshot): Breakdown
    {
        $breakdown = new Breakdown(
            array_map('intval', (array) ($snapshot['lines'] ?? [])),
            (int) ($snapshot['total'] ?? 0),
            (array) ($snapshot['meta'] ?? [])
        );

        // The row was written by this service minutes ago; if it no longer
        // balances, something rewrote it and it must not become an order.
        $breakdown->assertBalanced();

        return $breakdown;
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function unpayable(array $summary, ?array $address, array $errors): array
    {
        return [
            'ok' => false,
            'errors' => $errors,
            'summary' => $summary,
            'address' => $address,
            'quote' => null,
            'intent' => null,
            'client_secret' => null,
        ];
    }

    private function cart(): CartService
    {
        return $this->cart ?? new CartService($this->pricing);
    }

    private function pricing(): PricingService
    {
        return $this->pricing ?? new PricingService(RoutingFactory::make());
    }
}
