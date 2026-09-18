<?php

namespace Keel\App\Controllers\Kitchen;

use Keel\App\Models\Restaurant;
use Keel\App\Services\StripeConnectService;
use Keel\Core\Activity;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * The three routes Stripe Connect Express onboarding needs.
 *
 * start is a POST because it creates a connected account the first time it is
 * called, and a GET that creates things is a GET a browser will helpfully
 * repeat. The other two are Stripe sending the owner back to us, so they are
 * GETs whether we like it or not, and neither of them trusts what it is told:
 * return re-reads the account from Stripe rather than believing that arriving
 * at the return URL means anything.
 */
class ConnectController extends KitchenController
{
    public function start(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $this->authorizeRestaurant((int) $restaurant['id']);

        try {
            $url = (new StripeConnectService())->onboardingUrl($restaurant);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Stripe Connect onboarding failed: ' . $exception->getMessage());

            $this->back('/kitchen/onboarding', 'Stripe could not start onboarding. Try again in a moment.', 'bad');
        }

        Response::redirect($url);
    }

    /**
     * Stripe's link expired before the owner used it. Mint another and send
     * them straight back; there is nothing to show on this route.
     */
    public function refresh(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $this->authorizeRestaurant((int) $restaurant['id']);

        try {
            $url = (new StripeConnectService())->refreshUrl($restaurant);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Stripe Connect refresh failed: ' . $exception->getMessage());

            $this->back('/kitchen/onboarding', 'That Stripe link expired. Start payouts setup again.', 'bad');
        }

        Response::redirect($url);
    }

    /**
     * The owner finished, or backed out. Either way, ask Stripe.
     */
    public function return(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];
        $this->authorizeRestaurant($restaurantId);

        $status = (new StripeConnectService())->status($restaurant);

        Activity::log('restaurant.stripe_onboarding_returned', 'Restaurant', $restaurantId, $status);

        if (!$status['details_submitted']) {
            $this->back(
                '/kitchen/onboarding',
                'Stripe still needs a few details before payouts can start.',
                'warn'
            );
        }

        // Finished with Stripe is not the same as open for business. The spec
        // has an admin approve the restaurant, so the status is left alone here
        // and the message says so rather than implying the kitchen is live.
        $this->back(
            '/kitchen/onboarding',
            (string) Restaurant::find($restaurantId)['name']
                . ' is connected to Stripe. FairPlate will review and approve your restaurant next.'
        );
    }
}
