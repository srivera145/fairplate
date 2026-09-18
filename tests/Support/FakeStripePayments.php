<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\BalanceTransaction;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Charge;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Transfer;
use Stripe\TransferReversal as Reversal;

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

    /**
     * Charges by id, with the refunds list a charge.refunded event carries.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $chargeRows = [];

    /** @var array<string, array<string, mixed>> */
    public array $balanceTransactionRows = [];

    public string $customerId = 'cus_fake_customer';

    public string $checkoutUrl = 'https://checkout.stripe.com/c/pay/cs_test_fake';

    public string $portalUrl = 'https://billing.stripe.com/p/session/test_fake';

    /**
     * What Stripe kept on a capture, in cents.
     *
     * A real fee is 2.9% + 30¢ of the captured amount; the default here is the
     * same formula so that "the fee is under the service fee" means something
     * when a test asserts it. Set it to a number to force the other case.
     */
    public ?int $feeCents = null;

    /**
     * Transfers, refunds and reversals made, keyed by id.
     *
     * Deliberately not $transfers, $refunds and $reversals, for the same reason
     * $intentRows is not $paymentIntents: StripeClient resolves its services
     * through __get, and a real property of that name shadows the magic getter
     * so $client->transfers hands back this array instead of the service.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $transferRows = [];

    /** @var array<string, array<string, mixed>> */
    public array $refundRows = [];

    /** @var array<string, array<string, mixed>> */
    public array $reversalRows = [];

    /** Idempotency keys this client has already answered, key => result id. */
    public array $idempotencyKeys = [];

    /** When set, every call throws it. */
    public ?\Throwable $failWith = null;

    /**
     * Services that should throw on the next n calls, by service name.
     *
     * What a test uses to make a transfer fail twice and then succeed, which is
     * the only way to see the retry path do what it is for.
     *
     * @var array<string, int>
     */
    public array $failTimes = [];

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
            'charges' => new FakeChargeService($this),
            'balanceTransactions' => new FakeBalanceTransactionService($this),
            'refunds' => new FakeRefundService($this),
            'transfers' => new FakeTransferService($this),
            default => parent::getService($name),
        };
    }

    public function record(string $service, string $method, array $params): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        if (($this->failTimes[$service] ?? 0) > 0) {
            $this->failTimes[$service]--;

            throw new \Stripe\Exception\ApiConnectionException('Stripe is unreachable.');
        }

        $this->calls[] = ['service' => $service, 'method' => $method, 'params' => $params];
    }

    /**
     * The options a service method was handed, which is where an idempotency
     * key lives.
     *
     * @param mixed $opts
     */
    public function idempotencyKey(mixed $opts): ?string
    {
        if (is_array($opts) && isset($opts['idempotency_key'])) {
            return (string) $opts['idempotency_key'];
        }

        return null;
    }

    /**
     * Stripe's actual idempotency behaviour: a repeated key returns the first
     * answer rather than doing the work again.
     *
     * This is what makes "a retried job creates no duplicate transfers" a real
     * assertion rather than one that only passes because the code happened not
     * to call twice.
     */
    public function replayed(?string $key): ?string
    {
        return $key === null ? null : ($this->idempotencyKeys[$key] ?? null);
    }

    public function rememberKey(?string $key, string $id): void
    {
        if ($key !== null) {
            $this->idempotencyKeys[$key] = $id;
        }
    }

    public function nextId(string $prefix): string
    {
        return $prefix . '_fake_' . str_pad((string) (++$this->sequence), 4, '0', STR_PAD_LEFT);
    }

    /**
     * What Stripe would keep on a charge of this size.
     */
    public function feeFor(int $amountCents): int
    {
        if ($this->feeCents !== null) {
            return $this->feeCents;
        }

        return (int) floor($amountCents * 29 / 1000) + 30;
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

    /**
     * A charge, and the balance transaction behind it, as a capture produces.
     *
     * @return string the charge id
     */
    public function recordCharge(int $amountCents, string $paymentIntentId): string
    {
        $chargeId = $this->nextId('ch');
        $transactionId = $this->nextId('txn');

        $this->chargeRows[$chargeId] = [
            'id' => $chargeId,
            'object' => 'charge',
            'amount' => $amountCents,
            'amount_refunded' => 0,
            'payment_intent' => $paymentIntentId,
            'balance_transaction' => $transactionId,
            'refunded' => false,
            'refunds' => ['object' => 'list', 'data' => []],
        ];

        $this->balanceTransactionRows[$transactionId] = [
            'id' => $transactionId,
            'object' => 'balance_transaction',
            'amount' => $amountCents,
            'fee' => $this->feeFor($amountCents),
            'net' => $amountCents - $this->feeFor($amountCents),
        ];

        return $chargeId;
    }

    /**
     * @return array<string, mixed>
     */
    public function charge(string $chargeId): array
    {
        $row = $this->chargeRows[$chargeId] ?? ['id' => $chargeId, 'object' => 'charge'];

        // A real retrieve with expand[]=balance_transaction hands back the
        // object rather than the id, which is the branch PaymentService takes.
        $transactionId = $row['balance_transaction'] ?? null;

        if (is_string($transactionId) && isset($this->balanceTransactionRows[$transactionId])) {
            $row['balance_transaction'] = $this->balanceTransactionRows[$transactionId];
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function balanceTransaction(string $id): array
    {
        return $this->balanceTransactionRows[$id] ?? ['id' => $id, 'object' => 'balance_transaction', 'fee' => 0];
    }

    /**
     * @param array<string, mixed> $refund
     */
    public function addRefundToCharge(string $chargeId, array $refund): void
    {
        if (!isset($this->chargeRows[$chargeId])) {
            return;
        }

        $this->chargeRows[$chargeId]['refunds']['data'][] = $refund;
        $this->chargeRows[$chargeId]['amount_refunded'] += (int) $refund['amount'];
        $this->chargeRows[$chargeId]['refunded'] =
            $this->chargeRows[$chargeId]['amount_refunded'] >= $this->chargeRows[$chargeId]['amount'];
    }

    /**
     * The charge as a charge.refunded webhook would carry it.
     *
     * @return array<string, mixed>
     */
    public function chargeRow(string $chargeId): array
    {
        return $this->chargeRows[$chargeId] ?? [];
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

        $key = $this->client->idempotencyKey($opts);
        $replayed = $this->client->replayed($key);

        if ($replayed !== null) {
            return PaymentIntent::constructFrom($this->client->intentRows[$replayed]);
        }

        $id = $this->client->nextPaymentIntentId();
        $intent = array_merge([
            'id' => $id,
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
            'amount_capturable' => 0,
            'client_secret' => $id . '_secret_fake',
        ], $params);

        // An off-session charge confirms in the same call, the way a tip
        // adjustment's does, and lands with a charge behind it.
        if (!empty($params['confirm'])) {
            $chargeId = $this->client->recordCharge((int) $params['amount'], $id);
            $intent['status'] = 'succeeded';
            $intent['latest_charge'] = $chargeId;
            $intent['amount_received'] = (int) $params['amount'];
        }

        $this->client->intentRows[$id] = $intent;
        $this->client->rememberKey($key, $id);

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

        $intent = $this->client->intentRows[$id] ?? ['id' => $id, 'amount' => 0];
        $captured = (int) ($params['amount_to_capture'] ?? $intent['amount'] ?? 0);

        // Stripe's own guard, reproduced: capturing more than is held is an
        // error at the API rather than a silent over-capture.
        if ($captured > (int) ($intent['amount'] ?? 0)) {
            throw new \Stripe\Exception\InvalidRequestException(
                'Amount to capture is greater than the amount authorized.'
            );
        }

        $intent['status'] = 'succeeded';
        $intent['amount_received'] = $captured;
        $intent['amount_capturable'] = 0;
        $intent['latest_charge'] = $intent['latest_charge']
            ?? $this->client->recordCharge($captured, (string) $id);

        $this->client->intentRows[$id] = $intent;

        return PaymentIntent::constructFrom($intent);
    }

    public function cancel($id, array $params = [], $opts = null): PaymentIntent
    {
        $this->client->record('paymentIntents', 'cancel', ['id' => $id] + $params);

        $intent = array_merge(
            $this->client->intentRows[$id] ?? ['id' => $id, 'object' => 'payment_intent'],
            ['status' => 'canceled', 'amount_capturable' => 0]
        );

        $this->client->intentRows[$id] = $intent;

        return PaymentIntent::constructFrom($intent);
    }
}

/** @internal */
final class FakeChargeService
{
    public function __construct(private FakeStripePayments $client)
    {
    }

    public function retrieve($id, $params = null, $opts = null): Charge
    {
        $this->client->record('charges', 'retrieve', ['id' => $id] + (array) $params);

        return Charge::constructFrom($this->client->charge((string) $id));
    }
}

/** @internal */
final class FakeBalanceTransactionService
{
    public function __construct(private FakeStripePayments $client)
    {
    }

    public function retrieve($id, $params = null, $opts = null): BalanceTransaction
    {
        $this->client->record('balanceTransactions', 'retrieve', ['id' => $id]);

        return BalanceTransaction::constructFrom($this->client->balanceTransaction((string) $id));
    }
}

/** @internal */
final class FakeRefundService
{
    public function __construct(private FakeStripePayments $client)
    {
    }

    public function create(array $params = [], $opts = null): Refund
    {
        $this->client->record('refunds', 'create', $params);

        $key = $this->client->idempotencyKey($opts);
        $replayed = $this->client->replayed($key);

        if ($replayed !== null) {
            return Refund::constructFrom($this->client->refundRows[$replayed]);
        }

        $id = $this->client->nextId('re');
        $refund = [
            'id' => $id,
            'object' => 'refund',
            'amount' => (int) ($params['amount'] ?? 0),
            'charge' => (string) ($params['charge'] ?? ''),
            'status' => 'succeeded',
        ];

        $this->client->refundRows[$id] = $refund;
        $this->client->rememberKey($key, $id);
        $this->client->addRefundToCharge((string) $refund['charge'], $refund);

        return Refund::constructFrom($refund);
    }
}

/** @internal */
final class FakeTransferService
{
    public function __construct(private FakeStripePayments $client)
    {
    }

    public function create(array $params = [], $opts = null): Transfer
    {
        $this->client->record('transfers', 'create', $params);

        $key = $this->client->idempotencyKey($opts);
        $replayed = $this->client->replayed($key);

        if ($replayed !== null) {
            return Transfer::constructFrom($this->client->transferRows[$replayed]);
        }

        $id = $this->client->nextId('tr');
        $transfer = [
            'id' => $id,
            'object' => 'transfer',
            'amount' => (int) ($params['amount'] ?? 0),
            'amount_reversed' => 0,
            'currency' => (string) ($params['currency'] ?? 'usd'),
            'destination' => (string) ($params['destination'] ?? ''),
            'transfer_group' => (string) ($params['transfer_group'] ?? ''),
            'source_transaction' => $params['source_transaction'] ?? null,
        ];

        $this->client->transferRows[$id] = $transfer;
        $this->client->rememberKey($key, $id);

        return Transfer::constructFrom($transfer);
    }

    public function createReversal($transferId, array $params = [], $opts = null): Reversal
    {
        $this->client->record('transfers', 'createReversal', ['transfer' => $transferId] + $params);

        $key = $this->client->idempotencyKey($opts);
        $replayed = $this->client->replayed($key);

        if ($replayed !== null) {
            return Reversal::constructFrom($this->client->reversalRows[$replayed]);
        }

        $id = $this->client->nextId('trr');
        $amount = (int) ($params['amount'] ?? 0);
        $reversal = [
            'id' => $id,
            'object' => 'transfer_reversal',
            'amount' => $amount,
            'transfer' => (string) $transferId,
        ];

        $this->client->reversalRows[$id] = $reversal;
        $this->client->rememberKey($key, $id);

        if (isset($this->client->transferRows[$transferId])) {
            $this->client->transferRows[$transferId]['amount_reversed'] += $amount;
        }

        return Reversal::constructFrom($reversal);
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
