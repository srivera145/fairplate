<?php

namespace Keel\App\Jobs;

use Keel\App\Services\Dispatch\DispatchService;

/**
 * Run one dispatch round for an order.
 *
 * Queued when a kitchen accepts, and again by dispatch itself when there is
 * nobody eligible yet. Everything it knows is one order id, so a retry is
 * always a fresh look at the world rather than a replay of a decision made
 * fifteen seconds ago — which matters, because the answer changes as drivers
 * come online.
 *
 * Doing nothing is a normal outcome. An order that already has a driver, or one
 * a driver is currently deciding about, comes back here as a no-op rather than
 * an error, so the queue's retry-and-fail machinery is reserved for a database
 * that is actually down.
 */
class DispatchOfferJob implements Job
{
    public function handle(array $data): void
    {
        $orderId = (int) ($data['order_id'] ?? 0);

        if ($orderId <= 0) {
            throw new \RuntimeException('DispatchOfferJob needs an order_id.');
        }

        (new DispatchService())->dispatch($orderId);
    }
}
