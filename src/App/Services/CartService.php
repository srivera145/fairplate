<?php

namespace Keel\App\Services;

use Keel\App\Models\Cart;
use Keel\App\Models\CartItem;
use Keel\App\Models\CartItemOption;
use Keel\App\Models\ItemOption;
use Keel\App\Models\ItemOptionGroup;
use Keel\App\Models\MenuItem;
use Keel\App\Models\OrderItem;
use Keel\App\Models\Restaurant;
use Keel\App\Services\Pricing\PricingService;

/**
 * The customer's cart: what is in it, whether it may be, and what it costs.
 *
 * Two rules shape everything here.
 *
 * The cart stores ids, never money. A line is a menu item, a quantity, some
 * option ids and a note; the price is looked up from the menu every time the
 * cart is read. That is the whole answer to a tampered client — there is no
 * price field to tamper with, and a page that posts one is posting a key that
 * nothing reads.
 *
 * A cart belongs to one restaurant. The spec says so, dispatch assumes it, and
 * the driver payout is priced from one pickup point. Adding from a second
 * kitchen is not an error to be shouted about; it is a question, so add() hands
 * back a conflict the screen turns into "start a new cart?".
 *
 * Nothing here adds, multiplies or rounds a cent. Line totals and the subtotal
 * come back from PricingService, which is where specials are applied and where
 * the spec allows the arithmetic to live.
 */
class CartService
{
    /** Why a line can no longer be ordered. */
    public const PROBLEM_ITEM_GONE = 'This item is no longer on the menu.';
    public const PROBLEM_ITEM_UNAVAILABLE = 'This item is sold out right now.';
    public const PROBLEM_OPTION_GONE = 'One of the choices on this item is no longer offered.';

