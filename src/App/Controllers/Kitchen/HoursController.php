<?php

namespace Keel\App\Controllers\Kitchen;

use Keel\App\Models\Restaurant;
use Keel\App\Services\RestaurantHours;
use Keel\Core\Activity;
use Keel\Core\Request;

/**
 * Opening hours, holiday closures, and the big switch.
 *
 * Pause Orders is the control that matters most on this screen and gets the
 * most room. A kitchen that has just lost its fryer needs to stop the flow in
 * one tap, and needs to be able to do it without committing to a time, so
 * "until I say" is a first-class choice rather than a fallback.
 *
 * A timed pause resumes by itself because nothing here can promise to run in
 * fifteen minutes. See Restaurant::isPaused().
 */
class HoursController extends KitchenController
{
    public function index(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $hours = RestaurantHours::normalize($restaurant['hours'] ?? null);

        $this->view('kitchen.hours', array_merge(
            $this->shell($restaurant, 'hours', 'Hours'),
            [
                'hours' => $hours,
                'errors' => [],
                'paused' => Restaurant::isPaused($restaurant),
                'pauseMinutesLeft' => Restaurant::pauseMinutesLeft($restaurant),
            ]
        ));
    }

    public function save(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];
        $this->authorizeRestaurant($restaurantId);

        $result = RestaurantHours::fromForm($request->all());

        if ($result['errors'] !== []) {
            $this->view('kitchen.hours', array_merge(
                $this->shell($restaurant, 'hours', 'Hours'),
                [
                    'hours' => $result['hours'],
                    'errors' => $result['errors'],
                    'paused' => Restaurant::isPaused($restaurant),
                    'pauseMinutesLeft' => Restaurant::pauseMinutesLeft($restaurant),
                ]
            ));

            return;
        }

        Restaurant::update($restaurantId, ['hours' => RestaurantHours::encode($result['hours'])]);
        Activity::log('restaurant.hours_updated', 'Restaurant', $restaurantId);

        $this->back('/kitchen/hours', 'Hours saved.');
    }

    /**
     * Stop taking orders, for a while or until further notice.
     */
    public function pause(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];
        $this->authorizeRestaurant($restaurantId);

        $raw = trim((string) $request->input('minutes', ''));
        $minutes = $raw === '' || $raw === 'until_resumed' ? null : (int) $raw;

        try {
            Restaurant::pause($restaurantId, $minutes);
        } catch (\InvalidArgumentException $exception) {
            $this->back('/kitchen/hours', 'Pick how long to pause for.', 'bad');
        }

        Activity::log('restaurant.paused', 'Restaurant', $restaurantId, ['minutes' => $minutes]);

        $this->back(
            $this->backTo($request),
            $minutes === null
                ? 'Orders paused until you resume.'
                : "Orders paused for {$minutes} minutes. They will start again on their own.",
            'warn'
        );
    }

    public function resume(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];
        $this->authorizeRestaurant($restaurantId);

        Restaurant::resume($restaurantId);
        Activity::log('restaurant.resumed', 'Restaurant', $restaurantId);

        $this->back($this->backTo($request), 'Taking orders again.');
    }

    /**
     * The pause switch is on the board as well as this screen, so the action
     * returns to whichever one it was tapped from. Only kitchen paths are
     * accepted, so it cannot be turned into an open redirect.
     */
    private function backTo(Request $request): string
    {
        $to = (string) $request->input('back', '');

        return preg_match('#^/kitchen(/[A-Za-z0-9/_-]*)?$#', $to) === 1 ? $to : '/kitchen/hours';
    }
}
