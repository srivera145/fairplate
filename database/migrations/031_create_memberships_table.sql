-- An active membership zeroes the platform fee at checkout.
CREATE TABLE IF NOT EXISTS memberships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    stripe_subscription_id VARCHAR(255) NOT NULL UNIQUE,
    status VARCHAR(50) NOT NULL,
    current_period_end DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_memberships_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_memberships_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS memberships;
