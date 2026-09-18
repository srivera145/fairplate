<?php

namespace Keel\App\Models;

/**
 * One open cart per customer.
 *
 * The spec allows one restaurant at a time, so restaurant_id lives on the cart
 * rather than on the line: a cart that belongs to two kitchens is not a state
 * this application has, and keeping it off the line means there is no way to
 * write one by accident.
 *
 * The tip is an intent, not an amount. A percentage has to be applied to a
 * subtotal only the server knows, so what is stored is the choice — "18%" or
 * "this many cents" — and PricingService turns it into money at quote time.
 */
class Cart extends Model
{
    protected const TABLE = 'carts';

    protected const COLUMNS = [
        'user_id', 'restaurant_id', 'address_id', 'tip_mode', 'tip_basis_points', 'tip_cents',
    ];

    public const TIP_PERCENT = 'percent';
    public const TIP_CUSTOM = 'custom';

    /** The presets the checkout screen offers, in basis points. */
    public const TIP_PRESETS = [1500, 1800, 2000];

    /** What a cart starts on, per the spec. */
    public const DEFAULT_TIP_BASIS_POINTS = 1800;

    /** A custom tip above this is a fat finger, not generosity. */
    public const MAX_CUSTOM_TIP_CENTS = 20000;

    public static function forUser(int $userId): ?array
    {
        return self::firstBy('user_id', $userId);
    }

    /**
     * This customer's cart, created empty the first time they need one.
     */
    public static function openFor(int $userId): array
    {
        $cart = self::forUser($userId);

        if ($cart !== null) {
            return $cart;
        }

        self::create([
            'user_id' => $userId,
            'tip_mode' => self::TIP_PERCENT,
            'tip_basis_points' => self::DEFAULT_TIP_BASIS_POINTS,
            'tip_cents' => 0,
        ]);

        return self::forUser($userId) ?? [];
    }

    public static function items(int $cartId): array
    {
        return CartItem::forCart($cartId);
    }
}
