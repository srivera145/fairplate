-- Stripe promises at-least-once delivery, which means a retry after a timeout
-- looks exactly like a second order. The unique key on the event id is what
-- makes "handle this once" a database guarantee instead of a hope: the handler
-- claims the row first and does the work only if the insert was its own.
CREATE TABLE IF NOT EXISTS webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
    event_id VARCHAR(255) NOT NULL,
    type VARCHAR(120) NOT NULL,
    handled_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_webhook_events_event (provider, event_id),
    INDEX idx_webhook_events_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS webhook_events;
