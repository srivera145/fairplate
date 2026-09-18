-- The cart lives on the server, not in the browser, because it is the thing the
-- price is computed from and a customer who switches phones mid-order should
-- find it waiting.
--
-- Note what is NOT here: prices. A cart row carries ids, a quantity and a note,
-- and nothing else. Every cent is read from menu_items and item_options at quote
-- time, which is what makes "the client never sends prices" true by construction
-- rather than by validation.
--
-- The tip is stored as an intent instead of an amount. A percent tip is a
-- percentage of a subtotal the server works out; only a typed custom tip is a
-- number the customer chose, and that one is genuinely theirs to choose.
CREATE TABLE IF NOT EXISTS carts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    restaurant_id INT NULL,
    address_id INT NULL,
    tip_mode ENUM('percent', 'custom') NOT NULL DEFAULT 'percent',
    tip_basis_points INT UNSIGNED NOT NULL DEFAULT 1800,
    tip_cents INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_carts_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE SET NULL,
    CONSTRAINT fk_carts_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_carts_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cart_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    notes VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_menu_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_cart_items_cart (cart_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cart_item_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cart_item_id INT NOT NULL,
    item_option_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cart_item_options_item FOREIGN KEY (cart_item_id) REFERENCES cart_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_item_options_option FOREIGN KEY (item_option_id) REFERENCES item_options(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_cart_item_option (cart_item_id, item_option_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS cart_item_options;
DROP TABLE IF EXISTS cart_items;
DROP TABLE IF EXISTS carts;
