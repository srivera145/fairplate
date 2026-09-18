-- A tip raised within the adjust window is its own charge, grossed up the same
-- way. delta_cents is signed so a correction can be recorded.
CREATE TABLE IF NOT EXISTS tip_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    delta_cents INT NOT NULL,
    service_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
    stripe_charge_id VARCHAR(255) NULL,
    status ENUM('pending', 'succeeded', 'failed') NOT NULL DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tip_adjustments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_tip_adjustments_order (order_id),
    INDEX idx_tip_adjustments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS tip_adjustments;
