<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * A Stripe client for the customer app: payment intents, customers,
 * subscriptions and the portal, answered from memory.
 *
 * StripeClient resolves every service through getService(), so overriding that
 * one method stands in for the whole SDK. The stand-ins return real
 * Stripe\PaymentIntent and Stripe\Checkout\Session objects built with
 * constructFrom, so the code under test reads the same properties it would in
 * production and a typo in a property name still fails here.
 *
 * It records every call, which is how a test asserts the two things that matter
 * most about checkout and cannot be asserted from the database: that the intent
 * was created with capture_method=manual, and that nothing ever called capture.
 */
final class FakeStripePayments extends StripeClient
{
    /** @var list<array{service: string, method: string, params: array}> */
    public array $calls = [];

    /**
     * Payment intents by id.
     *
     * Deliberately not called $paymentIntents: StripeClient resolves its
     * services through __get, and a real property of that name would shadow the
     * magic getter so $client->paymentIntents handed back this array instead of
     * the service.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $intentRows = [];

    public string $customerId = 'cus_fake_customer';

    public string $checkoutUrl = 'https://checkout.stripe.com/c/pay/cs_test_fake';

    public string $portalUrl = 'https://billing.stripe.com/p/session/test_fake';

    /** When set, every call throws it. */
    public ?\Throwable $failWith = null;

    private int $sequence = 0;

    public function __construct()
    {
        parent::__construct(['api_key' => 'sk_test_fake']);
    }

    public function getService($name)
    {
        return match ($name) {
            'paymentIntents' => new FakePaymentIntentService($this),
            'customers' => new FakeCustomerService($this),
            'checkout' => new FakeCheckoutNamespace($this),
            'billingPortal' => new FakeBillingPortalNamespace($this),
            default => parent::getService($name),
        };
    }

    public function record(string $service, string $method, array $params): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->calls[] = ['service' => $service, 'method' => $method, 'params' => $params];
    }

    /** @return list<string> "service.method" for each call, in order */
    public function calledMethods(): array
    {
        return array_map(
            static fn (array $call): string => $call['service'] . '.' . $call['method'],
            $this->calls
        );
    }

    public function lastParamsFor(string $service, string $method): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['service'] === $service && $call['method'] === $method) {
                return $call['params'];
            }
        }

        return null;
    }

    public function nextPaymentIntentId(): string
    {
        return 'pi_fake_' . str_pad((string) (++$this->sequence), 4, '0', STR_PAD_LEFT);
    }

    /**
     * The intent as Stripe would report it once the card has authorized: the
     * amount is capturable and nothing has been taken.
     */
    public function authorize(string $paymentIntentId): PaymentIntent
    {
        $intent = $this->intentRows[$paymentIntentId] ?? null;

        if ($intent === null) {
            throw new \RuntimeException("No fake payment intent {$paymentIntentId}.");
        }

        $intent['status'] = 'requires_capture';
        $intent['amount_capturable'] = $intent['amount'];
        $this->intentRows[$paymentIntentId] = $intent;

        return PaymentIntent::constructFrom($intent);
    }

    /**
     * @return array<string, mixed>
     */
    public function intent(string $paymentIntentId): array
    {
        return $this->intentRows[$paymentIntentId] ?? [];
    }
}

/** @internal */
final class FakePaymentIntentService
{
    public function __construct(private FakeStripePayments $client)
    {
    }

    public function create(array $params = [], $opts = null): PaymentIntent
    {
        $this->client->record('paymentIntents', 'create', $params);

        $id = $this->client->nextPaymentIntentId();
        $intent = array_merge([
            'id' => $id,
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
            'amount_capturable' => 0,
            'client_secret' => $id . '_secret_fake',
        ], $params);

        $this->client->intentRows[$id] = $intent;

        return PaymentIntent::constructFrom($intent);
    }

    public function retrieve($id, $params = null, $opts = null): PaymentIntent
    {
        $this->client->record('paymentIntents', 'retrieve', ['id' => $id]);

        return PaymentIntent::constructFrom($this->client->intentRows[$id] ?? [
            'id' => $id,
            'object' => 'payment_intent',
            'status' => 'canceled',
        ]);
    }

    public function update($id, array $params = [], $opts = null): PaymentIntent
    {
        $this->client->record('paymentIntents', 'update', ['id' => $id] + $params);

        $intent = array_merge($this->client->intentRows[$id] ?? ['id' => $id], $params);
        $this->client->intentRows[$id] = $intent;

        return PaymentIntent::constructFrom($intent);
    }

    public function capture($id, array $params = [], $opts = null): PaymentIntent
    {
        $this->client->record('paymentIntents', 'capture', ['id' => $id] + $params);

        return PaymentIntent::constructFrom($this->client->intentRows[$id] ?? ['id' => $id]);
    }
}

/** @internal */
final class FakeCustomerService
{
    public function __construct(private FakeStripePayments $client)
    {
    }

    public function create(array $params = [], $opts = null): Customer
    {
        $this->client->record('customers', 'create', $params);

        return Customer::constructFrom([
            'id' => $this->client->customerId,
            'object' => 'customer',
        ]);
    }
}

/** @internal */
final class FakeCheckoutNamespace
{
    public FakeCheckoutSessionService $sessions;

    public function __construct(FakeStripePayments $client)
    {
        $this->sessions = new FakeCheckoutSessionService($client);
    }
}

/** @internal */
final class FakeCheckoutSessionService
{
    public function __construct(private FakeStripePayments $client)
    {
    }

    public function create(array $params = [], $opts = null): CheckoutSession
    {
        $this->client->record('checkout.sessions', 'create', $params);

        return CheckoutSession::constructFrom([
            'id' => 'cs_test_fake',
            'object' => 'checkout.session',
            'url' => $this->client->checkoutUrl,
        ]);
    }
}

/** @internal */
final class FakeBillingPortalNamespace
{
    public FakePortalSessionService $sessions;

    public function __construct(FakeStripePayments $client)
    {
        $this->sessions = new FakePortalSessionService($client);
    }
}

/** @internal */
final class FakePortalSessionService
{
    public function __construct(private FakeStripePayments $client)
    {
    }

    public function create(array $params = [], $opts = null): PortalSession
    {
        $this->client->record('billingPortal.sessions', 'create', $params);

        return PortalSession::constructFrom([
            'id' => 'bps_test_fake',
            'object' => 'billing_portal.session',
            'url' => $this->client->portalUrl,
        ]);
    }
}
