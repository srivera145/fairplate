<?php

namespace Keel\App\Models;

class MenuItem extends Model
{
    protected const TABLE = 'menu_items';

    protected const COLUMNS = [
        'restaurant_id', 'menu_category_id', 'name', 'description', 'price_cents',
        'photo', 'active', 'in_stock', 'sort',
    ];

    public static function forCategory(int $categoryId): array
    {
        return self::allBy('menu_category_id', $categoryId, 'sort ASC, name ASC');
    }

    public static function forRestaurant(int $restaurantId): array
    {
        return self::allBy('restaurant_id', $restaurantId, 'sort ASC, name ASC');
    }

    /**
     * What a customer may actually add to a cart.
     */
    public static function orderable(int $restaurantId): array
    {
        return self::query(
            'SELECT * FROM menu_items
             WHERE restaurant_id = ? AND active = 1 AND in_stock = 1
             ORDER BY sort ASC, name ASC',
            [$restaurantId]
        );
    }

    public static function optionGroups(int $menuItemId): array
    {
        return ItemOptionGroup::forItem($menuItemId);
    }
}
