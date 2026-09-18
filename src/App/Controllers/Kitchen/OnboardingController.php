<?php

namespace Keel\App\Controllers\Kitchen;

use Keel\App\Models\Restaurant;
use Keel\App\Models\RestaurantStaff;
use Keel\App\Services\Geo\GeocoderFactory;
use Keel\App\Services\ImageService;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\RestaurantHours;
use Keel\App\Services\StripeConnectService;
use Keel\App\Services\ZoneService;
use Keel\Core\Activity;
use Keel\Core\Database;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * The restaurant profile, and the gate in front of everything else.
 *
 * The address is the part with teeth. It is typed as text, geocoded to a point,
 * and the point has to land inside an active delivery zone — not because the
 * address is suspect, but because a restaurant outside the zone can never be
 * dispatched to, and finding that out at the first order is far worse than
 * finding it out here. A zone rejection is a plain answer on the form, not an
 * error page: it usually means a real restaurant in a real town FairPlate has
 * not reached yet.
 *
 * Nothing this screen does can make a restaurant live. status stays pending
 * however complete the profile is and however finished Stripe says the account
 * is; an admin approves, in phase 7.
 */
class OnboardingController extends KitchenController
{
    public function show(Request $request): void
    {
        $restaurant = $this->currentRestaurant();

        $this->view('kitchen.onboarding', array_merge(
            $this->shell($restaurant, 'onboarding', 'Restaurant profile'),
            [
                'connect' => $restaurant === null ? null : (new StripeConnectService())->status($restaurant),
                'form' => $this->formValues($restaurant),
                'errors' => [],
                'photosAvailable' => ImageService::isAvailable(),
            ]
        ));
    }

    public function save(Request $request): void
    {
        $restaurant = $this->currentRestaurant();
        $input = $request->all();

        $values = $this->formValues($restaurant);

        foreach (['name', 'phone', 'line1', 'line2', 'city', 'state', 'zip', 'tax_rate', 'open', 'close'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = trim((string) $input[$field]);
            }
        }

        $errors = $this->validate($values);

        // The geocode is the last check, because it is the one that costs a
        // network call, and there is no point spending it on a form that is
        // already missing its city.
        $point = null;

        if ($errors === []) {
            $point = GeocoderFactory::make()->geocode($this->addressLine($values));

            if ($point === null) {
                $errors['line1'] = 'We could not find that address. Check the street, city and ZIP.';
            } else {
                $zone = ZoneService::zoneFor($point['lat'], $point['lng']);

                if ($zone === null) {
                    $errors['line1'] = 'That address is outside every FairPlate delivery zone. '
                        . 'We are not live in your area yet.';
                } else {
                    $point['zone_id'] = (int) $zone['id'];
                }
            }
        }

        if ($errors !== []) {
            $this->view('kitchen.onboarding', array_merge(
                $this->shell($restaurant, 'onboarding', 'Restaurant profile'),
                [
                    'connect' => $restaurant === null ? null : (new StripeConnectService())->status($restaurant),
                    'form' => $values,
                    'errors' => $errors,
                    'photosAvailable' => ImageService::isAvailable(),
                ]
            ));

            return;
        }

        $attributes = [
            'name' => $values['name'],
            'phone' => $values['phone'] === '' ? null : $values['phone'],
            'line1' => $values['line1'],
            'line2' => $values['line2'] === '' ? null : $values['line2'],
            'city' => $values['city'],
            'state' => strtoupper($values['state']),
            'zip' => $values['zip'],
            'lat' => (string) $point['lat'],
            'lng' => (string) $point['lng'],
            'delivery_zone_id' => $point['zone_id'],
            'tax_rate' => Money::rateFromPercent($values['tax_rate']),
        ];

        if ($restaurant === null) {
            $attributes['slug'] = $this->uniqueSlug($values['name']);
            $attributes['status'] = Restaurant::STATUS_PENDING;
            $attributes['hours'] = RestaurantHours::encode([
                'days' => array_fill_keys(
                    RestaurantHours::DAYS,
                    [['open' => $values['open'], 'close' => $values['close']]]
                ),
            ]);

            $restaurantId = Restaurant::create($attributes);

            RestaurantStaff::create([
                'restaurant_id' => $restaurantId,
                'user_id' => $this->userId(),
                'is_owner' => 1,
            ]);

            $this->selectRestaurant($restaurantId);
            Activity::log('restaurant.created', 'Restaurant', $restaurantId, ['name' => $values['name']]);

            $this->back('/kitchen/onboarding', 'Profile saved. Connect your payouts next.');
        }

        $restaurantId = (int) $restaurant['id'];
        $this->authorizeRestaurant($restaurantId);

        Restaurant::update($restaurantId, $attributes);
        Activity::log('restaurant.updated', 'Restaurant', $restaurantId);

        $this->back('/kitchen/onboarding', 'Profile saved.');
    }

