-- A zone is either a polygon (ray casting) or a circle (haversine). The unused
-- columns stay NULL; ZoneService reads the type to decide which test to run.
CREATE TABLE IF NOT EXISTS delivery_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    type ENUM('polygon', 'radius') NOT NULL,
    polygon JSON NULL,
    center_lat DECIMAL(10, 7) NULL,
    center_lng DECIMAL(10, 7) NULL,
    radius_m INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_delivery_zones_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS delivery_zones;
