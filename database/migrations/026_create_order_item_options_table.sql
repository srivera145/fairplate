CREATE TABLE IF NOT EXISTS order_item_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_item_id INT NOT NULL,
    item_option_id INT NULL,
    group_name_snapshot VARCHAR(120) NOT NULL,
    name_snapshot VARCHAR(120) NOT NULL,
    price_delta_cents INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_item_options_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_item_options_option FOREIGN KEY (item_option_id) REFERENCES item_options(id) ON DELETE SET NULL,
    INDEX idx_order_item_options_item (order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS order_item_options;