    /**
     * The logo and the cover image, each on its own small form so a slow upload
     * never costs the owner the rest of the profile they had typed.
     */
    public function uploadPhoto(Request $request, string $kind): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];
        $this->authorizeRestaurant($restaurantId);

        if (!in_array($kind, ['logo', 'cover'], true)) {
            $this->forbidden();
        }

        $file = $_FILES['photo'] ?? null;

        if (!is_array($file)) {
            $this->back('/kitchen/onboarding', 'Choose an image first.', 'bad');
        }

        try {
            $path = ImageService::storeUpload($file, 'restaurants/' . $restaurantId);
        } catch (\RuntimeException $exception) {
            $this->back('/kitchen/onboarding', $exception->getMessage(), 'bad');
        }

        ImageService::delete($restaurant[$kind] ?? null);
        Restaurant::update($restaurantId, [$kind => $path]);

        $this->back('/kitchen/onboarding', ucfirst($kind) . ' updated.');
    }

    /**
     * Switches which restaurant the kitchen screens are showing.
     */
    public function selectRestaurantAction(Request $request): void
    {
        $restaurantId = (int) $request->input('restaurant_id', 0);

        $this->selectRestaurant($restaurantId);

        Response::redirect('/kitchen');
    }

    /**
     * @return array<string, string>
     */
    private function formValues(?array $restaurant): array
    {
        if ($restaurant === null) {
            return [
                'name' => '', 'phone' => '', 'line1' => '', 'line2' => '',
                'city' => 'Tallahassee', 'state' => 'FL', 'zip' => '',
                'tax_rate' => '', 'open' => '11:00', 'close' => '22:00',
            ];
        }

        $hours = RestaurantHours::normalize($restaurant['hours'] ?? null);
        $firstRange = $hours['days']['mon'][0] ?? ['open' => '11:00', 'close' => '22:00'];

        return [
            'name' => (string) $restaurant['name'],
            'phone' => (string) ($restaurant['phone'] ?? ''),
            'line1' => (string) $restaurant['line1'],
            'line2' => (string) ($restaurant['line2'] ?? ''),
            'city' => (string) $restaurant['city'],
            'state' => (string) $restaurant['state'],
            'zip' => (string) $restaurant['zip'],
            // Stored as a fraction, edited as the percentage on the till.
            'tax_rate' => Money::percentFromRate((string) $restaurant['tax_rate']),
            'open' => (string) $firstRange['open'],
            'close' => (string) $firstRange['close'],
        ];
    }

    /**
     * @return array<string, string> field => message
     */
    private function validate(array $values): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = 'Your restaurant needs a name.';
        }

        foreach (['line1' => 'Street address', 'city' => 'City', 'zip' => 'ZIP'] as $field => $label) {
            if ($values[$field] === '') {
                $errors[$field] = $label . ' is required.';
            }
        }

        if (!preg_match('/^[A-Za-z]{2}$/', $values['state'])) {
            $errors['state'] = 'Use the two-letter state code.';
        }

        if (!preg_match('/^\d{5}(-\d{4})?$/', $values['zip'])) {
            $errors['zip'] = 'That is not a ZIP code.';
        }

        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $values['tax_rate'])) {
            $errors['tax_rate'] = 'Enter your sales tax as a percentage, like 7.5.';
        }

        foreach (['open', 'close'] as $field) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $values[$field])) {
                $errors[$field] = 'Use a 24-hour time, like 11:00.';
            }
        }

        if (!isset($errors['open'], $errors['close']) && $values['open'] === $values['close']) {
            $errors['close'] = 'Opening and closing cannot be the same minute.';
        }

        return $errors;
    }

    private function addressLine(array $values): string
    {
        return implode(', ', array_filter([
            $values['line1'],
            $values['city'],
            strtoupper($values['state']) . ' ' . $values['zip'],
        ]));
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $base = $base === '' ? 'restaurant' : substr($base, 0, 140);

        $statement = Database::connection()->prepare('SELECT id FROM restaurants WHERE slug = ? LIMIT 1');

        $slug = $base;
        $suffix = 1;

        while (true) {
            $statement->execute([$slug]);

            if ($statement->fetch() === false) {
                return $slug;
            }

            $slug = $base . '-' . (++$suffix);
        }
    }
}
