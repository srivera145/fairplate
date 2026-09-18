-- period is the America/New_York calendar month being billed, as YYYY-MM.
-- savings_cents is signed: commission_equiv_cents minus what the restaurant
-- actually paid, which can go negative in a heavy month.
CREATE TABLE IF NOT EXISTS restaurant_monthly_statements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    period CHAR(7) NOT NULL,
    orders INT UNSIGNED NOT NULL DEFAULT 0,
    tier_id INT NULL,
    fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
    discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    commission_equiv_cents INT UNSIGNED NOT NULL DEFAULT 0,
    savings_cents INT NOT NULL DEFAULT 0,
    stripe_invoice_id VARCHAR(255) NULL,
    status ENUM('draft', 'awaiting_custom_fee', 'invoiced', 'paid', 'void') NOT NULL DEFAULT 'draft',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_statements_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_statements_tier FOREIGN KEY (tier_id) REFERENCES restaurant_fee_tiers(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_statement_period (restaurant_id, period),
    INDEX idx_statements_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS restaurant_monthly_statements;
