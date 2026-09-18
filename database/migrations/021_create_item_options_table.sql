-- price_delta_cents is signed: an option can discount an item.
CREATE TABLE IF NOT EXISTS item_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_option_group_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    price_delta_cents INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_item_options_group FOREIGN KEY (item_option_group_id) REFERENCES item_option_groups(id) ON DELETE CASCADE,
    INDEX idx_item_options_group (item_option_group_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS item_options;
