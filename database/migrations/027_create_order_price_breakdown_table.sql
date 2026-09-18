-- One row per pricing stage. settings_snapshot freezes every pricing setting
-- used, so a later admin change can never restate an existing order.
-- total_cents is the customer charge; service_fee_cents is the gross-up.
CREATE TABLE IF NOT EXISTS order_price_breakdown (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    stage ENUM('estimate', 'authorized', 'final') NOT NULL,
    subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0,
    tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
    driver_base_cents INT UNSIGNED NOT NULL DEFAULT 0,
    driver_mileage_cents INT UNSIGNED NOT NULL DEFAULT 0,
    driver_guaranteed_cents INT UNSIGNED NOT NULL DEFAULT 0,
    wait_pay_cents INT UNSIGNED NOT NULL DEFAULT 0,
    tip_cents INT UNSIGNED NOT NULL DEFAULT 0,
    platform_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
    service_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
    total_cents INT UNSIGNED NOT NULL DEFAULT 0,
    settings_snapshot JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_price_breakdown_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_price_breakdown_stage (order_id, stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS order_price_breakdown;
