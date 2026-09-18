<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\Account;
use Stripe\AccountLink;
use Stripe\StripeClient;

/**
 * A Stripe client that answers from a script instead of the network.
 *
 * StripeClient resolves every service through getService(), so overriding that
 * one method is enough to stand in for the whole SDK. The stand-ins return real
 * Stripe\Account and Stripe\AccountLink objects built with constructFrom, so the
 * code under test reads the same properties it would in production and a typo in
 * a property name still fails here.
 */
final class FakeStripeClient extends StripeClient
{
    /** @var list<array{service: string, method: string, params: array}> */
    public array $calls = [];

    public string $accountId = 'acct_testfake0001';

    public string $linkUrl = 'https://connect.stripe.com/setup/e/acct_testfake0001/abc123';

    /** Merged into the account this client reports. */
    public array $accountState = [
        'details_submitted' => false,
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'requirements' => ['currently_due' => ['external_account']],
    ];

    /** When set, every call throws it. */
    public ?\Throwable $failWith = null;

    public function __construct()
    {
        parent::__construct(['api_key' => 'sk_test_fake']);
    }

    public function getService($name)
    {
        return match ($name) {
            'accounts' => new FakeStripeAccountService($this),
            'accountLinks' => new FakeStripeAccountLinkService($this),
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

    public function account(): Account
    {
        return Account::constructFrom(array_merge(
            ['id' => $this->accountId, 'object' => 'account', 'type' => 'express'],
            $this->accountState
        ));
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
}

/** @internal */
final class FakeStripeAccountService
{
    public function __construct(private FakeStripeClient $client)
    {
    }

    public function create(array $params = [], $opts = null): Account
    {
        $this->client->record('accounts', 'create', $params);

        return $this->client->account();
    }

    public function retrieve($id, $params = null, $opts = null): Account
    {
        $this->client->record('accounts', 'retrieve', ['id' => $id]);

        return $this->client->account();
    }
}

/** @internal */
final class FakeStripeAccountLinkService
{
    public function __construct(private FakeStripeClient $client)
    {
    }

    public function create(array $params = [], $opts = null): AccountLink
    {
        $this->client->record('accountLinks', 'create', $params);

        return AccountLink::constructFrom([
            'object' => 'account_link',
            'url' => $this->client->linkUrl,
            'expires_at' => time() + 300,
        ]);
    }
}
