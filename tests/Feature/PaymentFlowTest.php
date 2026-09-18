<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Jobs\AuthorizationExpiryCheckJob;
use Keel\App\Jobs\TransferPayoutJob;
use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Models\Payout;
use Keel\App\Models\Refund;
use Keel\App\Models\TipAdjustment;
use Keel\App\Models\User;
use Keel\App\Services\OrderLifecycle;
use Keel\App\Services\Payments\PaymentException;
use Keel\App\Services\Payments\PaymentService;
use Keel\App\Services\Payments\PayoutService;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Settings;
use Keel\Core\Database;
use Tests\Support\FakeStripePayments;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * The money, end to end.
 *
 * Every test here drives the real lifecycle — the kitchen accepts, dispatch
 * offers, a driver accepts and drives, the order is delivered — because that is
 * the only way the numbers being asserted are the numbers the application
 * actually produced. A hand-written breakdown row would make the arithmetic
 * true by construction, which is exactly what these tests exist not to do.
 *
 * Stripe is a recording double rather than the network. It reproduces the three
 * behaviours the code depends on and would otherwise be assumed: a capture
 * above the authorization is refused, a balance transaction carries the real
 * fee, and a repeated idempotency key returns the first answer instead of doing
 * the work twice. The last one is what makes "a retried job creates no
 * duplicate transfers" a real assertion.
 *
 * The one identity everything else hangs off:
 *
 *     restaurant + driver + platform fee + service fee = the captured total
 *
 * It is asserted directly, and it is also why the individual amounts can be
 * asserted against the spec's formulas without the two ever disagreeing.
 */
class PaymentFlowTest extends TestCase
{
    use PaymentFixtures;

