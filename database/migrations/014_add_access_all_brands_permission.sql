-- Migration: Staff "Access All Brands" permission
-- Description: Adds the brands.access_all permission so non-superadmin staff can be granted access
--   to the All Brands (platform) view via their role. Superadmins always have access.
-- Idempotent via the unique slug.

INSERT IGNORE INTO `op_permissions` (`slug`, `name`, `group_name`)
VALUES ('brands.access_all', 'Access All Brands view', 'people');
