<?php

namespace Keel\App\Models;

class MenuCategory extends Model
{
    protected const TABLE = 'menu_categories';

    protected const COLUMNS = ['restaurant_id', 'name', 'description', 'sort', 'active'];

    public static function forRestaurant(int $restaurantId): array
    {
        return self::allBy('restaurant_id', $restaurantId, 'sort ASC, name ASC');
    }

    public static function items(int $categoryId): array
    {
        return MenuItem::forCategory($categoryId);
    }
}
