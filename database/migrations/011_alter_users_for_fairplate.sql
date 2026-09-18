-- FairPlate identity lives on the Keel users table: login is by phone OTP, so
-- email becomes optional and phone becomes the unique handle. Keel's own
-- organization role moves to org_role so `role` can carry the four FairPlate
-- roles the route groups key off.
ALTER TABLE users
    CHANGE COLUMN role org_role VARCHAR(20) NOT NULL DEFAULT 'owner',
    ADD COLUMN role ENUM('customer', 'restaurant_staff', 'driver', 'admin') NOT NULL DEFAULT 'customer' AFTER organization_id,
    ADD COLUMN phone VARCHAR(20) NULL AFTER email,
    MODIFY COLUMN email VARCHAR(255) NULL,
    ADD UNIQUE KEY uniq_users_phone (phone),
    ADD INDEX idx_users_role (role);

-- @down
ALTER TABLE users
    DROP INDEX idx_users_role,
    DROP INDEX uniq_users_phone,
    DROP COLUMN phone,
    DROP COLUMN role,
    CHANGE COLUMN org_role role VARCHAR(20) NOT NULL DEFAULT 'owner',
    MODIFY COLUMN email VARCHAR(255) NOT NULL;
