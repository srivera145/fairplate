-- What the driver app needs on top of the phase-4 schema.
--
-- delivery_photo is proof of drop-off. The spec makes it mandatory only when
-- the customer's instructions say to leave the food at the door, because that
-- is the case where nobody signs for it; every other delivery may still carry
-- one and most will.
--
-- driver_locations already indexes (driver_id, recorded_at), which answers "where
-- is this driver" but cannot answer "everything older than thirty days" — a
-- composite index is no use to a query that does not name the leading column.
-- PurgeDriverLocationsJob asks exactly that question once a day against the
-- largest table FairPlate writes, so it gets an index of its own.
ALTER TABLE orders
    ADD COLUMN delivery_photo VARCHAR(500) NULL AFTER delivered_at;

ALTER TABLE driver_locations
    ADD INDEX idx_driver_locations_recorded (recorded_at);

-- @down
ALTER TABLE driver_locations
    DROP INDEX idx_driver_locations_recorded;

ALTER TABLE orders
    DROP COLUMN delivery_photo;
