-- address_snapshot freezes the delivery address at checkout; the customer
-- editing the address book later must not move a placed order.
-- route_miles is the restaurant-to-customer distance, locked at checkout.
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    restaurant_id INT NOT NULL,
    driver_id INT NULL,
    address_snapshot JSON NOT NULL,
    status ENUM(
        'placed',
        'accepted',
        'rejected',
        'ready',
        'driver_assigned',
        'arrived_at_restaurant',
        'picked_up',
        'arrived_at_customer',
        'delivered',
        'cancelled',
        'needs_attention'
    ) NOT NULL DEFAULT 'placed',
    route_miles DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    placed_at DATETIME NULL,
    accepted_at DATETIME NULL,
    rejected_at DATETIME NULL,
    ready_at DATETIME NULL,
    driver_assigned_at DATETIME NULL,
    arrived_at_restaurant_at DATETIME NULL,
    picked_up_at DATETIME NULL,
    arrived_at_customer_at DATETIME NULL,
    delivered_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    needs_attention_at DATETIME NULL,
    authorized_cents INT UNSIGNED NULL,
    captured_cents INT UNSIGNED NULL,
    stripe_payment_intent_id VARCHAR(255) NULL,
    stripe_fee_cents INT UNSIGNED NULL,
    is_member_order TINYINT(1) NOT NULL DEFAULT 0,
    cancel_reason TEXT NULL,
    reject_reason TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
    INDEX idx_orders_status (status),
    INDEX idx_orders_customer (customer_id, created_at),
    INDEX idx_orders_restaurant (restaurant_id, status),
    INDEX idx_orders_driver (driver_id, status),
    INDEX idx_orders_payment_intent (stripe_payment_intent_id),
    INDEX idx_orders_delivered (delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS orders;
