<?php

namespace Keel\App\Models;

class OrderItem extends Model
{
    protected const TABLE = 'order_items';

    protected const COLUMNS = [
        'order_id', 'menu_item_id', 'name_snapshot', 'unit_price_cents',
        'quantity', 'line_total_cents', 'notes',
    ];

    public static function forOrder(int $orderId): array
    {
        return self::allBy('order_id', $orderId, 'id ASC');
    }

    public static function options(int $orderItemId): array
    {
        return OrderItemOption::forOrderItem($orderItemId);
    }

    /**
     * Subtotal straight from the stored line totals, in cents.
     */
    public static function subtotalCents(int $orderId): int
    {
        $row = self::queryOne(
            'SELECT COALESCE(SUM(line_total_cents), 0) AS subtotal FROM order_items WHERE order_id = ?',
            [$orderId]
        );

        return (int) ($row['subtotal'] ?? 0);
    }
}