    public function __construct(private readonly ?PricingService $pricing = null)
    {
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * The cart as a screen needs it: the row, its lines with live prices, the
     * restaurant, and the priced subtotal.
     *
     * @return array{
     *     cart: array<string, mixed>,
     *     restaurant: array<string, mixed>|null,
     *     lines: list<array<string, mixed>>,
     *     count: int,
     *     subtotal_cents: int,
     *     discount_cents: int,
     *     has_problems: bool
     * }
     */
    public function summary(int $userId): array
    {
        $cart = Cart::openFor($userId);
        $restaurantId = $cart['restaurant_id'] ?? null;
        $restaurant = $restaurantId === null ? null : Restaurant::find((int) $restaurantId);
        $lines = $this->lines((int) $cart['id']);

        $priced = $restaurant === null || $lines === []
            ? ['subtotal_cents' => 0, 'discount_cents' => 0, 'items' => []]
            : $this->pricing()->subtotal(['items' => $this->pricingItems($lines)], $restaurant);

        foreach (array_keys($lines) as $index) {
            $lines[$index]['line_cents'] = (int) ($priced['items'][$index]['line_cents'] ?? 0);
            $lines[$index]['discount_cents'] = (int) ($priced['items'][$index]['discount_cents'] ?? 0);
        }

        $problems = array_filter($lines, static fn (array $line): bool => $line['problem'] !== null);

        return [
            'cart' => $cart,
            'restaurant' => $restaurant,
            'lines' => $lines,
            'count' => array_sum(array_map(static fn (array $line): int => $line['quantity'], $lines)),
            'subtotal_cents' => (int) $priced['subtotal_cents'],
            'discount_cents' => (int) $priced['discount_cents'],
            'has_problems' => $problems !== [],
        ];
    }

    /**
     * How many items are in the cart, for the badge in the header.
     */
    public function count(int $userId): int
    {
        $cart = Cart::forUser($userId);

        if ($cart === null) {
            return 0;
        }

        $total = 0;

        foreach (CartItem::forCart((int) $cart['id']) as $line) {
            $total += (int) $line['quantity'];
        }

        return $total;
    }

    /**
     * Every line, with the menu read fresh.
     *
     * A line whose item was deleted, hidden or 86'd since it was added keeps its
     * place and carries a `problem` rather than quietly disappearing: a customer
     * about to pay deserves to be told what changed.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(int $cartId): array
    {
        $lines = [];

        foreach (CartItem::forCart($cartId) as $row) {
            $item = MenuItem::find((int) $row['menu_item_id']);
            $options = $this->hydrateOptions((int) $row['id']);

            $problem = match (true) {
                $item === null => self::PROBLEM_ITEM_GONE,
                (int) $item['active'] !== 1 => self::PROBLEM_ITEM_UNAVAILABLE,
                (int) $item['in_stock'] !== 1 => self::PROBLEM_ITEM_UNAVAILABLE,
                $options['missing'] !== [] => self::PROBLEM_OPTION_GONE,
                default => null,
            };

            $lines[] = [
                'id' => (int) $row['id'],
                'menu_item_id' => (int) $row['menu_item_id'],
                'name' => (string) ($item['name'] ?? 'Removed item'),
                'photo' => $item['photo'] ?? null,
                'quantity' => (int) $row['quantity'],
                'notes' => (string) ($row['notes'] ?? ''),
                'unit_price_cents' => (int) ($item['price_cents'] ?? 0),
                'options' => $options['options'],
                'option_ids' => array_map(static fn (array $option): int => $option['id'], $options['options']),
                'problem' => $problem,
                'line_cents' => 0,
                'discount_cents' => 0,
            ];
        }

        return $lines;
    }

    /**
     * The cart as PricingService::quote() wants it.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public function pricingItems(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'menu_item_id' => $line['menu_item_id'],
            'quantity' => $line['quantity'],
            'price_cents' => $line['unit_price_cents'],
            'options' => array_map(
                static fn (array $option): array => ['price_delta_cents' => $option['price_delta_cents']],
                $line['options']
            ),
        ], $lines);
    }

    /**
     * The tip the cart is set to, as the key PricingService reads.
     *
     * A percentage stays a percentage all the way into the pricing service,
     * which applies it to a subtotal it worked out itself.
     *
     * @return array{tip_cents: int}|array{tip_basis_points: int}
     */
    public function tipFor(array $cart): array
    {
        if ((string) ($cart['tip_mode'] ?? Cart::TIP_PERCENT) === Cart::TIP_CUSTOM) {
            return ['tip_cents' => (int) ($cart['tip_cents'] ?? 0)];
        }

        return ['tip_basis_points' => (int) ($cart['tip_basis_points'] ?? Cart::DEFAULT_TIP_BASIS_POINTS)];
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * Puts an item in the cart.
     *
     * @param list<int> $optionIds
     * @return array{ok: bool, errors: list<string>, conflict: array<string, mixed>|null, line_id: int|null}
     */
    public function add(
        int $userId,
        int $menuItemId,
        int $quantity,
        array $optionIds,
        string $notes = '',
        bool $replaceCart = false
    ): array {
        $item = MenuItem::find($menuItemId);

        if ($item === null || (int) $item['active'] !== 1) {
            return $this->failure([self::PROBLEM_ITEM_GONE]);
        }

        if ((int) $item['in_stock'] !== 1) {
            return $this->failure([self::PROBLEM_ITEM_UNAVAILABLE]);
        }

        $restaurant = Restaurant::find((int) $item['restaurant_id']);

        if ($restaurant === null || (string) $restaurant['status'] !== Restaurant::STATUS_ACTIVE) {
            return $this->failure(['This restaurant is not taking orders right now.']);
        }

        $errors = $this->validateSelection($menuItemId, $optionIds);

        if ($errors !== []) {
            return $this->failure($errors);
        }

        $cart = Cart::openFor($userId);
        $cartId = (int) $cart['id'];
        $currentRestaurantId = $cart['restaurant_id'] === null ? null : (int) $cart['restaurant_id'];
        $hasItems = CartItem::forCart($cartId) !== [];

        if ($hasItems && $currentRestaurantId !== null && $currentRestaurantId !== (int) $item['restaurant_id']) {
            if (!$replaceCart) {
                return [
                    'ok' => false,
                    'errors' => [],
                    'conflict' => [
                        'current' => Restaurant::find($currentRestaurantId),
                        'wanted' => $restaurant,
                    ],
                    'line_id' => null,
                ];
            }

            CartItem::clearCart($cartId);
        }

        Cart::update($cartId, ['restaurant_id' => (int) $item['restaurant_id']]);

        $quantity = $this->clampQuantity($quantity);
        $notes = $this->clampNotes($notes);
        $existing = $this->matchingLine($cartId, $menuItemId, $optionIds, $notes);

        if ($existing !== null) {
            CartItem::update(
                (int) $existing['id'],
                ['quantity' => $this->clampQuantity((int) $existing['quantity'] + $quantity)]
            );

            return ['ok' => true, 'errors' => [], 'conflict' => null, 'line_id' => (int) $existing['id']];
        }

        $lineId = CartItem::create([
            'cart_id' => $cartId,
            'menu_item_id' => $menuItemId,
            'quantity' => $quantity,
            'notes' => $notes === '' ? null : $notes,
        ]);

        foreach (array_unique(array_map('intval', $optionIds)) as $optionId) {
            CartItemOption::create(['cart_item_id' => $lineId, 'item_option_id' => $optionId]);
        }

        return ['ok' => true, 'errors' => [], 'conflict' => null, 'line_id' => $lineId];
    }

    /**
     * Changes a line's quantity. Zero removes it, which is what a stepper wound
     * down to nothing means.
     */
    public function setQuantity(int $userId, int $lineId, int $quantity): bool
    {
        $cart = Cart::forUser($userId);

        if ($cart === null) {
            return false;
        }

        $line = CartItem::forCartAndId((int) $cart['id'], $lineId);

        if ($line === null) {
            return false;
        }

        if ($quantity <= 0) {
            CartItem::delete($lineId);
            $this->forgetRestaurantIfEmpty((int) $cart['id']);

            return true;
        }

        return CartItem::update($lineId, ['quantity' => $this->clampQuantity($quantity)]);
    }

    public function removeLine(int $userId, int $lineId): bool
    {
        return $this->setQuantity($userId, $lineId, 0);
    }

    /**
     * Empties the cart but keeps the row, so the tip choice survives.
     */
    public function clear(int $userId): void
    {
        $cart = Cart::forUser($userId);

        if ($cart === null) {
            return;
        }

        CartItem::clearCart((int) $cart['id']);
        Cart::update((int) $cart['id'], ['restaurant_id' => null]);
    }

    /**
     * Records the tip the customer chose. A percentage stays a percentage.
     */
    public function setTip(int $userId, string $mode, int $value): void
    {
        $cart = Cart::openFor($userId);

        if ($mode === Cart::TIP_CUSTOM) {
            Cart::update((int) $cart['id'], [
                'tip_mode' => Cart::TIP_CUSTOM,
                'tip_cents' => max(0, min($value, Cart::MAX_CUSTOM_TIP_CENTS)),
            ]);

            return;
        }

        Cart::update((int) $cart['id'], [
            'tip_mode' => Cart::TIP_PERCENT,
            'tip_basis_points' => max(0, min($value, 10000)),
        ]);
    }

    public function setAddress(int $userId, ?int $addressId): void
    {
        $cart = Cart::openFor($userId);

        Cart::update((int) $cart['id'], ['address_id' => $addressId]);
    }

    /**
     * Rebuilds the cart from a past order.
     *
     * Menus move, so this is a best effort and says so: anything removed,
     * hidden, 86'd, re-optioned or repriced since is reported back rather than
     * silently dropped or silently substituted.
     *
     * @return array{added: int, warnings: list<string>, restaurant: array<string, mixed>|null}
     */
    public function reorder(int $userId, array $order): array
    {
        $restaurant = Restaurant::find((int) $order['restaurant_id']);

        if ($restaurant === null) {
            return ['added' => 0, 'warnings' => ['That restaurant is no longer on FairPlate.'], 'restaurant' => null];
        }

        $cart = Cart::openFor($userId);
        CartItem::clearCart((int) $cart['id']);
        Cart::update((int) $cart['id'], ['restaurant_id' => (int) $restaurant['id']]);

        $added = 0;
        $warnings = [];

        foreach (OrderItem::forOrder((int) $order['id']) as $orderItem) {
            $name = (string) $orderItem['name_snapshot'];
            $menuItemId = $orderItem['menu_item_id'] === null ? 0 : (int) $orderItem['menu_item_id'];
            $item = $menuItemId > 0 ? MenuItem::find($menuItemId) : null;

            if ($item === null || (int) $item['active'] !== 1) {
                $warnings[] = $name . ' is no longer on the menu.';
                continue;
            }

            if ((int) $item['in_stock'] !== 1) {
                $warnings[] = $name . ' is sold out right now.';
                continue;
            }

            [$optionIds, $missingOptions] = $this->resurrectOptions($menuItemId, (int) $orderItem['id']);

            foreach ($missingOptions as $missing) {
                $warnings[] = $name . ': "' . $missing . '" is no longer offered.';
            }

            if ((int) $item['price_cents'] !== (int) $orderItem['unit_price_cents']) {
                $warnings[] = $name . ' has changed price since your last order.';
            }

            $result = $this->add(
                $userId,
                $menuItemId,
                (int) $orderItem['quantity'],
                $optionIds,
                (string) ($orderItem['notes'] ?? ''),
                true
            );

            if ($result['ok']) {
                $added++;
                continue;
            }

            $warnings[] = $name . ': ' . implode(' ', $result['errors']);
        }

        if ($added === 0) {
            $this->clear($userId);
        }

        return ['added' => $added, 'warnings' => $warnings, 'restaurant' => $restaurant];
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    /**
     * Whether these options are a legal choice for this item.
     *
     * The item sheet enforces the same rules in the browser, which is a courtesy
     * rather than a control: this is the copy that decides.
     *
     * @param list<int> $optionIds
     * @return list<string>
     */
    public function validateSelection(int $menuItemId, array $optionIds): array
    {
        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
        $errors = [];
        $known = [];

        foreach ($this->groupsFor($menuItemId) as $group) {
            $chosen = 0;

            foreach ($group['options'] as $option) {
                $known[] = (int) $option['id'];

                if (in_array((int) $option['id'], $optionIds, true)) {
                    $chosen++;
                }
            }

            $minimum = max((int) $group['min_select'], (int) $group['required'] === 1 ? 1 : 0);
            $maximum = (int) $group['max_select'];
            $name = (string) $group['name'];

            if ($chosen < $minimum) {
                $errors[] = $minimum === 1
                    ? 'Choose an option for "' . $name . '".'
                    : 'Choose at least ' . $minimum . ' options for "' . $name . '".';
            }

            if ($maximum > 0 && $chosen > $maximum) {
                $errors[] = $maximum === 1
                    ? 'Only one option can be chosen for "' . $name . '".'
                    : 'Choose at most ' . $maximum . ' options for "' . $name . '".';
            }
        }

        if (array_diff($optionIds, $known) !== []) {
            $errors[] = 'One of the choices is not offered on this item.';
        }

        return $errors;
    }

    /**
     * An item with its option groups and their live options, which is what both
     * the item sheet and this class's validation read.
     *
     * @return array{item: array<string, mixed>, groups: list<array<string, mixed>>}|null
     */
    public function itemWithGroups(int $menuItemId): ?array
    {
        $item = MenuItem::find($menuItemId);

        if ($item === null) {
            return null;
        }

        return ['item' => $item, 'groups' => $this->groupsFor($menuItemId)];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function groupsFor(int $menuItemId): array
    {
        $groups = [];

        foreach (ItemOptionGroup::forItem($menuItemId) as $group) {
            $group['options'] = ItemOption::activeForGroup((int) $group['id']);
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * The chosen options for a cart line, plus the ids that no longer resolve.
     *
     * @return array{options: list<array<string, mixed>>, missing: list<int>}
     */
    private function hydrateOptions(int $cartItemId): array
    {
        $options = [];
        $missing = [];

        foreach (CartItemOption::forCartItem($cartItemId) as $row) {
            $optionId = (int) $row['item_option_id'];
            $option = ItemOption::find($optionId);

            if ($option === null || (int) $option['active'] !== 1) {
                $missing[] = $optionId;
                continue;
            }

            $group = ItemOptionGroup::find((int) $option['item_option_group_id']);

            $options[] = [
                'id' => $optionId,
                'name' => (string) $option['name'],
                'group_name' => (string) ($group['name'] ?? ''),
                'price_delta_cents' => (int) $option['price_delta_cents'],
            ];
        }

        return ['options' => $options, 'missing' => $missing];
    }

    /**
     * The order's chosen options, matched back onto the live menu by id.
     *
     * @return array{0: list<int>, 1: list<string>}
     */
    private function resurrectOptions(int $menuItemId, int $orderItemId): array
    {
        $available = [];

        foreach ($this->groupsFor($menuItemId) as $group) {
            foreach ($group['options'] as $option) {
                $available[(int) $option['id']] = true;
            }
        }

        $optionIds = [];
        $missing = [];

        foreach (OrderItem::options($orderItemId) as $chosen) {
            $optionId = $chosen['item_option_id'] === null ? 0 : (int) $chosen['item_option_id'];

            if ($optionId > 0 && isset($available[$optionId])) {
                $optionIds[] = $optionId;
                continue;
            }

            $missing[] = (string) $chosen['name_snapshot'];
        }

        return [$optionIds, $missing];
    }

    /**
     * An identical line already in the cart, so "add two more" bumps a quantity
     * instead of growing a second row that reads the same.
     *
     * @param list<int> $optionIds
     */
    private function matchingLine(int $cartId, int $menuItemId, array $optionIds, string $notes): ?array
    {
        $wanted = array_values(array_unique(array_map('intval', $optionIds)));
        sort($wanted);

        foreach (CartItem::forCart($cartId) as $line) {
            if ((int) $line['menu_item_id'] !== $menuItemId) {
                continue;
            }

            if (trim((string) ($line['notes'] ?? '')) !== $notes) {
                continue;
            }

            $have = CartItemOption::optionIdsFor((int) $line['id']);
            sort($have);

            if ($have === $wanted) {
                return $line;
            }
        }

        return null;
    }

    private function forgetRestaurantIfEmpty(int $cartId): void
    {
        if (CartItem::forCart($cartId) === []) {
            Cart::update($cartId, ['restaurant_id' => null]);
        }
    }

    private function clampQuantity(int $quantity): int
    {
        return max(1, min($quantity, CartItem::MAX_QUANTITY));
    }

    private function clampNotes(string $notes): string
    {
        return trim(mb_substr(trim($notes), 0, 255));
    }

    /**
     * @param list<string> $errors
     * @return array{ok: bool, errors: list<string>, conflict: null, line_id: null}
     */
    private function failure(array $errors): array
    {
        return ['ok' => false, 'errors' => $errors, 'conflict' => null, 'line_id' => null];
    }

    private function pricing(): PricingService
    {
        return $this->pricing ?? new PricingService();
    }
}
