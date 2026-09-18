-- One saved address is the one checkout starts on. A flag rather than a column
-- on users: the address book is per user already, and a users.default_address_id
-- would need a foreign key back to a table that points at users, which makes
-- deleting either end awkward for no gain.
ALTER TABLE addresses
    ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER instructions,
    ADD INDEX idx_addresses_user_default (user_id, is_default);

-- @down
ALTER TABLE addresses
    DROP INDEX idx_addresses_user_default,
    DROP COLUMN is_default;
