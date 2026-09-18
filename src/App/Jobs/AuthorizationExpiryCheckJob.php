<?php

namespace Keel\App\Jobs;

use Keel\App\Models\Order;
use Keel\App\Services\Payments\AdminAlert;
use Keel\App\Services\Pricing\Money;

/**
 * Hourly: which holds are about to expire with nothing taken?
 *
 * A Stripe authorization lasts seven days and some card issuers drop it sooner.
 * An order still holding one on day six is an order whose money will quietly
 * stop being there — the customer sees the hold vanish, the restaurant has
 * cooked, and there is nothing left to capture against.
 *
 * Every way of getting here is a bug somewhere else: a delivery whose capture
 * threw, a rejection whose release never ran, an order stuck in
 * needs_attention that nobody attended to. So this job does not try to fix any
 * of them. It finds them and says so, while there is still a day to act.
 *
 * Rejected and cancelled orders are not counted: their intents were cancelled
 * on purpose and expire by design.
 */
class AuthorizationExpiryCheckJob implements Job
{
    /**
     * Stripe releases at seven days. Six leaves a day to do something about it.
     */
    public const WARN_AFTER_DAYS = 6;

    public function handle(array $data): void
    {
        $days = (int) ($data['days'] ?? self::WARN_AFTER_DAYS);
        $stale = Order::staleAuthorizations($days);

        if ($stale === []) {
            return;
        }

        foreach ($stale as $order) {
            AdminAlert::raise('authorization.expiring', sprintf(
                'Order %d has held %s since %s and has never been captured.',
                (int) $order['id'],
                $this->dollars((int) ($order['authorized_cents'] ?? 0)),
                (string) $order['placed_at']
            ), [
                'order_id' => (int) $order['id'],
                'status' => (string) $order['status'],
                'authorized_cents' => (int) ($order['authorized_cents'] ?? 0),
                'placed_at' => (string) $order['placed_at'],
                'payment_intent' => (string) $order['stripe_payment_intent_id'],
            ]);
        }
    }

    private function dollars(int $cents): string
    {
        return Money::usd($cents);
    }
}
