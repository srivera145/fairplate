-- price_cents is the in-store price. active is the restaurant hiding the item;
-- in_stock is the item being temporarily 86'd.
CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    menu_category_id INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    price_cents INT UNSIGNED NOT NULL,
    photo VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    in_stock TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_menu_items_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_items_category FOREIGN KEY (menu_category_id) REFERENCES menu_categories(id) ON DELETE CASCADE,
    INDEX idx_menu_items_category (menu_category_id, sort),
    INDEX idx_menu_items_restaurant (restaurant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS menu_items;
