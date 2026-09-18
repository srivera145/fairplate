<?php

namespace Keel\App\Controllers\App;

use Keel\App\Models\Address;
use Keel\App\Services\Geo\GeocoderFactory;
use Keel\App\Services\ZoneService;
use Keel\Core\Request;

/**
 * The address book.
 *
 * Every address is geocoded on the way in and refused if the point falls
 * outside the delivery area, which is the only moment FairPlate can say "not
 * yet" while it still costs nobody anything. Letting an out-of-zone address be
 * saved and rejected later means a customer builds a cart, picks a tip and
 * types a card before being told.
 *
 * The coordinates are the reason for the lookup, not a nicety: the zone test,
 * the sort on the browse screen and the driver's route miles all need a point,
 * and an address with no point is an address no part of this can price.
 */
class AddressesController extends CustomerController
{
    /** The message the spec asks for, in one place so every path says it. */
    public const OUT_OF_ZONE = 'Not in our delivery area yet.';

    private const NOT_FOUND = 'We could not find that address. Check the street number and ZIP code.';

    private const LOOKUP_DOWN = 'Address lookup is unavailable right now. Try again in a moment.';

    private const STATES = ['FL'];

    public function index(Request $request): void
    {
        $this->view('app.addresses', array_merge(
            $this->shell('addresses', 'Addresses'),
            [
                'addresses' => Address::forUser($this->userId()),
                'editing' => null,
                'errors' => [],
                'old' => [],
            ]
        ));
    }

    public function edit(Request $request, string $id): void
    {
        $address = Address::forUserAndId($this->userId(), (int) $id);

        if ($address === null) {
            $this->notFound();
        }

        $this->view('app.addresses', array_merge(
            $this->shell('addresses', 'Edit address'),
            [
                'addresses' => Address::forUser($this->userId()),
                'editing' => $address,
                'errors' => [],
                'old' => $address,
            ]
        ));
    }

    public function store(Request $request): void
    {
        $this->save($request, null);
    }

    public function update(Request $request, string $id): void
    {
        $address = Address::forUserAndId($this->userId(), (int) $id);

        if ($address === null) {
            $this->notFound();
        }

        $this->save($request, $address);
    }

    public function destroy(Request $request, string $id): void
    {
        $userId = $this->userId();
        $address = Address::forUserAndId($userId, (int) $id);

        if ($address === null) {
            $this->notFound();
        }

        Address::delete((int) $address['id']);
        Address::ensureDefault($userId);

        $this->back('/app/addresses', 'Address deleted.');
    }

    public function makeDefault(Request $request, string $id): void
    {
        $userId = $this->userId();
        $address = Address::forUserAndId($userId, (int) $id);

        if ($address === null) {
            $this->notFound();
        }

        Address::makeDefault($userId, (int) $address['id']);

        $this->back('/app/addresses', 'Deliveries will go to ' . (string) $address['label'] . ' from now on.');
    }

    /**
     * Validate, geocode, zone-check, save. In that order, because each step
     * needs the one before it to have produced something real.
     */
    private function save(Request $request, ?array $existing): void
    {
        $userId = $this->userId();
        $input = $this->input($request);
        $errors = $this->validate($input);

        if ($errors === []) {
            $point = $this->locate($input, $errors);

            if ($point !== null) {
                if (!ZoneService::contains($point['lat'], $point['lng'])) {
                    $errors[] = self::OUT_OF_ZONE;
                } else {
                    $this->persist($userId, $input, $point, $existing);

                    $this->back(
                        '/app/addresses',
                        $existing === null ? 'Address saved.' : 'Address updated.'
                    );
                }
            }
        }

        $this->view('app.addresses', array_merge(
            $this->shell('addresses', $existing === null ? 'Addresses' : 'Edit address'),
            [
                'addresses' => Address::forUser($userId),
                'editing' => $existing,
                'errors' => $errors,
                'old' => $input,
            ]
        ));
    }

    private function persist(int $userId, array $input, array $point, ?array $existing): void
    {
        $attributes = [
            'user_id' => $userId,
            'label' => $input['label'],
            'line1' => $input['line1'],
            'line2' => $input['line2'] === '' ? null : $input['line2'],
            'city' => $input['city'],
            'state' => $input['state'],
            'zip' => $input['zip'],
            'lat' => number_format($point['lat'], 7, '.', ''),
            'lng' => number_format($point['lng'], 7, '.', ''),
            'instructions' => $input['instructions'] === '' ? null : $input['instructions'],
        ];

        if ($existing === null) {
            $addressId = Address::create($attributes);
            $first = count(Address::forUser($userId)) === 1;

            if ($first || $input['is_default']) {
                Address::makeDefault($userId, $addressId);
            }

            return;
        }

        Address::update((int) $existing['id'], $attributes);

        if ($input['is_default']) {
            Address::makeDefault($userId, (int) $existing['id']);
        }

        Address::ensureDefault($userId);
    }

    /**
     * The typed address as a point, or null with the reason appended.
     *
     * A geocoder that is down and a geocoder that found nothing are different
     * problems and get different sentences: one is worth retrying and the other
     * means the address is wrong.
     *
     * @param list<string> $errors
     * @return array{lat: float, lng: float}|null
     */
    private function locate(array $input, array &$errors): ?array
    {
        $query = implode(', ', array_filter([
            $input['line1'],
            $input['city'],
            $input['state'] . ' ' . $input['zip'],
        ]));

        try {
            $result = GeocoderFactory::make()->geocode($query);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Geocoding failed: ' . $exception->getMessage());
            $errors[] = self::LOOKUP_DOWN;

            return null;
        }

        if ($result === null) {
            $errors[] = self::NOT_FOUND;

            return null;
        }

        return ['lat' => (float) $result['lat'], 'lng' => (float) $result['lng']];
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return [
            'label' => $this->text($request, 'label', 60) ?: 'Home',
            'line1' => $this->text($request, 'line1', 255),
            'line2' => $this->text($request, 'line2', 255),
            'city' => $this->text($request, 'city', 120),
            'state' => strtoupper($this->text($request, 'state', 2)),
            'zip' => $this->text($request, 'zip', 10),
            'instructions' => $this->text($request, 'instructions', 500),
            'is_default' => (string) $request->input('is_default', '') === '1',
        ];
    }

    /**
     * @return list<string>
     */
    private function validate(array $input): array
    {
        $errors = [];

        if ($input['line1'] === '') {
            $errors[] = 'A street address is needed.';
        }

        if ($input['city'] === '') {
            $errors[] = 'A city is needed.';
        }

        if (!in_array($input['state'], self::STATES, true)) {
            $errors[] = 'FairPlate only delivers in Florida so far.';
        }

        if (!preg_match('/^\d{5}(-\d{4})?$/', $input['zip'])) {
            $errors[] = 'A ZIP code looks like 32301.';
        }

        return $errors;
    }

    private function text(Request $request, string $key, int $limit): string
    {
        return trim(mb_substr(trim((string) $request->input($key, '')), 0, $limit));
    }
}
