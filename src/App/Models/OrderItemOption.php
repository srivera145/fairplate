<?php

namespace Keel\App\Models;

class OrderItemOption extends Model
{
    protected const TABLE = 'order_item_options';

    protected const COLUMNS = [
        'order_item_id', 'item_option_id', 'group_name_snapshot',
        'name_snapshot', 'price_delta_cents',
    ];

    public static function forOrderItem(int $orderItemId): array
    {
        return self::allBy('order_item_id', $orderItemId, 'id ASC');
    }
}
