-- online means the driver is taking offers; idle means online with no active
-- order, which is what dispatch fans out to.
CREATE TABLE IF NOT EXISTS drivers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    vehicle_make VARCHAR(80) NULL,
    vehicle_model VARCHAR(80) NULL,
    vehicle_color VARCHAR(40) NULL,
    plate VARCHAR(20) NULL,
    approved TINYINT(1) NOT NULL DEFAULT 0,
    online TINYINT(1) NOT NULL DEFAULT 0,
    idle TINYINT(1) NOT NULL DEFAULT 1,
    last_lat DECIMAL(10, 7) NULL,
    last_lng DECIMAL(10, 7) NULL,
    last_seen_at DATETIME NULL,
    stripe_account_id VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_drivers_user (user_id),
    INDEX idx_drivers_online (online),
    INDEX idx_drivers_dispatchable (online, idle, approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS drivers;
