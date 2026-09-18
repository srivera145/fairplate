-- The breadcrumb trail. order_id is set while the ping belongs to an active
-- delivery, which is the only window a customer may read it in.
CREATE TABLE IF NOT EXISTS driver_locations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    driver_id INT NOT NULL,
    order_id INT NULL,
    lat DECIMAL(10, 7) NOT NULL,
    lng DECIMAL(10, 7) NOT NULL,
    recorded_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_driver_locations_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
    CONSTRAINT fk_driver_locations_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_driver_locations_driver (driver_id, recorded_at),
    INDEX idx_driver_locations_order (order_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS driver_locations;