    private FakeStripePayments $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDispatchSettings();
        $this->stripe = $this->fakeStripe();
        $this->fakeRouting(3.0);
    }

    protected function tearDown(): void
    {
        $this->restoreCollaborators();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Capture
    // -----------------------------------------------------------------

    public function testDeliveringAnOrderCapturesTheFinalTotalAndRecordsTheStripeFee(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $order = Order::find($context['order_id']);
        $final = OrderPriceBreakdown::forStage($context['order_id'], OrderPriceBreakdown::STAGE_FINAL);

        self::assertNotNull($final, 'Delivery prices the order one last time.');
        self::assertSame(
            (int) $final['total_cents'],
            (int) $order['captured_cents'],
            'The capture is the final breakdown\'s total, to the cent.'
        );
        self::assertLessThanOrEqual(
            (int) $order['authorized_cents'],
            (int) $order['captured_cents'],
            'A capture can never exceed its hold.'
        );

        $captured = $this->stripe->lastParamsFor('paymentIntents', 'capture');

        self::assertNotNull($captured);
        self::assertSame($context['payment_intent_id'], $captured['id']);
        self::assertSame((int) $final['total_cents'], $captured['amount_to_capture']);

        self::assertNotNull($order['stripe_charge_id'], 'The charge is kept: transfers and refunds need it.');
        self::assertSame(
            $this->stripe->feeFor((int) $order['captured_cents']),
            (int) $order['stripe_fee_cents'],
            'The fee is read back from the balance transaction, not estimated.'
        );
    }

    public function testACaptureThatWouldExceedTheAuthorizationIsRefused(): void
    {
        $context = $this->createPayableOrder();
        $this->runToPickup($context);

        OrderLifecycle::deliver($context['order_id']);

        // The final breakdown, rewritten to more than the hold. Nothing in the
        // application does this; the point is that if anything ever did, the
        // capture stops rather than taking a number nobody agreed to.
        $order = Order::find($context['order_id']);
        $final = OrderPriceBreakdown::forStage($context['order_id'], OrderPriceBreakdown::STAGE_FINAL);
        OrderPriceBreakdown::update((int) $final['id'], [
            'total_cents' => (int) $order['authorized_cents'] + 1,
        ]);
        Order::update($context['order_id'], ['captured_cents' => null, 'stripe_charge_id' => null]);

        $this->expectException(PaymentException::class);

        (new PaymentService())->capture(Order::find($context['order_id']));
    }

    public function testTheActualStripeFeeStaysUnderTheServiceFeeOnTenOrders(): void
    {
        // Ten different shapes of order — distance, tip, wait — because the
        // gross-up has to cover the card on all of them, not on an average.
        for ($i = 0; $i < 10; $i++) {
            $this->truncateBetweenOrders();

            $miles = 0.5 + ($i * 0.9);
            $this->fakeRouting($miles);

            $context = $this->createPayableOrder($miles, 100 + ($i * 175));
            $this->runToDelivered($context, $i % 3 === 0 ? 14 : 0);

            $order = Order::find($context['order_id']);
            $final = OrderPriceBreakdown::forStage($context['order_id'], OrderPriceBreakdown::STAGE_FINAL);

            self::assertLessThanOrEqual(
                (int) $final['service_fee_cents'],
                (int) $order['stripe_fee_cents'],
                sprintf(
                    'Order %d: Stripe took %d against a service fee of %d. No order may cost FairPlate money.',
                    $i,
                    (int) $order['stripe_fee_cents'],
                    (int) $final['service_fee_cents']
                )
            );
        }
    }

    // -----------------------------------------------------------------
    // Transfers
    // -----------------------------------------------------------------

    public function testTheTwoTransfersAreTheSpecsSharesAndTheyAddUpToTheCapture(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context, 14);

        $orderId = $context['order_id'];
        $order = Order::find($orderId);
        $final = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_FINAL);

        $restaurant = Payout::forOrderAndType($orderId, Payout::TYPE_RESTAURANT);
        $driver = Payout::forOrderAndType($orderId, Payout::TYPE_DRIVER);

        self::assertNotNull($restaurant);
        self::assertNotNull($driver);
        self::assertSame(Payout::STATUS_PAID, (string) $restaurant['status']);
        self::assertSame(Payout::STATUS_PAID, (string) $driver['status']);

        self::assertSame(
            (int) $final['subtotal_cents'] + (int) $final['tax_cents'],
            (int) $restaurant['amount_cents'],
            'The restaurant gets subtotal + tax, in full. Nothing is deducted.'
        );

        self::assertSame(
            (int) $final['driver_guaranteed_cents']
                + (int) $final['wait_pay_cents']
                + (int) $final['tip_cents'],
            (int) $driver['amount_cents'],
            'The driver gets the guarantee, the wait pay and the whole tip.'
        );

        self::assertGreaterThan(0, (int) $final['wait_pay_cents'], 'A fourteen-minute wait is paid for.');

        $platformKept = (int) $final['platform_fee_cents'] + (int) $final['service_fee_cents'];

        self::assertSame(
            (int) $order['captured_cents'],
            (int) $restaurant['amount_cents'] + (int) $driver['amount_cents'] + $platformKept,
            'restaurant + driver + platform fee + service fee = the captured amount, to the cent.'
        );
    }

    public function testEachTransferNamesTheOrdersChargeAndTransferGroup(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $order = Order::find($context['order_id']);
        $transfers = array_values(array_filter(
            $this->stripe->calls,
            static fn (array $call): bool => $call['service'] === 'transfers' && $call['method'] === 'create'
        ));

        self::assertCount(2, $transfers);

        foreach ($transfers as $call) {
            self::assertSame(
                TransferPayoutJob::transferGroup((int) $order['id']),
                $call['params']['transfer_group'],
                'The capture and both transfers are one group in the dashboard.'
            );
            self::assertSame(
                (string) $order['stripe_charge_id'],
                $call['params']['source_transaction'],
                'A transfer comes out of this order\'s own charge, not the platform float.'
            );
        }

        $destinations = array_map(
            static fn (array $call): string => (string) $call['params']['destination'],
            $transfers
        );

        self::assertContains(self::RESTAURANT_ACCOUNT, $destinations);
        self::assertContains(self::DRIVER_ACCOUNT, $destinations);
    }

    public function testARetriedPayoutJobCreatesNoSecondTransfer(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $payouts = Payout::forOrder($context['order_id']);

        self::assertCount(2, $payouts);

        $before = count($this->stripe->transferRows);

        // The worker releasing a job it already ran, twice over.
        foreach ($payouts as $payout) {
            (new TransferPayoutJob())->handle(['payout_id' => (int) $payout['id']]);
            (new TransferPayoutJob())->handle(['payout_id' => (int) $payout['id']]);
        }

        self::assertSame($before, count($this->stripe->transferRows), 'No second transfer was made.');
        self::assertCount(2, Payout::forOrder($context['order_id']), 'And no second payout row.');
    }

    public function testQueueingTheSameOrdersPayoutsTwiceLeavesTwoRows(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        // What a replayed delivered event, or a retried capture, would do.
        (new PayoutService())->queueForOrder(Order::find($context['order_id']));
        $this->drainQueue();

        $payouts = Payout::forOrder($context['order_id']);

        self::assertCount(2, $payouts);
        self::assertCount(2, $this->stripe->transferRows);
    }

    public function testAPayoutRetriesWithBackoffAndSucceedsOnTheThirdAttempt(): void
    {
        $context = $this->createPayableOrder();
        $this->runToPickup($context);

        // Stripe is unreachable for the first two transfer attempts.
        $this->stripe->failTimes['transfers'] = 2;

        OrderLifecycle::deliver($context['order_id']);
        $this->drainQueue();

        $restaurant = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);

        self::assertSame(Payout::STATUS_PENDING, (string) $restaurant['status'], 'It is not given up on.');
        self::assertNotNull($restaurant['last_error']);

        $queued = $this->queuedJobs(TransferPayoutJob::class);

        self::assertNotSame([], $queued, 'A failed attempt puts itself back.');
        self::assertGreaterThan(
            // The queue stamps available_at with the database's clock, so the
            // comparison has to come from there too.
            (string) Database::connection()->query('SELECT NOW()')->fetchColumn(),
            (string) $queued[0]['available_at'],
            'And waits before trying again.'
        );

        // The clock moves on and the worker comes back.
        $this->releaseQueuedJobs();
        $this->drainQueue();

        $restaurant = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);

        self::assertSame(Payout::STATUS_PAID, (string) $restaurant['status']);
        self::assertNotNull($restaurant['stripe_transfer_id']);
    }

    public function testAPayoutThatNeverSucceedsFailsAfterFiveAttemptsAndAlertsAnAdmin(): void
    {
        $this->createUser(['role' => User::ROLE_ADMIN, 'email' => 'ops@fairplate.test']);

        $context = $this->createPayableOrder();
        $this->runToPickup($context);

        $this->stripe->failTimes['transfers'] = 100;

        OrderLifecycle::deliver($context['order_id']);

        for ($attempt = 0; $attempt < TransferPayoutJob::MAX_ATTEMPTS + 1; $attempt++) {
            $this->releaseQueuedJobs();
            $this->drainQueue();
        }

        $restaurant = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);

        self::assertSame(Payout::STATUS_FAILED, (string) $restaurant['status']);
        self::assertSame(TransferPayoutJob::MAX_ATTEMPTS, (int) $restaurant['attempts']);
        self::assertStringContainsString('payout.failed', $this->activityActions());
        self::assertStringContainsString('ops@fairplate.test', $this->latestMailLog());
    }

    public function testAPayoutToAnAccountWithPayoutsDisabledIsHeldRatherThanDropped(): void
    {
        $this->createUser(['role' => User::ROLE_ADMIN, 'email' => 'ops@fairplate.test']);

        $context = $this->createPayableOrder();
        $this->runToPickup($context);
        $this->setPayoutsEnabled('restaurant', $context['restaurant_id'], false);

        OrderLifecycle::deliver($context['order_id']);
        $this->drainQueue();

        $restaurant = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);
        $driver = Payout::forOrderAndType($context['order_id'], Payout::TYPE_DRIVER);

        self::assertSame(Payout::STATUS_HELD, (string) $restaurant['status'], 'Held, never dropped.');
        self::assertSame(
            (int) $restaurant['amount_cents'],
            (int) $restaurant['amount_cents'],
            'The amount owed is still on the row.'
        );
        self::assertSame(
            Payout::STATUS_PAID,
            (string) $driver['status'],
            'The driver is paid regardless: the two recipients fail independently.'
        );
        self::assertStringContainsString('payout.held', $this->activityActions());
        self::assertStringContainsString('ops@fairplate.test', $this->latestMailLog());
    }

    public function testAPayoutToARecipientWithNoConnectedAccountIsHeld(): void
    {
        $context = $this->createPayableOrder(3.0, 200, ['stripe_account_id' => null]);
        $this->runToDelivered($context);

        $restaurant = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);

        self::assertSame(Payout::STATUS_HELD, (string) $restaurant['status']);
        self::assertStringContainsString('no connected Stripe account', (string) $restaurant['last_error']);
    }

    public function testThePayoutRefusesToPayADriverLessThanTheOfferCardPromised(): void
    {
        $context = $this->createPayableOrder();
        $this->runToPickup($context);

        // The promise a driver accepted, moved after the fact.
        $offer = DispatchOffer::acceptedForOrder($context['order_id']);
        DispatchOffer::update((int) $offer['id'], [
            'guaranteed_cents' => (int) $offer['guaranteed_cents'] + 100,
        ]);

        OrderLifecycle::deliver($context['order_id']);
        $this->drainQueue();

        self::assertSame(
            [],
            Payout::forOrder($context['order_id']),
            'Nobody is paid off a breakdown that disagrees with what a driver was promised.'
        );
        self::assertStringContainsString('payout.guarantee_mismatch', $this->activityActions());
    }

    // -----------------------------------------------------------------
    // Release and refunds
    // -----------------------------------------------------------------

    public function testRejectingBeforePickupCancelsTheHoldAndTransfersNothing(): void
    {
        // A kitchen turns an order down while it is still placed; the rule book
        // does not offer rejection after that.
        $context = $this->createPayableOrder();
        OrderLifecycle::reject($context['order_id'], 'too_busy');
        $this->drainQueue();

        $order = Order::find($context['order_id']);

        self::assertSame(Order::STATUS_REJECTED, (string) $order['status']);
        self::assertNull($order['captured_cents'], 'Nobody was charged.');
        self::assertContains('paymentIntents.cancel', $this->stripe->calledMethods());
        self::assertSame(
            'canceled',
            (string) $this->stripe->intent($context['payment_intent_id'])['status']
        );
        self::assertSame([], Payout::forOrder($context['order_id']), 'And no transfers exist.');
        self::assertSame([], $this->stripe->transferRows);
    }

    public function testCancellingBeforePickupReleasesTheHoldTheSameWay(): void
    {
        $context = $this->createPayableOrder();
        OrderLifecycle::accept($context['order_id'], 15);
        $this->drainQueue();

        OrderLifecycle::cancel($context['order_id'], 'Customer changed their mind.');

        $order = Order::find($context['order_id']);

        self::assertSame(Order::STATUS_CANCELLED, (string) $order['status']);
        self::assertNull($order['captured_cents']);
        self::assertContains('paymentIntents.cancel', $this->stripe->calledMethods());
        self::assertSame([], Payout::forOrder($context['order_id']));
    }

    public function testARefundAfterDeliveryLeavesTheDriversTransferStanding(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $order = Order::find($context['order_id']);
        $driverBefore = Payout::forOrderAndType($context['order_id'], Payout::TYPE_DRIVER);

        (new PaymentService())->refund($order, 500, 'Cold food', false);

        $driverAfter = Payout::forOrderAndType($context['order_id'], Payout::TYPE_DRIVER);
        $restaurant = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);
        $refund = Refund::forOrder($context['order_id'])[0];

        self::assertSame(500, (int) $refund['amount_cents']);
        self::assertSame(0, (int) $refund['restaurant_error']);
        self::assertSame(0, (int) $refund['reversed_restaurant_cents'], 'The platform absorbs it.');
        self::assertSame(0, (int) $restaurant['reversed_cents'], 'The kitchen keeps its money.');
        self::assertSame(
            (int) $driverBefore['amount_cents'],
            (int) $driverAfter['amount_cents'] - (int) $driverAfter['reversed_cents'],
            'The driver drove, so the driver is paid.'
        );
        self::assertSame(
            (int) $order['stripe_fee_cents'],
            (int) $refund['processing_absorbed_cents'],
            'Stripe keeps the original fee and the platform writes it off.'
        );
    }

    public function testARestaurantErrorRefundReversesOnlyTheRestaurantsPortion(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $order = Order::find($context['order_id']);
        $restaurantBefore = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);

        $refund = (new PaymentService())->refund($order, 400, 'Missing an item', true);

        $restaurantAfter = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);
        $driver = Payout::forOrderAndType($context['order_id'], Payout::TYPE_DRIVER);

        self::assertSame(1, (int) $refund['restaurant_error']);
        self::assertSame(400, (int) $refund['reversed_restaurant_cents']);
        self::assertNotNull($refund['stripe_transfer_reversal_id']);
        self::assertSame(400, (int) $restaurantAfter['reversed_cents']);
        self::assertSame(0, (int) $driver['reversed_cents'], 'Only the restaurant\'s portion comes back.');

        $reversal = $this->stripe->lastParamsFor('transfers', 'createReversal');

        self::assertSame((string) $restaurantBefore['stripe_transfer_id'], $reversal['transfer']);
        self::assertSame(400, $reversal['amount']);
    }

    public function testARefundCannotExceedWhatWasCharged(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $order = Order::find($context['order_id']);

        $this->expectException(PaymentException::class);

        (new PaymentService())->refund($order, (int) $order['captured_cents'] + 1, 'Too much', false);
    }

    // -----------------------------------------------------------------
    // Tip adjustment
    // -----------------------------------------------------------------

    public function testRaisingTheTipChargesTheGrossedUpAmountAndPaysTheDriverTheWholeDelta(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $this->actingAsOwner($context['customer_id']);

        $response = $this->post('/app/orders/' . $context['order_id'] . '/tip', [
            'amount' => '3.00',
            '_csrf' => $this->csrfToken(),
        ]);

        self::assertSame(302, $response->status);

        $adjustment = TipAdjustment::forOrder($context['order_id'])[0];

        self::assertSame(TipAdjustment::STATUS_SUCCEEDED, (string) $adjustment['status']);
        self::assertSame(300, (int) $adjustment['delta_cents']);

        // ceil((300 + 30) / (1 − 0.029)) = 340.
        self::assertSame(340, (int) $adjustment['charge_cents'], 'The delta is grossed up on its own.');
        self::assertSame(40, (int) $adjustment['service_fee_cents']);

        $charge = null;

        foreach ($this->stripe->calls as $call) {
            if ($call['service'] === 'paymentIntents'
                && $call['method'] === 'create'
                && ($call['params']['metadata']['kind'] ?? '') === 'tip_adjustment') {
                $charge = $call['params'];
            }
        }

        self::assertNotNull($charge, 'The raise is a separate charge, not a change to the first one.');
        self::assertSame(340, $charge['amount']);
        self::assertTrue($charge['off_session']);
        self::assertTrue($charge['confirm']);
        self::assertSame('pm_fake_card', $charge['payment_method']);

        $this->drainQueue();

        $payout = Payout::forOrderAndType($context['order_id'], Payout::TYPE_DRIVER_TIP_ADJUST);

        self::assertNotNull($payout);
        self::assertSame(300, (int) $payout['amount_cents'], 'The driver gets the whole 300.');
        self::assertSame(Payout::STATUS_PAID, (string) $payout['status']);
        self::assertSame(self::DRIVER_ACCOUNT, (string) $payout['recipient_account']);
    }

    public function testATipRaiseOutsideTheWindowIsRefused(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);
        $this->backdateDelivery($context['order_id'], Settings::int('tip_adjust_window_hours') + 1);

        $order = Order::find($context['order_id']);

        $this->expectException(PaymentException::class);

        (new PaymentService())->chargeTipAdjustment($order, 300);
    }

    public function testTheReceiptShowsTheFinalBreakdownTheTipRaiseAndTheRefund(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $order = Order::find($context['order_id']);
        $payments = new PaymentService();
        $payments->chargeTipAdjustment($order, 300);
        $payments->refund(Order::find($context['order_id']), 250, 'Missing a side', false);

        $this->actingAsOwner($context['customer_id']);
        $response = $this->get('/app/orders/' . $context['order_id']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Receipt', $response->body);
        self::assertStringContainsString('Extra tip', $response->body);
        self::assertStringContainsString('Refunds', $response->body);
        self::assertStringContainsString('Missing a side', $response->body);
        self::assertStringContainsString('Service fee', $response->body);
        self::assertStringContainsString('Charged separately', $response->body);

        $captured = Money::usd((int) Order::find($context['order_id'])['captured_cents']);

        self::assertStringContainsString($captured, $response->body, 'The receipt shows what was charged.');
    }

    // -----------------------------------------------------------------
    // The admin flows
    // -----------------------------------------------------------------

    public function testAnAdminCanRefundAnOrderAndTheDefaultAbsorbsIt(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $this->actingAsRole(User::ROLE_ADMIN);

        $response = $this->post('/admin/orders/' . $context['order_id'] . '/refund', [
            'amount' => '4.00',
            'reason' => 'Late by an hour',
            '_csrf' => $this->csrfToken(),
        ]);

        self::assertSame(302, $response->status);

        $refund = Refund::forOrder($context['order_id'])[0];
        $restaurant = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);

        self::assertSame(400, (int) $refund['amount_cents']);
        self::assertSame(0, (int) $refund['restaurant_error'], 'Absorbing is the default.');
        self::assertSame(0, (int) $restaurant['reversed_cents'], 'The kitchen is untouched.');
    }

    public function testAnAdminMarkingARefundARestaurantErrorReversesTheirPortion(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $this->actingAsRole(User::ROLE_ADMIN);

        $this->post('/admin/orders/' . $context['order_id'] . '/refund', [
            'amount' => '4.00',
            'reason' => 'Wrong order entirely',
            'restaurant_error' => '1',
            '_csrf' => $this->csrfToken(),
        ]);

        $refund = Refund::forOrder($context['order_id'])[0];

        self::assertSame(1, (int) $refund['restaurant_error']);
        self::assertSame(400, (int) $refund['reversed_restaurant_cents']);
        self::assertSame(
            400,
            (int) Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT)['reversed_cents']
        );
    }

    public function testAnAdminCanSendAHeldPayoutAgainOnceTheAccountIsConnected(): void
    {
        $context = $this->createPayableOrder(3.0, 200, ['stripe_account_id' => null]);
        $this->runToDelivered($context);

        $held = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);

        self::assertSame(Payout::STATUS_HELD, (string) $held['status']);

        // The kitchen finishes onboarding.
        \Keel\App\Models\Restaurant::update($context['restaurant_id'], [
            'stripe_account_id' => self::RESTAURANT_ACCOUNT,
        ]);

        $this->actingAsRole(User::ROLE_ADMIN);
        $response = $this->post('/admin/payouts/' . (int) $held['id'] . '/retry', [
            '_csrf' => $this->csrfToken(),
        ]);

        self::assertSame(302, $response->status);
        $this->drainQueue();

        $paid = Payout::find((int) $held['id']);

        self::assertSame(Payout::STATUS_PAID, (string) $paid['status']);
        self::assertSame(self::RESTAURANT_ACCOUNT, (string) $paid['recipient_account']);
        self::assertSame((int) $held['amount_cents'], (int) $paid['amount_cents'], 'The amount never changed.');
    }

    // -----------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------

    public function testACancelledPaymentIntentCancelsTheOrderBehindIt(): void
    {
        $context = $this->createPayableOrder();
        OrderLifecycle::accept($context['order_id'], 15);
        $this->drainQueue();

        $response = $this->sendEvent('payment_intent.canceled', 'evt_pi_cancel', [
            'id' => $context['payment_intent_id'],
            'object' => 'payment_intent',
            'status' => 'canceled',
        ]);

        self::assertSame(200, $response->status);
        self::assertSame(Order::STATUS_CANCELLED, (string) Order::find($context['order_id'])['status']);
    }

    public function testARefundMadeInStripeIsRecordedAgainstTheOrder(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $order = Order::find($context['order_id']);
        $chargeId = (string) $order['stripe_charge_id'];

        $response = $this->sendEvent('charge.refunded', 'evt_charge_refunded', [
            'id' => $chargeId,
            'object' => 'charge',
            'amount' => (int) $order['captured_cents'],
            'amount_refunded' => 600,
            'refunds' => [
                'object' => 'list',
                'data' => [[
                    'id' => 're_from_dashboard',
                    'object' => 'refund',
                    'amount' => 600,
                    'reason' => 'requested_by_customer',
                ]],
            ],
        ]);

        self::assertSame(200, $response->status);

        $refunds = Refund::forOrder($context['order_id']);

        self::assertCount(1, $refunds);
        self::assertSame(600, (int) $refunds[0]['amount_cents']);
        self::assertSame('re_from_dashboard', (string) $refunds[0]['stripe_refund_id']);
        self::assertSame(0, (int) $refunds[0]['restaurant_error'], 'A dashboard refund is never a restaurant error.');
    }

    public function testARefundWebhookForARefundWeAlreadyMadeIsNotRecordedTwice(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $order = Order::find($context['order_id']);
        $refund = (new PaymentService())->refund($order, 500, 'Cold food', false);
        $chargeId = (string) $order['stripe_charge_id'];

        $this->sendEvent('charge.refunded', 'evt_charge_refunded_dup', $this->stripe->chargeRow($chargeId));

        self::assertCount(1, Refund::forOrder($context['order_id']));
        self::assertSame(
            (string) $refund['stripe_refund_id'],
            (string) Refund::forOrder($context['order_id'])[0]['stripe_refund_id']
        );
    }

    public function testAnAccountWithPayoutsDisabledIsFlaggedAndItsHeldPayoutsComeBackWhenItIsEnabled(): void
    {
        $this->createUser(['role' => User::ROLE_ADMIN, 'email' => 'ops@fairplate.test']);

        $context = $this->createPayableOrder();
        $this->runToPickup($context);

        $disabled = $this->sendEvent('account.updated', 'evt_account_off', [
            'id' => self::RESTAURANT_ACCOUNT,
            'object' => 'account',
            'payouts_enabled' => false,
            'requirements' => ['currently_due' => ['external_account']],
        ]);

        self::assertSame(200, $disabled->status);
        self::assertSame(
            0,
            (int) \Keel\App\Models\Restaurant::find($context['restaurant_id'])['payouts_enabled']
        );

        OrderLifecycle::deliver($context['order_id']);
        $this->drainQueue();

        $held = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);

        self::assertSame(Payout::STATUS_HELD, (string) $held['status']);

        $enabled = $this->sendEvent('account.updated', 'evt_account_on', [
            'id' => self::RESTAURANT_ACCOUNT,
            'object' => 'account',
            'payouts_enabled' => true,
            'requirements' => ['currently_due' => []],
        ]);

        self::assertSame(200, $enabled->status);
        $this->drainQueue();

        $paid = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);

        self::assertSame(Payout::STATUS_PAID, (string) $paid['status'], 'Held work is never lost.');
        self::assertNotNull($paid['stripe_transfer_id']);
    }

    public function testAReversedTransferIsRecordedOnThePayout(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $payout = Payout::forOrderAndType($context['order_id'], Payout::TYPE_RESTAURANT);

        $response = $this->sendEvent('transfer.reversed', 'evt_transfer_reversed', [
            'id' => (string) $payout['stripe_transfer_id'],
            'object' => 'transfer',
            'amount' => (int) $payout['amount_cents'],
            'amount_reversed' => 150,
        ]);

        self::assertSame(200, $response->status);
        self::assertSame(
            150,
            (int) Payout::find((int) $payout['id'])['reversed_cents']
        );
    }

    public function testAReplayedWebhookDoesNotDoTheWorkTwice(): void
    {
        $context = $this->createPayableOrder();
        $this->runToDelivered($context);

        $order = Order::find($context['order_id']);
        $charge = [
            'id' => (string) $order['stripe_charge_id'],
            'object' => 'charge',
            'amount' => (int) $order['captured_cents'],
            'amount_refunded' => 600,
            'refunds' => [
                'object' => 'list',
                'data' => [[
                    'id' => 're_replayed',
                    'object' => 'refund',
                    'amount' => 600,
                    'reason' => 'requested_by_customer',
                ]],
            ],
        ];

        $first = $this->sendEvent('charge.refunded', 'evt_replay', $charge);
        $second = $this->sendEvent('charge.refunded', 'evt_replay', $charge);

        self::assertSame(200, $first->status);
        self::assertTrue((bool) ($second->json()['duplicate'] ?? false));
        self::assertCount(1, Refund::forOrder($context['order_id']));
        self::assertCount(2, Payout::forOrder($context['order_id']), 'And no extra transfers either.');
    }

    // -----------------------------------------------------------------
    // The hourly authorization check
    // -----------------------------------------------------------------

    public function testAnAuthorizationOlderThanSixDaysWithNoCaptureAlertsAnAdmin(): void
    {
        $this->createUser(['role' => User::ROLE_ADMIN, 'email' => 'ops@fairplate.test']);

        $context = $this->createPayableOrder();
        $this->backdate($context['order_id'], 'placed_at', 7 * 86400);

        (new AuthorizationExpiryCheckJob())->handle([]);

        self::assertStringContainsString('alert.authorization.expiring', $this->activityActions());
        self::assertStringContainsString('ops@fairplate.test', $this->latestMailLog());
    }

    public function testACapturedOrderIsNotReportedAsAnExpiringAuthorization(): void
    {
        $this->createUser(['role' => User::ROLE_ADMIN, 'email' => 'ops@fairplate.test']);

        $context = $this->createPayableOrder();
        $this->runToDelivered($context);
        $this->backdate($context['order_id'], 'placed_at', 7 * 86400);

        (new AuthorizationExpiryCheckJob())->handle([]);

        self::assertSame([], Order::staleAuthorizations(AuthorizationExpiryCheckJob::WARN_AFTER_DAYS));
        self::assertStringNotContainsString('alert.authorization.expiring', $this->activityActions());
    }

    public function testARejectedOrdersReleasedHoldIsNotReportedAsExpiring(): void
    {
        $context = $this->createPayableOrder();
        OrderLifecycle::reject($context['order_id'], 'closing_soon');

        $this->backdate($context['order_id'], 'placed_at', 8 * 86400);

        self::assertSame([], Order::staleAuthorizations(AuthorizationExpiryCheckJob::WARN_AFTER_DAYS));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Makes every delayed job available now, the way the passage of time would.
     */
    private function releaseQueuedJobs(): void
    {
        Database::connection()->exec('UPDATE jobs SET available_at = NOW(), reserved_at = NULL');
    }

    /**
     * The actions written to the activity log this test, as one string.
     */
    private function activityActions(): string
    {
        $rows = Database::connection()
            ->query('SELECT action FROM activity_log ORDER BY id ASC')
            ->fetchAll(\PDO::FETCH_COLUMN);

        return implode(' ', array_map('strval', $rows));
    }

    /**
     * Clears the order tables between iterations of the ten-order fee test,
     * which is the only test here that needs more than one order.
     */
    private function truncateBetweenOrders(): void
    {
        $connection = Database::connection();
        $connection->exec('SET FOREIGN_KEY_CHECKS=0');

        foreach ([
            'jobs', 'payouts', 'refunds', 'tip_adjustments', 'dispatch_offers',
            'order_price_breakdown', 'order_items', 'orders', 'drivers',
            'restaurant_staff', 'restaurants', 'delivery_zones',
        ] as $table) {
            $connection->exec('TRUNCATE TABLE ' . $table);
        }

        $connection->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * One signed Stripe event, through the real webhook route.
     *
     * @param array<string, mixed> $object
     */
    private function sendEvent(string $type, string $eventId, array $object): \Tests\Support\TestResponse
    {
        $raw = (string) json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $raw, 'whsec_feature_test');

        return $this->postRawJson('/webhooks/stripe', $raw, [
            'Stripe-Signature' => 't=' . $timestamp . ',v1=' . $signature,
        ]);
    }
}
