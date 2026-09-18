<?php

namespace Keel\App\Controllers\App;

use Keel\App\Controllers\Kitchen\SpecialsController;
use Keel\App\Models\Address;
use Keel\App\Models\MenuCategory;
use Keel\App\Models\MenuItem;
use Keel\App\Models\Restaurant;
use Keel\App\Models\Special;
use Keel\App\Services\RestaurantHours;
use Keel\App\Services\ZoneService;
use Keel\Core\Request;

/**
 * One restaurant's menu, and one item's sheet.
 *
 * The item sheet is a real page at a real URL. On a phone the menu intercepts
 * the tap and shows the same markup in a bottom sheet, but the link underneath
 * still works, which means the options, the quantity and the special
 * instructions are a form post rather than a pile of state in a script. A
 * browser that never runs the script gets a page; one that does gets a sheet.
 *
 * Sold-out items stay on the menu, disabled. Removing them makes a kitchen look
 * like it stopped selling something, and a customer who scrolls past a greyed
 * "sold out" understands the situation immediately.
 */
class MenuController extends CustomerController
{
    public function show(Request $request, string $slug): void
    {
        $restaurant = $this->restaurant($slug);
        $categories = $this->categories((int) $restaurant['id']);
        $specials = Special::runningNow(Special::activeForRestaurant((int) $restaurant['id']));

        $this->view('app.menu', array_merge(
            $this->shell('browse', (string) $restaurant['name']),
            [
                'restaurant' => $restaurant,
                'categories' => $categories,
                'specials' => $this->describeSpecials($specials),
                'specialItemIds' => $this->specialItemIds($specials),
                'isOpen' => !Restaurant::isPaused($restaurant)
                    && RestaurantHours::isOpenAt($restaurant['hours'] ?? null),
                'statusLabel' => RestaurantHours::nextOpeningLabel($restaurant['hours'] ?? null),
                'outOfZone' => $this->outOfZone($restaurant),
            ]
        ));
    }

    /**
     * One item, as a page or as the sheet's contents.
     *
     * The same view either way; only the chrome around it differs, which is what
     * keeps the two from drifting apart.
     */
    public function item(Request $request, string $slug, string $id): void
    {
        $restaurant = $this->restaurant($slug);
        $found = $this->cart()->itemWithGroups((int) $id);

        if ($found === null
            || (int) $found['item']['restaurant_id'] !== (int) $restaurant['id']
            || (int) $found['item']['active'] !== 1) {
            $this->notFound();
        }

        $data = [
            'restaurant' => $restaurant,
            'item' => $found['item'],
            'groups' => $found['groups'],
            'line' => null,
            'errors' => [],
        ];

        if ($this->wantsJson() || (string) $request->input('sheet', '') === '1') {
            $this->json([
                'id' => (int) $found['item']['id'],
                'name' => (string) $found['item']['name'],
                'html' => $this->renderToString('app.partials.item-form', $data),
            ]);
        }

        $this->view('app.item', array_merge(
            $this->shell('browse', (string) $found['item']['name']),
            $data
        ));
    }

    /**
     * An active restaurant by slug, or a 404.
     */
    private function restaurant(string $slug): array
    {
        $restaurant = Restaurant::findBySlug($slug);

        if ($restaurant === null || (string) $restaurant['status'] !== Restaurant::STATUS_ACTIVE) {
            $this->notFound();
        }

        return $restaurant;
    }

    /**
     * Active categories, each with the items a customer may see.
     *
     * Hidden items are gone; sold-out ones are here and marked, because that is
     * information rather than clutter.
     *
     * @return list<array<string, mixed>>
     */
    private function categories(int $restaurantId): array
    {
        $categories = [];

        foreach (MenuCategory::forRestaurant($restaurantId) as $category) {
            if ((int) $category['active'] !== 1) {
                continue;
            }

            $items = array_values(array_filter(
                MenuItem::forCategory((int) $category['id']),
                static fn (array $item): bool => (int) $item['active'] === 1
            ));

            if ($items === []) {
                continue;
            }

            $category['items'] = $items;
            $category['anchor'] = 'category-' . (int) $category['id'];
            $categories[] = $category;
        }

        return $categories;
    }

    /**
     * Specials with the same one-line description the kitchen previewed when it
     * wrote them. One function, so the preview is never a different promise from
     * the menu.
     *
     * @param list<array<string, mixed>> $specials
     * @return list<array<string, mixed>>
     */
    private function describeSpecials(array $specials): array
    {
        foreach ($specials as $index => $special) {
            $itemName = '';

            if ($special['menu_item_id'] !== null) {
                $item = MenuItem::find((int) $special['menu_item_id']);
                $itemName = (string) ($item['name'] ?? '');
            }

            $specials[$index]['line'] = SpecialsController::describe($special, $itemName);
            $specials[$index]['item_name'] = $itemName;
        }

        return $specials;
    }

    /**
     * Which items carry a special right now, so the menu row can say so.
     *
     * @param list<array<string, mixed>> $specials
     * @return list<int>
     */
    private function specialItemIds(array $specials): array
    {
        $ids = [];

        foreach ($specials as $special) {
            if ($special['menu_item_id'] !== null) {
                $ids[] = (int) $special['menu_item_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * True when this restaurant cannot reach the customer's delivery address.
     *
     * The menu is still shown — browsing is harmless and a customer may be
     * looking for somewhere else to be — but the banner says so now rather than
     * letting them build a cart they cannot pay for.
     */
    private function outOfZone(array $restaurant): bool
    {
        $address = Address::defaultForUser($this->userId());

        if ($address === null || $address['lat'] === null || $address['lng'] === null) {
            return false;
        }

        $zone = ZoneService::zoneFor((float) $address['lat'], (float) $address['lng']);
        $restaurantZoneId = $restaurant['delivery_zone_id'] ?? null;

        if ($zone === null) {
            return true;
        }

        return $restaurantZoneId !== null && (int) $restaurantZoneId !== (int) $zone['id'];
    }
}
