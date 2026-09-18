<?php

namespace Keel\App\Controllers\Kitchen;

use Keel\App\Models\Restaurant;
use Keel\App\Models\RestaurantStaff;
use Keel\App\Models\User;
use Keel\App\Policies\RestaurantPolicy;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Response;
use Keel\Core\Session;
use Keel\Core\View;

/**
 * What every kitchen screen needs before it can do anything: which restaurant
 * this is, and whether this person is allowed near it.
 *
 * RequireRestaurantStaff on the route group answers "is this a kitchen user".
 * It cannot answer "is this *their* kitchen", because that depends on the
 * record in the URL. That second question is this class's whole job, and every
 * kitchen action asks it — authorizeRestaurant() before anything scoped to a
 * restaurant, authorizeRecord() before anything that arrived as a record id.
 *
 * Both end in a 403 page rather than a redirect. A staff member who followed a
 * link to another restaurant's order should be told no, not quietly moved
 * somewhere that looks like it worked.
 */
abstract class KitchenController extends Controller
{
    /** Where a flash message waits between the POST and the redirect. */
    private const FLASH_KEY = '_kitchen_flash';

    /** The restaurant this tab is working on, when the user has more than one. */
    private const ACTIVE_RESTAURANT_KEY = 'kitchen_restaurant_id';

    protected function userId(): int
    {
        return (int) Auth::id();
    }

    /**
     * Every restaurant this user may act for.
     */
    protected function restaurants(): array
    {
        return RestaurantStaff::restaurantsForUser($this->userId());
    }

    /**
     * The restaurant the kitchen screens are showing.
     *
     * Most owners have one. Someone with two picks, and the pick is remembered
     * in the session rather than in the URL, so no link ever carries a
     * restaurant id that a scope check then has to defend.
     *
     * Returns null when this user is not attached to any restaurant yet, which
     * is the state a brand new owner signs in to.
     */
    protected function currentRestaurant(): ?array
    {
        $restaurants = $this->restaurants();

        if ($restaurants === []) {
            return null;
        }

        $selectedId = (int) Session::get(self::ACTIVE_RESTAURANT_KEY, 0);

        foreach ($restaurants as $restaurant) {
            if ((int) $restaurant['id'] === $selectedId) {
                return Restaurant::resumeIfExpired($restaurant);
            }
        }

        return Restaurant::resumeIfExpired($restaurants[0]);
    }

    /**
     * The current restaurant, or a 403 when there is none.
     *
     * For the routes that only make sense once a restaurant exists. The screens
     * that have something to say to an owner without one — the board, the
     * onboarding form — use currentRestaurant() and handle the null.
     */
    protected function requireRestaurant(): array
    {
        $restaurant = $this->currentRestaurant();

        if ($restaurant === null) {
            $this->forbidden();
        }

        return $restaurant;
    }

    protected function selectRestaurant(int $restaurantId): void
    {
        $this->authorizeRestaurant($restaurantId);

        Session::put(self::ACTIVE_RESTAURANT_KEY, $restaurantId);
    }

    /**
     * Stops here unless this user is staff of this restaurant.
     */
    protected function authorizeRestaurant(int $restaurantId): void
    {
        if (!RestaurantPolicy::allowsRestaurant($this->userId(), $restaurantId)) {
            $this->forbidden();
        }
    }

    /**
     * Stops here unless this record belongs to a restaurant this user is staff
     * of. A record that does not exist is refused the same way, so probing ids
     * tells the prober nothing.
     */
    protected function authorizeRecord(string $table, int $recordId): void
    {
        if (!RestaurantPolicy::allowsRecord($this->userId(), $table, $recordId)) {
            $this->forbidden();
        }
    }

    /**
     * Stops here unless this user owns the restaurant, rather than merely
     * working there. Inviting and removing staff is an owner's call.
     */
    protected function authorizeStaffAdmin(int $restaurantId): void
    {
        if (!RestaurantPolicy::allowsStaffAdmin($this->userId(), $restaurantId)) {
            $this->forbidden();
        }
    }

    protected function forbidden(): never
    {
        $user = User::find($this->userId());

        if ($this->wantsJson()) {
            Response::json(['error' => 'Forbidden.'], 403);
        }

        Response::raw($this->forbiddenPage($user), 403, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    protected function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return str_contains((string) $accept, 'application/json');
    }

    /**
     * A one-shot message carried across a redirect.
     */
    protected function flash(string $message, string $tone = 'good'): void
    {
        Session::put(self::FLASH_KEY, ['message' => $message, 'tone' => $tone]);
    }

    /**
     * @return array{message: string, tone: string}|null
     */
    protected function takeFlash(): ?array
    {
        $flash = Session::get(self::FLASH_KEY);
        Session::forget(self::FLASH_KEY);

        return is_array($flash) ? $flash : null;
    }

    /**
     * The data every kitchen view's chrome needs.
     */
    protected function shell(?array $restaurant, string $active, string $title): array
    {
        return [
            'title' => $title,
            'activeNav' => $active,
            'user' => User::find($this->userId()),
            'restaurant' => $restaurant,
            'restaurants' => $this->restaurants(),
            'flash' => $this->takeFlash(),
        ];
    }

    /**
     * Saves a message and goes back to where the form was.
     */
    protected function back(string $to, string $message = '', string $tone = 'good'): never
    {
        if ($message !== '') {
            $this->flash($message, $tone);
        }

        Response::redirect($to);
    }

    private function forbiddenPage(?array $user): string
    {
        ob_start();

        try {
            View::render('errors.403', ['homePath' => User::homePath($user)]);
        } catch (\Throwable $exception) {
            ob_end_clean();

            return 'Forbidden';
        }

        return (string) ob_get_clean();
    }
}
