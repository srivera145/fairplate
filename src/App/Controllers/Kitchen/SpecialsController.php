<?php

namespace Keel\App\Controllers\Kitchen;

use Keel\App\Models\MenuItem;
use Keel\App\Models\Special;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Pricing\PricingException;
use Keel\Core\Activity;
use Keel\Core\Request;

/**
 * The specials a restaurant runs, and a preview of how each one reads.
 *
 * A special is three fields that interact — a type, a value, and sometimes an
 * item — and the sentence they produce is not obvious from the form. So the
 * form carries a preview of the exact card the customer will see, built by the
 * same describe() the customer menu will call in phase 4. One function, so the
 * preview cannot drift away from the thing it is previewing.
 *
 * Nothing here prices anything. A special is stored as a type and a value and
 * is applied by PricingService when an order is built; this screen only decides
 * what the numbers are.
 */
class SpecialsController extends KitchenController
{
    public function index(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];

        $specials = Special::forRestaurant($restaurantId);

        foreach ($specials as $index => $special) {
            $specials[$index]['preview'] = self::describe($special, $this->itemName($special));
            $specials[$index]['day_labels'] = self::dayLabels($special);
        }

        $this->view('kitchen.specials', array_merge(
            $this->shell($restaurant, 'specials', 'Specials'),
            [
                'specials' => $specials,
                'items' => MenuItem::forRestaurant($restaurantId),
            ]
        ));
    }

    public function store(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];

        $attributes = $this->attributesFrom($request, $restaurantId);
        $attributes['restaurant_id'] = $restaurantId;

        $id = Special::create($attributes);
        Activity::log('special.created', 'Special', $id, ['title' => $attributes['title']]);

        $this->back('/kitchen/specials', 'Special added.');
    }

    public function update(Request $request, string $id): void
    {
        $specialId = (int) $id;
        $this->authorizeRecord('specials', $specialId);

        $restaurantId = (int) Special::find($specialId)['restaurant_id'];

        Special::update($specialId, $this->attributesFrom($request, $restaurantId));

        $this->back('/kitchen/specials', 'Special saved.');
    }

    public function destroy(Request $request, string $id): void
    {
        $specialId = (int) $id;
        $this->authorizeRecord('specials', $specialId);

        Special::delete($specialId);
        Activity::log('special.deleted', 'Special', $specialId);

        $this->back('/kitchen/specials', 'Special removed.');
    }

    /**
     * The one sentence a special turns into, wherever it is shown.
     *
     * Percentages are stored as fractions, so 0.2000 has to read as "20% off"
     * and not "0.2% off"; money is cents and has to read as dollars. Both
     * conversions are the shared helpers, not arithmetic written here.
     */
    public static function describe(array $special, string $itemName = ''): string
    {
        $scope = $itemName !== '' ? $itemName : 'your order';

        return match ((string) $special['type']) {
            Special::TYPE_PERCENT => Money::percentFromRate((string) ($special['value_pct'] ?? '0'))
                . '% off ' . $scope,
            Special::TYPE_AMOUNT => '$' . Money::toDollars((int) ($special['value_cents'] ?? 0))
                . ' off ' . $scope,
            Special::TYPE_PRICE => $scope . ' for $' . Money::toDollars((int) ($special['value_cents'] ?? 0)),
            default => (string) $special['title'],
        };
    }

    /**
     * "Mon, Tue" or "Every day", plus the hours when the special is timed.
     *
     * @return string
     */
    public static function dayLabels(array $special): string
    {
        $names = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $days = $special['days'] ?? null;

        if (is_string($days)) {
            $days = json_decode($days, true);
        }

        $label = 'Every day';

        if (is_array($days) && $days !== []) {
            $label = implode(', ', array_map(
                static fn (int $day): string => $names[$day] ?? '',
                array_map('intval', $days)
            ));
        }

        $start = $special['start_time'] ?? null;
        $end = $special['end_time'] ?? null;

        if ($start !== null && $end !== null) {
            $label .= ', ' . substr((string) $start, 0, 5) . '–' . substr((string) $end, 0, 5);
        }

        return $label;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFrom(Request $request, int $restaurantId): array
    {
        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            $this->back('/kitchen/specials', 'A special needs a title.', 'bad');
        }

        $type = (string) $request->input('type', Special::TYPE_PERCENT);

        if (!in_array($type, [Special::TYPE_PERCENT, Special::TYPE_AMOUNT, Special::TYPE_PRICE], true)) {
            $this->back('/kitchen/specials', 'Pick a kind of special.', 'bad');
        }

        // An item-scoped special has to point at this restaurant's own item.
        $menuItemId = (int) $request->input('menu_item_id', 0);

        if ($menuItemId > 0) {
            $this->authorizeRecord('menu_items', $menuItemId);
        }

        if ($type === Special::TYPE_PRICE && $menuItemId === 0) {
            $this->back('/kitchen/specials', 'A fixed-price special has to name an item.', 'bad');
        }

        $days = array_values(array_filter(
            array_map('intval', (array) $request->input('days', [])),
            static fn (int $day): bool => $day >= 1 && $day <= 7
        ));

        $attributes = [
            'title' => $title,
            'description' => $this->nullable($request->input('description')),
            'type' => $type,
            'menu_item_id' => $menuItemId > 0 ? $menuItemId : null,
            'days' => $days === [] ? null : json_encode($days),
            'start_time' => $this->nullableTime($request->input('start_time')),
            'end_time' => $this->nullableTime($request->input('end_time')),
            'active' => $request->input('active') !== null ? 1 : 0,
            'value_pct' => null,
            'value_cents' => null,
        ];

        try {
            if ($type === Special::TYPE_PERCENT) {
                $attributes['value_pct'] = Money::rateFromPercent((string) $request->input('value', ''));
            } else {
                $attributes['value_cents'] = Money::fromDollars((string) $request->input('value', ''));
            }
        } catch (PricingException $exception) {
            $this->back('/kitchen/specials', $exception->getMessage(), 'bad');
        }

        return $attributes;
    }

    private function itemName(array $special): string
    {
        $itemId = $special['menu_item_id'] ?? null;

        if ($itemId === null) {
            return '';
        }

        return (string) (MenuItem::find((int) $itemId)['name'] ?? '');
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value . ':00' : null;
    }
}
