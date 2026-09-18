<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class CartItem extends Model
{
    protected const TABLE = 'cart_items';

    protected const COLUMNS = ['cart_id', 'menu_item_id', 'quantity', 'notes'];

    /** More than this of one thing is a typo or a prank, not an order. */
    public const MAX_QUANTITY = 25;

    public static function forCart(int $cartId): array
    {
        return self::allBy('cart_id', $cartId, 'id ASC');
    }

    public static function forCartAndId(int $cartId, int $itemId): ?array
    {
        return self::queryOne(
            'SELECT * FROM cart_items WHERE id = ? AND cart_id = ? LIMIT 1',
            [$itemId, $cartId]
        );
    }

    public static function options(int $cartItemId): array
    {
        return CartItemOption::forCartItem($cartItemId);
    }

    public static function clearCart(int $cartId): void
    {
        $statement = Database::connection()->prepare('DELETE FROM cart_items WHERE cart_id = ?');
        $statement->execute([$cartId]);
    }
}
