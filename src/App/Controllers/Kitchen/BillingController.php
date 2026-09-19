<?php

namespace Keel\App\Controllers\Kitchen;

use Keel\App\Models\RestaurantMonthlyStatement;
use Keel\App\Services\Billing\BillingCustomer;
use Keel\App\Services\Billing\TierBillingService;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Settings;
use Keel\App\Services\StripeClientFactory;
use Keel\Core\Activity;
use Keel\Core\Request;
use Keel\Core\Response;
use Keel\Core\Session;

/**
 * What this month costs, what past months cost, and what FairPlate charges it
 * to.
 *
 * The month panel was a projection with a placeholder where the fee went. It is
 * now the same arithmetic the billing job runs on the first, against the same
 * TierBillingService, so the number a kitchen watches climb all month is the
 * number on the invoice by construction rather than by two calculations that
 * have to be kept in agreement.
 *
 * The payment form is three routes rather than one because of a rule this
 * codebase already follows: a GET must not create anything. A SetupIntent is
 * cheap, but a GET that mints one leaves an abandoned intent behind every time
 * anybody opens the page, so the POST creates it, the session carries the
 * client secret, and the GET renders a form from what is already there. Stripe
 * returns the browser to the third, which re-reads the intent rather than
 * believing the URL it arrived on.
 */
class BillingController extends KitchenController
{
    /** Where the pending SetupIntent waits between the POST and the form. */
    private const SETUP_KEY = '_kitchen_billing_setup';

    /**
     * This month, live, plus the last few statements and the card on file.
     */
    public function index(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];

        $billing = TierBillingService::fromTables();
        $period = TierBillingService::currentPeriod();

        $statements = RestaurantMonthlyStatement::forRestaurant($restaurantId);

        $this->view('kitchen.billing', array_merge(
            $this->shell($restaurant, 'billing', 'Billing'),
            [
                'panel' => $billing->statementFor($restaurant, $period),
                'tiers' => $billing->tiers(),
                'recentStatements' => array_slice($statements, 0, 3),
                'statementCount' => count($statements),
                'paymentMethod' => (new BillingCustomer())->paymentMethodSummary($restaurant),
                'stripeConfigured' => StripeClientFactory::publishableKey() !== '',
            ]
        ));
    }

    /**
     * Every month this restaurant has been billed for.
     */
    public function statements(Request $request): void
    {
        $restaurant = $this->requireRestaurant();

        $this->view('kitchen.statements', array_merge(
            $this->shell($restaurant, 'billing', 'Statements'),
            [
                'statements' => RestaurantMonthlyStatement::forRestaurant((int) $restaurant['id']),
                // The comparison column names a rate, and the rate is a setting
                // an admin can move. Reading it here keeps the heading and the
                // arithmetic behind it describing the same number.
                'comparisonPct' => Money::percentFromRate(Settings::decimal('comparison_commission_pct')),
            ]
        ));
    }

    /**
     * Starts collecting a card or bank account.
     *
     * A POST because it creates the Stripe customer the first time it is
     * called, and a GET that creates things is a GET a browser will helpfully
     * repeat.
     */
    public function startPaymentMethod(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];
        $this->authorizeRestaurant($restaurantId);

        try {
            $intent = (new BillingCustomer())->setupIntentFor($restaurant);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Billing SetupIntent failed: ' . $exception->getMessage());

            $this->back('/kitchen/billing', 'We could not open the payment form. Try again in a moment.', 'bad');
        }

        Session::put(self::SETUP_KEY, [
            'restaurant_id' => $restaurantId,
            'client_secret' => (string) $intent->client_secret,
        ]);

        Response::redirect('/kitchen/billing/method');
    }

    /**
     * The payment form itself, rendered from the intent the POST created.
     *
     * Refreshable, because the client secret is in the session rather than
     * being minted by this request. Arriving here without one — a bookmark, a
     * back button after finishing — goes back to the billing page rather than
     * quietly creating a second intent.
     */
    public function paymentMethodForm(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $setup = Session::get(self::SETUP_KEY);

        if (!is_array($setup) || (int) ($setup['restaurant_id'] ?? 0) !== (int) $restaurant['id']) {
            Response::redirect('/kitchen/billing');
        }

        $this->view('kitchen.billing-method', array_merge(
            $this->shell($restaurant, 'billing', 'Payment method'),
            [
                'clientSecret' => (string) $setup['client_secret'],
                'publishableKey' => StripeClientFactory::publishableKey(),
            ]
        ));
    }

    /**
     * Stripe sent the owner back. Ask Stripe what actually happened.
     *
     * The setup_intent on the query string is not trusted for anything except
     * which intent to read: BillingCustomer checks it belongs to this
     * restaurant's own customer before it becomes the default payment method,
     * so a pasted id cannot point this restaurant's invoices at somebody else's
     * bank account.
     */
    public function completePaymentMethod(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];
        $this->authorizeRestaurant($restaurantId);

        Session::forget(self::SETUP_KEY);

        $setupIntentId = trim((string) $request->input('setup_intent', ''));

        try {
            $paymentMethod = (new BillingCustomer())->attachFromSetupIntent($restaurant, $setupIntentId);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Billing payment method attach failed: ' . $exception->getMessage());

            $this->back('/kitchen/billing', 'Stripe could not confirm that payment method. Try again.', 'bad');
        }

        if ($paymentMethod === null) {
            // A bank account being verified by micro-deposits sits unconfirmed
            // for days, which is not a failure and must not read as one.
            $this->back(
                '/kitchen/billing',
                'Stripe has not confirmed that payment method yet. We will use it as soon as it is verified.',
                'warn'
            );
        }

        Activity::log('billing.payment_method_set', 'Restaurant', $restaurantId, [
            'payment_method' => $paymentMethod,
        ]);

        $this->back('/kitchen/billing', 'That payment method is on file for your monthly fee.');
    }

    /**
     * /kitchen/month is where the projection panel used to live, and a kitchen
     * tablet that bookmarked it should land on the page that replaced it rather
     * than on a 404.
     */
    public function month(Request $request): void
    {
        Response::redirect('/kitchen/billing');
    }
}
