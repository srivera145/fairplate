-- tax_rate and founding_discount_pct are fractions (0.0750 = 7.5%), matching
-- the settings that hold processing_pct and comparison_commission_pct.
-- custom_fee_cents is NULL until an admin sets the 501+ tier fee by hand.
CREATE TABLE IF NOT EXISTS restaurants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(160) NOT NULL UNIQUE,
    phone VARCHAR(20) NULL,
    line1 VARCHAR(255) NOT NULL,
    line2 VARCHAR(255) NULL,
    city VARCHAR(120) NOT NULL,
    state CHAR(2) NOT NULL,
    zip VARCHAR(10) NOT NULL,
    lat DECIMAL(10, 7) NULL,
    lng DECIMAL(10, 7) NULL,
    delivery_zone_id INT NULL,
    tax_rate DECIMAL(5, 4) NOT NULL DEFAULT 0.0000,
    hours JSON NULL,
    paused TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending', 'active', 'suspended') NOT NULL DEFAULT 'pending',
    stripe_account_id VARCHAR(255) NULL,
    stripe_customer_id VARCHAR(255) NULL,
    founding_discount_pct DECIMAL(5, 4) NOT NULL DEFAULT 0.0000,
    custom_fee_cents INT UNSIGNED NULL,
    logo VARCHAR(500) NULL,
    cover VARCHAR(500) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_restaurants_zone FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL,
    INDEX idx_restaurants_status (status),
    INDEX idx_restaurants_zone (delivery_zone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS restaurants;
