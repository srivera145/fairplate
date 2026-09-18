-- What real money movement needs on top of the phase-5 schema.
--
-- orders.stripe_charge_id is the charge the capture produced. Two later things
-- need it and neither can find it from the PaymentIntent alone once the money
-- has moved: a transfer's source_transaction, which is what makes the payout
-- come out of this order's own funds rather than the platform balance, and a
-- refund, which is issued against the charge.
--
-- Every Stripe write carries an idempotency key, and a key is only worth having
-- if it survives the retry that needs it — so it is a stored column with a
-- unique index rather than a string built at call time. The index does double
-- duty: it is also what stops a replayed webhook or a retried job inserting a
-- second payout row for the same transfer.
--
-- payouts.status gains 'held'. A transfer to an account whose payouts Stripe
-- has disabled must never be dropped and must never be retried into the same
-- refusal forever, so it stops in a state a person can see and a webhook can
-- release.
--
-- dispatch_offers.guaranteed_cents is the number the offer card showed. The
-- spec says a guarantee never drops after acceptance; storing what was promised
-- is what lets PayoutService check that rather than assume it.
--
-- payouts_enabled on restaurants and drivers mirrors Stripe's own flag, kept
-- current by account.updated. It defaults to 1 so that an account Stripe has
-- said nothing about yet is attempted rather than held; a missing connected
-- account is caught separately, and that is the case that actually holds.
ALTER TABLE orders
    ADD COLUMN stripe_charge_id VARCHAR(255) NULL AFTER stripe_payment_intent_id,
    ADD INDEX idx_orders_charge (stripe_charge_id);

ALTER TABLE payouts
    MODIFY COLUMN status ENUM('pending', 'paid', 'failed', 'held') NOT NULL DEFAULT 'pending',
    ADD COLUMN idempotency_key VARCHAR(255) NOT NULL AFTER type,
    ADD COLUMN reversed_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER amount_cents,
    ADD COLUMN last_error TEXT NULL AFTER attempts,
    ADD UNIQUE KEY uniq_payouts_idempotency (idempotency_key),
    ADD INDEX idx_payouts_transfer (stripe_transfer_id);

ALTER TABLE refunds
    ADD COLUMN idempotency_key VARCHAR(255) NOT NULL AFTER order_id,
    ADD COLUMN stripe_transfer_reversal_id VARCHAR(255) NULL AFTER stripe_refund_id,
    ADD COLUMN reversed_restaurant_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER processing_absorbed_cents,
    ADD UNIQUE KEY uniq_refunds_idempotency (idempotency_key),
    ADD INDEX idx_refunds_stripe (stripe_refund_id);

ALTER TABLE tip_adjustments
    ADD COLUMN idempotency_key VARCHAR(255) NOT NULL AFTER order_id,
    ADD COLUMN charge_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER service_fee_cents,
    ADD COLUMN stripe_payment_intent_id VARCHAR(255) NULL AFTER stripe_charge_id,
    ADD UNIQUE KEY uniq_tip_adjustments_idempotency (idempotency_key);

ALTER TABLE dispatch_offers
    ADD COLUMN guaranteed_cents INT UNSIGNED NULL AFTER `round`;

ALTER TABLE restaurants
    ADD COLUMN payouts_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER stripe_account_id;

ALTER TABLE drivers
    ADD COLUMN payouts_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER stripe_account_id;

-- @down
ALTER TABLE drivers
    DROP COLUMN payouts_enabled;

ALTER TABLE restaurants
    DROP COLUMN payouts_enabled;

ALTER TABLE dispatch_offers
    DROP COLUMN guaranteed_cents;

ALTER TABLE tip_adjustments
    DROP INDEX uniq_tip_adjustments_idempotency,
    DROP COLUMN stripe_payment_intent_id,
    DROP COLUMN charge_cents,
    DROP COLUMN idempotency_key;

ALTER TABLE refunds
    DROP INDEX idx_refunds_stripe,
    DROP INDEX uniq_refunds_idempotency,
    DROP COLUMN reversed_restaurant_cents,
    DROP COLUMN stripe_transfer_reversal_id,
    DROP COLUMN idempotency_key;

ALTER TABLE payouts
    DROP INDEX idx_payouts_transfer,
    DROP INDEX uniq_payouts_idempotency,
    DROP COLUMN last_error,
    DROP COLUMN reversed_cents,
    DROP COLUMN idempotency_key,
    MODIFY COLUMN status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending';

ALTER TABLE orders
    DROP INDEX idx_orders_charge,
    DROP COLUMN stripe_charge_id;
