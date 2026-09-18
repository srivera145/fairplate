-- max_orders NULL means the tier is open-ended. is_custom marks the top tier,
-- whose fee comes from restaurants.custom_fee_cents instead of fee_cents.
CREATE TABLE IF NOT EXISTS restaurant_fee_tiers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    min_orders INT UNSIGNED NOT NULL,
    max_orders INT UNSIGNED NULL,
    fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
    is_custom TINYINT(1) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_restaurant_fee_tiers_sort (sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS restaurant_fee_tiers;
