-- Separate charges and transfers: one payout row per Stripe transfer out.
-- recipient_account is the destination connected account at transfer time.
CREATE TABLE IF NOT EXISTS payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    type ENUM('restaurant', 'driver', 'driver_tip_adjust') NOT NULL,
    recipient_account VARCHAR(255) NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    stripe_transfer_id VARCHAR(255) NULL,
    status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payouts_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_payouts_order (order_id, type),
    INDEX idx_payouts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS payouts;
