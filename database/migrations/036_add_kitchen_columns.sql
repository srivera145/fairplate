-- What the kitchen app needs on top of the phase-2 schema.
--
-- paused_until carries the "pause for 15 minutes" switch. The kitchen tablet
-- cannot be relied on to send a resume, and there is no scheduler, so the
-- resume is a stored deadline the reader compares against rather than a job
-- that has to fire.
--
-- prep_minutes is what the kitchen promised when it accepted, and is the only
-- number dispatch has to work from before a driver is assigned.
-- driver_eta_at is written by dispatch in phase 6; the kitchen only reads it.
ALTER TABLE restaurants
    ADD COLUMN paused_until DATETIME NULL AFTER paused;

ALTER TABLE orders
    ADD COLUMN prep_minutes TINYINT UNSIGNED NULL AFTER route_miles,
    ADD COLUMN driver_eta_at DATETIME NULL AFTER driver_assigned_at;

-- @down
ALTER TABLE orders
    DROP COLUMN driver_eta_at,
    DROP COLUMN prep_minutes;

ALTER TABLE restaurants
    DROP COLUMN paused_until;
