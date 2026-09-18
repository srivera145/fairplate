-- restaurant_error flips who eats the refund: the platform absorbs it by
-- default, and only a marked restaurant error reverses the restaurant's share.
CREATE TABLE IF NOT EXISTS refunds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    reason TEXT NULL,
    restaurant_error TINYINT(1) NOT NULL DEFAULT 0,
    processing_absorbed_cents INT UNSIGNED NOT NULL DEFAULT 0,
    stripe_refund_id VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_refunds_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_refunds_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS refunds;
