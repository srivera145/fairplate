-- A membership cancelled through the Stripe portal stays active until the period
-- it was paid for runs out. Without this column the page can only say "active",
-- which is true and useless to someone who just cancelled.
ALTER TABLE memberships
    ADD COLUMN cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0 AFTER current_period_end;

-- @down
ALTER TABLE memberships
    DROP COLUMN cancel_at_period_end;
