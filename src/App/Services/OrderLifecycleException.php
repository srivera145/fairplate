<?php

namespace Keel\App\Services;

/**
 * An order was asked to do something its current status does not allow.
 *
 * Always a bug or a stale screen, never a user mistake worth an apologetic
 * message: a kitchen tablet still showing Accept on an order the customer
 * cancelled thirty seconds ago should be told no and refreshed.
 */
class OrderLifecycleException extends \RuntimeException
{
    public static function illegalTransition(int $orderId, string $from, string $to): self
    {
        return new self("Order {$orderId} cannot move from \"{$from}\" to \"{$to}\".");
    }

    public static function unknownStatus(string $status): self
    {
        return new self("\"{$status}\" is not an order status.");
    }

    public static function missingOrder(int $orderId): self
    {
        return new self("Order {$orderId} does not exist.");
    }
}
