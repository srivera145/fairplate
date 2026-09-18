<?php

namespace Keel\App\Models;

class ItemOption extends Model
{
    protected const TABLE = 'item_options';

    protected const COLUMNS = ['item_option_group_id', 'name', 'price_delta_cents', 'active', 'sort'];

    public static function forGroup(int $groupId): array
    {
        return self::allBy('item_option_group_id', $groupId, 'sort ASC, id ASC');
    }

    public static function activeForGroup(int $groupId): array
    {
        return self::query(
            'SELECT * FROM item_options WHERE item_option_group_id = ? AND active = 1 ORDER BY sort ASC, id ASC',
            [$groupId]
        );
    }
}
