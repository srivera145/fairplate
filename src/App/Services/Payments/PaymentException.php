<?php

namespace Keel\App\Services\Payments;

/**
 * Money that could not be moved, for a reason this application knows about.
 *
 * Distinct from Stripe's own exceptions on purpose: those mean the network or
 * the API said no and are usually worth retrying, and these mean the order is
 * in a state that no retry will improve — a capture above its authorization, a
 * guarantee that does not match what a driver was promised, a refund on an
 * order nobody was charged for. A caller can tell the two apart and should.
 */
class PaymentException extends \RuntimeException
{
    public static function notCaptured(int $orderId): self
    {
        return new self("Order {$orderId} has not been captured, so there is nothing to refund.");
    }

    public static function aboveAuthorization(int $orderId, int $total, int $authorized): self
    {
        return new self(sprintf(
            'Order %d would capture %d against an authorization of %d.',
            $orderId,
            $total,
            $authorized
        ));
    }
}
