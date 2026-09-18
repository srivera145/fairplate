<?php

namespace Keel\App\Controllers\App;

use Keel\App\Models\Address;
use Keel\App\Models\MenuItem;
use Keel\App\Models\Restaurant;
use Keel\App\Services\RestaurantHours;
use Keel\Core\Request;

/**
 * The cart screen and the four things that change it.
 *
 * Adding from a second restaurant is the only interesting case. It is not an
 * error — wanting noodles instead of tacos is a normal change of mind — so the
 * post comes back with both restaurants named and one button that says what
 * will happen. Nothing is thrown away until that button is pressed.
 *
 * Note what this controller never reads: a price. The post carries a menu item
 * id, a quantity, some option ids and a note. Every cent on the screen was
 * worked out by PricingService from the menu, which is why a page that invents
 * a cheaper number changes nothing at all.
 */
class CartController extends CustomerController
{
    public function index(Request $request): void
    {
        $summary = $this->cart()->summary($this->userId());
        $restaurant = $summary['restaurant'];

        $this->view('app.cart', array_merge(
            $this->shell('cart', 'Your cart'),
            [
                'summary' => $summary,
                'isOpen' => $restaurant !== null
                    && !Restaurant::isPaused($restaurant)
                    && RestaurantHours::isOpenAt($restaurant['hours'] ?? null),
                'statusLabel' => $restaurant === null
                    ? ''
                    : RestaurantHours::nextOpeningLabel($restaurant['hours'] ?? null),
                'addresses' => Address::forUser($this->userId()),
            ]
        ));
    }

    public function store(Request $request): void
    {
        $menuItemId = (int) $request->input('menu_item_id', 0);
        $quantity = (int) $request->input('quantity', 1);
        $optionIds = $this->optionIds($request);
        $notes = (string) $request->input('notes', '');
        $replace = (string) $request->input('replace_cart', '') === '1';

        $result = $this->cart()->add($this->userId(), $menuItemId, $quantity, $optionIds, $notes, $replace);

        if ($result['conflict'] !== null) {
            $this->view('app.cart-switch', array_merge(
                $this->shell('cart', 'Start a new cart?'),
                [
                    'current' => $result['conflict']['current'],
                    'wanted' => $result['conflict']['wanted'],
                    'menuItemId' => $menuItemId,
                    'quantity' => $quantity,
                    'optionIds' => $optionIds,
                    'notes' => $notes,
                ]
            ));

            return;
        }

        if (!$result['ok']) {
            $this->itemAgain($menuItemId, $optionIds, $quantity, $notes, $result['errors']);

            return;
        }

        $item = MenuItem::find($menuItemId);
        $name = (string) ($item['name'] ?? 'Item');

        $this->back('/app/cart', $name . ' added to your cart.');
    }

    public function updateLine(Request $request, string $id): void
    {
        $quantity = (int) $request->input('quantity', 1);

        if (!$this->cart()->setQuantity($this->userId(), (int) $id, $quantity)) {
            $this->notFound();
        }

        $this->back('/app/cart');
    }

    public function destroyLine(Request $request, string $id): void
    {
        if (!$this->cart()->removeLine($this->userId(), (int) $id)) {
            $this->notFound();
        }

        $this->back('/app/cart', 'Removed.');
    }

    public function clear(Request $request): void
    {
        $this->cart()->clear($this->userId());

        $this->back('/app/cart', 'Cart emptied.');
    }

    /**
     * Records which saved address this cart is going to.
     */
    public function setAddress(Request $request): void
    {
        $addressId = (int) $request->input('address_id', 0);
        $address = $addressId > 0 ? Address::forUserAndId($this->userId(), $addressId) : null;

        if ($addressId > 0 && $address === null) {
            $this->notFound();
        }

        $this->cart()->setAddress($this->userId(), $address === null ? null : (int) $address['id']);

        $this->back((string) $request->input('back', '/app/cart'));
    }

    /**
     * Back to the item, with what was chosen still chosen and the reason it was
     * refused above it.
     *
     * @param list<int> $optionIds
     * @param list<string> $errors
     */
    private function itemAgain(int $menuItemId, array $optionIds, int $quantity, string $notes, array $errors): void
    {
        $found = $this->cart()->itemWithGroups($menuItemId);

        if ($found === null) {
            $this->back('/app', implode(' ', $errors), 'bad');
        }

        $restaurant = Restaurant::find((int) $found['item']['restaurant_id']);

        if ($restaurant === null) {
            $this->back('/app', implode(' ', $errors), 'bad');
        }

        $this->view('app.item', array_merge(
            $this->shell('browse', (string) $found['item']['name']),
            [
                'restaurant' => $restaurant,
                'item' => $found['item'],
                'groups' => $found['groups'],
                'line' => ['option_ids' => $optionIds, 'quantity' => $quantity, 'notes' => $notes],
                'errors' => $errors,
            ]
        ));
    }

    /**
     * @return list<int>
     */
    private function optionIds(Request $request): array
    {
        $options = $request->input('options', []);

        if (!is_array($options)) {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', $options),
            static fn (int $id): bool => $id > 0
        ));
    }
}
