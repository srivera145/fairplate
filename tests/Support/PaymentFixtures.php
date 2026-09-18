<?php

declare(strict_types=1);

namespace Tests\Support;

use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\Restaurant;
use Keel\App\Services\OrderLifecycle;
use Keel\Core\Database;

/**
 * A whole order, from a hold on a card to food on a doorstep.
 *
 * Phase 6 is about what happens to money at the end of a run, and none of it
 * can be asserted from a fabricated order row: the capture reads the final
 * breakdown, the transfers read the capture, and the guarantee check reads what
 * a driver was actually offered. So this drives the real lifecycle — accept,
 * dispatch, offer, accept, arrive, pick up, deliver — and lets each step write
 * what the next one reads.
 *
 * The only shortcuts are the ones a test cannot avoid: the authorization is put
 * on the order directly rather than through a Stripe redirect, and the clock is
 * moved backwards rather than waited through.
 */
trait PaymentFixtures
{
    use DriverFixtures;

    protected const RESTAURANT_ACCOUNT = 'acct_restaurant_fake';
    protected const DRIVER_ACCOUNT = 'acct_driver_fake';

    /**
     * A restaurant that can be paid, a driver who can be paid, and an order the
     * two of them are about to move between.
     *
     * @return array{
     *     order_id: int,
     *     restaurant_id: int,
     *     driver_id: int,
     *     customer_id: int,
     *     payment_intent_id: string,
     *     authorized_cents: int
     * }
     */
    protected function createPayableOrder(
        float $miles = 3.0,
        int $tipCents = 200,
        array $restaurantOverrides = []
    ): array {
        $zoneId = $this->createZone();
        $created = $this->createRestaurantWithOwner('Taqueria Uno', $zoneId, array_merge([
            'hours' => $this->alwaysOpenHours(),
            'stripe_account_id' => self::RESTAURANT_ACCOUNT,
        ], $restaurantOverrides));

        $restaurantId = $created['restaurant_id'];
        $driverId = $this->createDispatchableDriver('Dana Ruiz', null, null, [
            'stripe_account_id' => self::DRIVER_ACCOUNT,
        ]);

        $priced = $this->createPricedOrder($restaurantId, $miles, $tipCents);
        $orderId = $priced['order_id'];

        // The hold, as the authorization webhook would have written it.
        $paymentIntentId = 'pi_fake_order_' . $orderId;
        Order::update($orderId, ['stripe_payment_intent_id' => $paymentIntentId]);
        $this->stripeHold($paymentIntentId, $priced['authorization']->total());

        return [
            'order_id' => $orderId,
            'restaurant_id' => $restaurantId,
            'driver_id' => $driverId,
            'customer_id' => $priced['customer_id'],
            'payment_intent_id' => $paymentIntentId,
            'authorized_cents' => $priced['authorization']->total(),
        ];
    }

    /**
     * Puts an authorized intent into the fake Stripe client, with a saved card
     * on it so a tip raise has something to charge.
     */
    protected function stripeHold(string $paymentIntentId, int $amountCents): void
    {
        $this->stripe->intentRows[$paymentIntentId] = [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'status' => 'requires_capture',
            'amount' => $amountCents,
            'amount_capturable' => $amountCents,
            'capture_method' => 'manual',
            'currency' => 'usd',
            'customer' => 'cus_fake_customer',
            'payment_method' => 'pm_fake_card',
        ];
    }

    /**
     * Runs the order from placed to picked_up through the real lifecycle.
     *
     * @return array{offer_id: int, wait_minutes: int}
     */
    protected function runToPickup(array $context, int $waitMinutes = 0): array
    {
        $orderId = $context['order_id'];

        OrderLifecycle::accept($orderId, 15);
        $this->drainQueue();

        $offer = DispatchOffer::pendingForOrder($orderId);

        if ($offer === null) {
            throw new \RuntimeException('Dispatch made no offer for order ' . $orderId . '.');
        }

        (new \Keel\App\Services\Dispatch\DispatchService())
            ->accept((int) $offer['id'], $context['driver_id']);

        OrderLifecycle::markReady($orderId);
        OrderLifecycle::arriveAtRestaurant($orderId);

        if ($waitMinutes > 0) {
            // The wait clock is the gap between two stored timestamps, so the
            // only honest way to make it say eleven minutes is to move one.
            $this->backdate($orderId, 'arrived_at_restaurant_at', $waitMinutes * 60);
        }

        OrderLifecycle::pickUp($orderId);

        return ['offer_id' => (int) $offer['id'], 'wait_minutes' => $waitMinutes];
    }

    /**
     * The whole run, ending with the capture and both transfers done.
     *
     * @return array<string, mixed> the context, with the order as it now stands
     */
    protected function runToDelivered(array $context, int $waitMinutes = 0): array
    {
        $this->runToPickup($context, $waitMinutes);
        OrderLifecycle::deliver($context['order_id']);
        $this->drainQueue();

        return $context + ['order' => Order::find($context['order_id'])];
    }

    /**
     * Moves an order's delivery into the past, for the tip window.
     */
    protected function backdateDelivery(int $orderId, int $hours): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE orders SET delivered_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR) WHERE id = ?'
        );
        $statement->execute([$hours, $orderId]);
    }

    protected function setPayoutsEnabled(string $kind, int $id, bool $enabled): void
    {
        $model = $kind === 'restaurant' ? Restaurant::class : Driver::class;
        $model::update($id, ['payouts_enabled' => $enabled ? 1 : 0]);
    }
}
