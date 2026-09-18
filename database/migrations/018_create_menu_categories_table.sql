CREATE TABLE IF NOT EXISTS menu_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    sort INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_menu_categories_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    INDEX idx_menu_categories_restaurant (restaurant_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS menu_categories;
