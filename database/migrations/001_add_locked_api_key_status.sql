-- Add 'locked' status to op_api_keys so keys can be suspended without revoking them.
-- Additive ENUM widening: existing 'active'/'revoked' rows and app code are unaffected.

ALTER TABLE `op_api_keys` MODIFY COLUMN `status` ENUM('active','locked','revoked') NOT NULL DEFAULT 'active';
