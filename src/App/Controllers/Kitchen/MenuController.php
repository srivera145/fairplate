<?php

namespace Keel\App\Controllers\Kitchen;

use Keel\App\Models\ItemOption;
use Keel\App\Models\ItemOptionGroup;
use Keel\App\Models\MenuCategory;
use Keel\App\Models\MenuItem;
use Keel\App\Services\ImageService;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Pricing\PricingException;
use Keel\Core\Activity;
use Keel\Core\Database;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * Categories, items, option groups and options.
 *
 * Every write here starts with authorizeRecord(). The ids in these URLs are
 * sequential integers across every restaurant on the platform, so the only
 * thing standing between one kitchen and another kitchen's menu is that check —
 * which is why it is the first line of each method rather than a filter on the
 * query, where it would be easy to leave out of the next method added.
 *
 * Prices go in and out in dollars and live in cents. Money::fromDollars is the
 * only thing that converts, and every field here that takes a price funnels
 * through priceCents() to reach it.
 *
 * Ordering is stored, not implied. Both the drag handle and the up/down buttons
 * end at the same reorder(), and the buttons exist because a drag that needs a
 * steady hand is a poor fit for a tablet in a kitchen, and because a keyboard
 * cannot drag at all.
 */
class MenuController extends KitchenController
{
    /** Which collections may be reordered, and what scopes each one. */
    private const SORTABLE = [
        'categories' => ['table' => 'menu_categories', 'parent' => 'restaurant_id'],
        'items' => ['table' => 'menu_items', 'parent' => 'menu_category_id'],
        'groups' => ['table' => 'item_option_groups', 'parent' => 'menu_item_id'],
        'options' => ['table' => 'item_options', 'parent' => 'item_option_group_id'],
    ];

    public function index(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];

        $categories = MenuCategory::forRestaurant($restaurantId);

        foreach ($categories as $index => $category) {
            $categories[$index]['items'] = MenuItem::forCategory((int) $category['id']);
        }

