-- One row per driver per dispatch round. `round` is backticked throughout, the
-- way rate_limits treats `key`.
CREATE TABLE IF NOT EXISTS dispatch_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    driver_id INT NOT NULL,
    `round` INT UNSIGNED NOT NULL DEFAULT 1,
    offered_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    response ENUM('pending', 'accepted', 'declined', 'expired') NOT NULL DEFAULT 'pending',
    responded_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dispatch_offers_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_dispatch_offers_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_dispatch_offer (order_id, driver_id, `round`),
    INDEX idx_dispatch_offers_driver (driver_id, response),
    INDEX idx_dispatch_offers_expiry (response, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS dispatch_offers;
