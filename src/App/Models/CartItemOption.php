<?php

namespace Keel\App\Models;

class CartItemOption extends Model
{
    protected const TABLE = 'cart_item_options';

    protected const COLUMNS = ['cart_item_id', 'item_option_id'];

    public static function forCartItem(int $cartItemId): array
    {
        return self::allBy('cart_item_id', $cartItemId, 'id ASC');
    }

    /**
     * @return list<int>
     */
    public static function optionIdsFor(int $cartItemId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['item_option_id'],
            self::forCartItem($cartItemId)
        );
    }
}
