-- The spec's single "value" is split so money stays integer cents and
-- percentages stay decimal: percent specials use value_pct (0.2000 = 20% off),
-- amount and price specials use value_cents.
-- days is a JSON array of ISO weekday numbers, 1 (Monday) through 7 (Sunday).
CREATE TABLE IF NOT EXISTS specials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    type ENUM('percent', 'amount', 'price') NOT NULL,
    value_pct DECIMAL(5, 4) NULL,
    value_cents INT UNSIGNED NULL,
    menu_item_id INT NULL,
    days JSON NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_specials_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_specials_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_specials_restaurant (restaurant_id, active),
    INDEX idx_specials_item (menu_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS specials;
