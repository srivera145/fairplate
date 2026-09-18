-- Every pricing knob lives here and is snapshotted onto orders at checkout.
-- `key` is backticked the way rate_limits does it. type drives Settings' cast.
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT NOT NULL,
    type ENUM('int', 'decimal', 'string', 'bool', 'json') NOT NULL DEFAULT 'string',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS settings;
