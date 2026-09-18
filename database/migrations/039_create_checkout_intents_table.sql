-- What the webhook needs to build an order, frozen at the moment the card was
-- authorized.
--
-- The order is created by payment_intent.amount_capturable_updated and by
-- nothing else, so the webhook has to be able to build it alone, from the
-- server's own numbers, with no browser in the conversation. Stripe metadata is
-- too small to carry a cart, so the cart and the quote are written here and the
-- payment intent id is the key back to them.
--
-- authorized_cents is the amount the PaymentIntent was created for. The webhook
-- compares it against what Stripe says is capturable and refuses the pair if
-- they disagree, which is the check that makes a tampered client harmless.
CREATE TABLE IF NOT EXISTS checkout_intents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    restaurant_id INT NOT NULL,
    address_id INT NULL,
    stripe_payment_intent_id VARCHAR(255) NOT NULL,
    address_snapshot JSON NOT NULL,
    cart_snapshot JSON NOT NULL,
    quote_snapshot JSON NOT NULL,
    route_miles DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    is_member TINYINT(1) NOT NULL DEFAULT 0,
    estimate_cents INT UNSIGNED NOT NULL DEFAULT 0,
    authorized_cents INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('pending', 'placed', 'abandoned') NOT NULL DEFAULT 'pending',
    order_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_checkout_intents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_checkout_intents_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_checkout_intents_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_checkout_intents_payment_intent (stripe_payment_intent_id),
    INDEX idx_checkout_intents_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @down
DROP TABLE IF EXISTS checkout_intents;
