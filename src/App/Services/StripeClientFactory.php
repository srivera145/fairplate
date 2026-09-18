<?php

namespace Keel\App\Services;

use Keel\Core\Env;
use Stripe\StripeClient;

/**
 * Hands a service the Stripe client.
 *
 * The same seam GeocoderFactory and RoutingFactory are, for the same reason: a
 * controller built with no constructor arguments cannot be given collaborators,
 * and checkout, memberships and the webhook all need the same client. One place
 * reads the secret key, so a test swaps once rather than once per service.
 */
final class StripeClientFactory
{
    private static ?StripeClient $override = null;

    public static function make(): StripeClient
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $secretKey = trim((string) Env::get('STRIPE_SECRET_KEY', ''));

        if ($secretKey === '') {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured.');
        }

        return new StripeClient($secretKey);
    }

    public static function publishableKey(): string
    {
        return trim((string) Env::get('STRIPE_PUBLISHABLE_KEY', ''));
    }

    /**
     * Replaces the client for the rest of the process. Tests only.
     */
    public static function swap(?StripeClient $client): void
    {
        self::$override = $client;
    }
}
