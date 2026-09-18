<?php

namespace Keel\App\Models;

class ItemOptionGroup extends Model
{
    protected const TABLE = 'item_option_groups';

    protected const COLUMNS = [
        'menu_item_id', 'name', 'min_select', 'max_select', 'required', 'sort',
    ];

    public static function forItem(int $menuItemId): array
    {
        return self::allBy('menu_item_id', $menuItemId, 'sort ASC, id ASC');
    }

    public static function options(int $groupId): array
    {
        return ItemOption::forGroup($groupId);
    }
}
