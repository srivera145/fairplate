-- min_select / max_select rather than min / max: MIN and MAX are SQL functions
-- and reading them back unquoted in every query is a trap.
CREATE TABLE IF NOT EXISTS item_option_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    min_select INT UNSIGNED NOT NULL DEFAULT 0,
    max_select INT UNSIGNED NOT NULL DEFAULT 1,
    required TINYINT(1) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_option_groups_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_option_groups_item (menu_item_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS item_option_groups;
