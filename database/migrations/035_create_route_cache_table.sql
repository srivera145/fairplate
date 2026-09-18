-- Driving distances already paid for, keyed by the two endpoints rounded to
-- four decimals (about eleven metres). A restaurant delivers to the same few
-- blocks all day, so this turns hundreds of Routes API calls into one.
-- Only real API answers land here; a haversine fallback is never cached.
-- miles matches orders.route_miles so a cached quote and a stored route agree.
CREATE TABLE IF NOT EXISTS route_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(80) NOT NULL,
    miles DECIMAL(6, 2) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_route_cache_key (cache_key),
    INDEX idx_route_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS route_cache;
