<?php

namespace Keel\App\Controllers\App;

use Keel\App\Models\Order;
use Keel\App\Services\Payments\PaymentException;
use Keel\App\Services\Payments\PaymentService;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Pricing\PricingException;
use Keel\Core\Request;

/**
 * Raising the tip after the food has arrived.
 *
 * The one place in the customer app that charges a card with nobody about to
 * type a number into Stripe's form, which is the whole reason it is its own
 * controller rather than another method on the orders screen.
 *
 * Three things are true here and each is enforced somewhere it cannot be
 * skipped:
 *
 *   The order is theirs. Loaded by both ids, like every other /app route that
 *   carries one, so there is no path that reads an order and then decides.
 *
 *   The window is open. PaymentService owns that rule and checks it again when
 *   it charges, so the form disappearing and the charge being refused are the
 *   same rule read twice rather than two rules that could drift.
 *
 *   The amount is a raise, not a correction. Tips go whole to the driver and
 *   the driver has already been paid the first one; lowering a tip afterwards
 *   would mean taking money back off somebody who has finished the job, which
 *   the spec does not ask for and this does not offer.
 */
class TipController extends CustomerController
{
    /** The most a tip may be raised by in one go, as a guard rather than a rule. */
    public const MAX_DELTA_CENTS = 20000;

    public function store(Request $request, string $id): never
    {
        $orderId = (int) $id;
        $order = Order::forCustomerAndId($this->userId(), $orderId);

        if ($order === null) {
            $this->notFound();
        }

        $back = '/app/orders/' . $orderId;
        $payments = new PaymentService();

        if (!$payments->tipWindow($order)['open']) {
            $this->back($back, 'That order is past the window for raising the tip.', 'bad');
        }

        $deltaCents = $this->deltaCents((string) ($_POST['amount'] ?? ''));

        if ($deltaCents === null) {
            $this->back($back, 'Enter how much to add, like 3.00.', 'bad');
        }

        if ($deltaCents > self::MAX_DELTA_CENTS) {
            $this->back($back, 'That is more than we can add in one go. Try a smaller amount.', 'bad');
        }

        try {
            $adjustment = $payments->chargeTipAdjustment($order, $deltaCents);
        } catch (PaymentException $exception) {
            $this->back($back, $exception->getMessage(), 'bad');
        }

        $this->back($back, sprintf(
            'Thank you — %s more goes to your driver.',
            Money::usd((int) $adjustment['delta_cents'])
        ));
    }

    /**
     * The typed amount as cents, or null if it was not one.
     *
     * Dollars in, because that is what the field asks for, and through Money so
     * there is exactly one answer to what "3.5" means. Zero is not an amount to
     * add and neither is a negative: both come back as null and the customer is
     * told what the field wants rather than being charged nothing.
     */
    private function deltaCents(string $amount): ?int
    {
        try {
            $cents = Money::fromDollars($amount);
        } catch (PricingException $exception) {
            return null;
        }

        return $cents > 0 ? $cents : null;
    }
}