        $this->view('kitchen.menu', array_merge(
            $this->shell($restaurant, 'menu', 'Menu'),
            [
                'categories' => $categories,
                'photosAvailable' => ImageService::isAvailable(),
            ]
        ));
    }

    /**
     * One item, with its option groups. A separate screen because an item with
     * three option groups and a photo does not fit in a row on the menu list.
     */
    public function editItem(Request $request, string $id): void
    {
        $itemId = (int) $id;
        $this->authorizeRecord('menu_items', $itemId);

        $restaurant = $this->requireRestaurant();
        $item = MenuItem::find($itemId);
        $groups = ItemOptionGroup::forItem($itemId);

        foreach ($groups as $index => $group) {
            $groups[$index]['options'] = ItemOption::forGroup((int) $group['id']);
        }

        $this->view('kitchen.item', array_merge(
            $this->shell($restaurant, 'menu', (string) $item['name']),
            [
                'item' => $item,
                'groups' => $groups,
                'categories' => MenuCategory::forRestaurant((int) $restaurant['id']),
                'photosAvailable' => ImageService::isAvailable(),
            ]
        ));
    }

    // -- Categories ---------------------------------------------------------

    public function storeCategory(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $this->back('/kitchen/menu', 'A category needs a name.', 'bad');
        }

        $id = MenuCategory::create([
            'restaurant_id' => $restaurantId,
            'name' => $name,
            'description' => $this->nullable($request->input('description')),
            'sort' => $this->nextSort('menu_categories', 'restaurant_id', $restaurantId),
            'active' => 1,
        ]);

        Activity::log('menu.category_created', 'MenuCategory', $id, ['name' => $name]);

        $this->back('/kitchen/menu', 'Category added.');
    }

    public function updateCategory(Request $request, string $id): void
    {
        $categoryId = (int) $id;
        $this->authorizeRecord('menu_categories', $categoryId);

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $this->back('/kitchen/menu', 'A category needs a name.', 'bad');
        }

        MenuCategory::update($categoryId, [
            'name' => $name,
            'description' => $this->nullable($request->input('description')),
            'active' => $request->input('active') !== null ? 1 : 0,
        ]);

        $this->back('/kitchen/menu', 'Category saved.');
    }

    public function destroyCategory(Request $request, string $id): void
    {
        $categoryId = (int) $id;
        $this->authorizeRecord('menu_categories', $categoryId);

        // The schema cascades items away with the category, which is right for a
        // category being removed on purpose and merciless for a mis-tap, so the
        // only thing standing in the way is the count in the warning.
        MenuCategory::delete($categoryId);
        Activity::log('menu.category_deleted', 'MenuCategory', $categoryId);

        $this->back('/kitchen/menu', 'Category removed.');
    }

    // -- Items --------------------------------------------------------------

    public function storeItem(Request $request): void
    {
        $categoryId = (int) $request->input('menu_category_id', 0);
        $this->authorizeRecord('menu_categories', $categoryId);

        $category = MenuCategory::find($categoryId);
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $this->back('/kitchen/menu', 'An item needs a name.', 'bad');
        }

        $priceCents = $this->priceCents((string) $request->input('price', ''), '/kitchen/menu');

        $id = MenuItem::create([
            'restaurant_id' => (int) $category['restaurant_id'],
            'menu_category_id' => $categoryId,
            'name' => $name,
            'description' => $this->nullable($request->input('description')),
            'price_cents' => $priceCents,
            'active' => 1,
            'in_stock' => 1,
            'sort' => $this->nextSort('menu_items', 'menu_category_id', $categoryId),
        ]);

        Activity::log('menu.item_created', 'MenuItem', $id, ['name' => $name]);

        $this->back('/kitchen/menu/items/' . $id, 'Item added. Add options and a photo below.');
    }

    public function updateItem(Request $request, string $id): void
    {
        $itemId = (int) $id;
        $this->authorizeRecord('menu_items', $itemId);

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $this->back('/kitchen/menu/items/' . $itemId, 'An item needs a name.', 'bad');
        }

        $attributes = [
            'name' => $name,
            'description' => $this->nullable($request->input('description')),
            'price_cents' => $this->priceCents((string) $request->input('price', ''), '/kitchen/menu/items/' . $itemId),
            'active' => $request->input('active') !== null ? 1 : 0,
        ];

        // Moving an item to another category has to land on a category this
        // kitchen owns, which is the same check the category routes make.
        $categoryId = (int) $request->input('menu_category_id', 0);

        if ($categoryId > 0 && $categoryId !== (int) MenuItem::find($itemId)['menu_category_id']) {
            $this->authorizeRecord('menu_categories', $categoryId);

            $attributes['menu_category_id'] = $categoryId;
            $attributes['sort'] = $this->nextSort('menu_items', 'menu_category_id', $categoryId);
        }

        MenuItem::update($itemId, $attributes);

        $this->back('/kitchen/menu/items/' . $itemId, 'Item saved.');
    }

    public function destroyItem(Request $request, string $id): void
    {
        $itemId = (int) $id;
        $this->authorizeRecord('menu_items', $itemId);

        ImageService::delete(MenuItem::find($itemId)['photo'] ?? null);
        MenuItem::delete($itemId);
        Activity::log('menu.item_deleted', 'MenuItem', $itemId);

        $this->back('/kitchen/menu', 'Item removed.');
    }

    /**
     * The 86 switch.
     *
     * One tap, and it takes effect on the next customer query rather than at
     * some later sync, because MenuItem::orderable() reads in_stock directly.
     * It is on the orders screen too: the moment the kitchen runs out mid-rush
     * is the moment nobody is going to walk over to the menu screen.
     */
    public function toggleStock(Request $request, string $id): void
    {
        $itemId = (int) $id;
        $this->authorizeRecord('menu_items', $itemId);

        $item = MenuItem::find($itemId);
        $inStock = (int) $item['in_stock'] === 1 ? 0 : 1;

        MenuItem::update($itemId, ['in_stock' => $inStock]);
        Activity::log($inStock === 1 ? 'menu.item_restocked' : 'menu.item_86ed', 'MenuItem', $itemId);

        $this->back(
            $this->backTo($request, '/kitchen/menu'),
            $inStock === 1
                ? $item['name'] . ' is back on the menu.'
                : $item['name'] . ' is 86ed and hidden from customers.'
        );
    }

    public function uploadItemPhoto(Request $request, string $id): void
    {
        $itemId = (int) $id;
        $this->authorizeRecord('menu_items', $itemId);

        $item = MenuItem::find($itemId);
        $file = $_FILES['photo'] ?? null;

        if (!is_array($file)) {
            $this->back('/kitchen/menu/items/' . $itemId, 'Choose a photo first.', 'bad');
        }

        try {
            $path = ImageService::storeUpload($file, 'menu/' . (int) $item['restaurant_id']);
        } catch (\RuntimeException $exception) {
            $this->back('/kitchen/menu/items/' . $itemId, $exception->getMessage(), 'bad');
        }

        ImageService::delete($item['photo'] ?? null);
        MenuItem::update($itemId, ['photo' => $path]);

        $this->back('/kitchen/menu/items/' . $itemId, 'Photo updated.');
    }

    public function removeItemPhoto(Request $request, string $id): void
    {
        $itemId = (int) $id;
        $this->authorizeRecord('menu_items', $itemId);

        ImageService::delete(MenuItem::find($itemId)['photo'] ?? null);
        MenuItem::update($itemId, ['photo' => null]);

        $this->back('/kitchen/menu/items/' . $itemId, 'Photo removed.');
    }

    // -- Option groups and options ------------------------------------------

    public function storeGroup(Request $request, string $id): void
    {
        $itemId = (int) $id;
        $this->authorizeRecord('menu_items', $itemId);

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $this->back('/kitchen/menu/items/' . $itemId, 'An option group needs a name.', 'bad');
        }

        $maxSelect = max(1, (int) $request->input('max_select', 1));
        $minSelect = max(0, min($maxSelect, (int) $request->input('min_select', 0)));

        $groupId = ItemOptionGroup::create([
            'menu_item_id' => $itemId,
            'name' => $name,
            'min_select' => $minSelect,
            'max_select' => $maxSelect,
            'required' => $minSelect > 0 ? 1 : 0,
            'sort' => $this->nextSort('item_option_groups', 'menu_item_id', $itemId),
        ]);

        Activity::log('menu.option_group_created', 'ItemOptionGroup', $groupId, ['name' => $name]);

        $this->back('/kitchen/menu/items/' . $itemId, 'Option group added.');
    }

    public function updateGroup(Request $request, string $id): void
    {
        $groupId = (int) $id;
        $this->authorizeRecord('item_option_groups', $groupId);

        $group = ItemOptionGroup::find($groupId);
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $this->back('/kitchen/menu/items/' . (int) $group['menu_item_id'], 'An option group needs a name.', 'bad');
        }

        $maxSelect = max(1, (int) $request->input('max_select', 1));
        $minSelect = max(0, min($maxSelect, (int) $request->input('min_select', 0)));

        ItemOptionGroup::update($groupId, [
            'name' => $name,
            'min_select' => $minSelect,
            'max_select' => $maxSelect,
            'required' => $minSelect > 0 ? 1 : 0,
        ]);

        $this->back('/kitchen/menu/items/' . (int) $group['menu_item_id'], 'Option group saved.');
    }

    public function destroyGroup(Request $request, string $id): void
    {
        $groupId = (int) $id;
        $this->authorizeRecord('item_option_groups', $groupId);

        $itemId = (int) ItemOptionGroup::find($groupId)['menu_item_id'];
        ItemOptionGroup::delete($groupId);

        $this->back('/kitchen/menu/items/' . $itemId, 'Option group removed.');
    }

    public function storeOption(Request $request, string $id): void
    {
        $groupId = (int) $id;
        $this->authorizeRecord('item_option_groups', $groupId);

        $group = ItemOptionGroup::find($groupId);
        $itemId = (int) $group['menu_item_id'];
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $this->back('/kitchen/menu/items/' . $itemId, 'An option needs a name.', 'bad');
        }

        $optionId = ItemOption::create([
            'item_option_group_id' => $groupId,
            'name' => $name,
            // An option may discount as well as add, so this one accepts a
            // negative figure where an item price never would.
            'price_delta_cents' => $this->priceCents(
                (string) $request->input('price_delta', '0'),
                '/kitchen/menu/items/' . $itemId,
                true
            ),
            'active' => 1,
            'sort' => $this->nextSort('item_options', 'item_option_group_id', $groupId),
        ]);

        Activity::log('menu.option_created', 'ItemOption', $optionId, ['name' => $name]);

        $this->back('/kitchen/menu/items/' . $itemId, 'Option added.');
    }

    public function updateOption(Request $request, string $id): void
    {
        $optionId = (int) $id;
        $this->authorizeRecord('item_options', $optionId);

        $itemId = $this->itemIdForOption($optionId);
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $this->back('/kitchen/menu/items/' . $itemId, 'An option needs a name.', 'bad');
        }

        ItemOption::update($optionId, [
            'name' => $name,
            'price_delta_cents' => $this->priceCents(
                (string) $request->input('price_delta', '0'),
                '/kitchen/menu/items/' . $itemId,
                true
            ),
            'active' => $request->input('active') !== null ? 1 : 0,
        ]);

        $this->back('/kitchen/menu/items/' . $itemId, 'Option saved.');
    }

    public function destroyOption(Request $request, string $id): void
    {
        $optionId = (int) $id;
        $this->authorizeRecord('item_options', $optionId);

        $itemId = $this->itemIdForOption($optionId);
        ItemOption::delete($optionId);

        $this->back('/kitchen/menu/items/' . $itemId, 'Option removed.');
    }

    // -- Ordering -----------------------------------------------------------

    /**
     * The drop at the end of a drag: the whole collection, in its new order.
     *
     * Every id is authorized individually. A reorder is a write to rows the
     * caller named, and "they were in the list I sent" is not a permission.
     */
    public function sort(Request $request): void
    {
        $type = (string) $request->input('type', '');

        if (!isset(self::SORTABLE[$type])) {
            $this->json(['error' => 'Unknown collection.'], 422);
        }

        $order = $request->input('order', []);

        if (!is_array($order)) {
            $this->json(['error' => 'No order was sent.'], 422);
        }

        $table = self::SORTABLE[$type]['table'];
        $ids = [];

        foreach ($order as $value) {
            $recordId = (int) $value;

            if ($recordId <= 0) {
                continue;
            }

            $this->authorizeRecord($table, $recordId);
            $ids[] = $recordId;
        }

        $this->applySort($table, $ids);

        $this->json(['saved' => count($ids)]);
    }

    /**
     * One step up or down, for a tablet or a keyboard.
     */
    public function move(Request $request, string $type, string $id): void
    {
        if (!isset(self::SORTABLE[$type])) {
            $this->forbidden();
        }

        $recordId = (int) $id;
        $table = self::SORTABLE[$type]['table'];
        $parentColumn = self::SORTABLE[$type]['parent'];

        $this->authorizeRecord($table, $recordId);

        $direction = (string) $request->input('direction', 'up') === 'down' ? 1 : -1;

        $statement = Database::connection()->prepare(
            'SELECT id FROM `' . $table . '`
             WHERE `' . $parentColumn . '` = (SELECT `' . $parentColumn . '` FROM `' . $table . '` WHERE id = ?)
             ORDER BY sort ASC, id ASC'
        );
        $statement->execute([$recordId]);

        $ids = array_map('intval', array_column($statement->fetchAll(), 'id'));
        $position = array_search($recordId, $ids, true);
        $target = $position === false ? null : $position + $direction;

        if ($target !== null && $target >= 0 && $target < count($ids)) {
            [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];
            $this->applySort($table, $ids);
        }

        Response::redirect($this->backTo($request, '/kitchen/menu'));
    }

    /**
     * Writes a list of ids out as sort 0, 1, 2...
     *
     * $table is never caller input: it comes from SORTABLE, which is why it can
     * be interpolated here when nothing else in this file is.
     */
    private function applySort(string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $statement = Database::connection()->prepare(
            'UPDATE `' . $table . '` SET sort = ? WHERE id = ?'
        );

        foreach (array_values($ids) as $position => $recordId) {
            $statement->execute([$position, $recordId]);
        }
    }

    /**
     * Where a new row goes: after everything already in its parent.
     */
    private function nextSort(string $table, string $parentColumn, int $parentId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COALESCE(MAX(sort), -1) + 1 AS next FROM `' . $table . '` WHERE `' . $parentColumn . '` = ?'
        );
        $statement->execute([$parentId]);

        return (int) ($statement->fetch()['next'] ?? 0);
    }

    /**
     * A typed price as cents, or back to the form saying why not.
     */
    private function priceCents(string $dollars, string $backTo, bool $allowNegative = false): int
    {
        try {
            return Money::fromDollars($dollars, $allowNegative);
        } catch (PricingException $exception) {
            $this->back($backTo, $exception->getMessage(), 'bad');
        }
    }

    private function itemIdForOption(int $optionId): int
    {
        $group = ItemOptionGroup::find((int) ItemOption::find($optionId)['item_option_group_id']);

        return (int) $group['menu_item_id'];
    }

    /**
     * Where to land after an action that can be triggered from two screens.
     * Only the handful of kitchen paths are accepted, so the parameter cannot
     * be turned into an open redirect.
     */
    private function backTo(Request $request, string $fallback): string
    {
        $to = (string) $request->input('back', '');

        return preg_match('#^/kitchen(/[A-Za-z0-9/_-]*)?$#', $to) === 1 ? $to : $fallback;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
