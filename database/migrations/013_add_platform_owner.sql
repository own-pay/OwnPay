-- Migration: Platform-owner row for the "All Brands" scope
-- Description: Adds an is_platform flag to op_merchants and seeds the reserved
--   "All Brands (Platform)" record. This record owns All-Brands-scoped operational
--   data (data created under an All-Brands API key, or in the All Brands admin view),
--   which stays readable only by the All Brands view. It is NOT a selectable brand:
--   it is excluded from the brand switcher / brand listings.
-- Idempotent: INSERT IGNORE keys off the unique slug/uuid/email. (The ADD COLUMN is
--   applied conditionally by the runner; re-importing schema.sql already includes the column.)

ALTER TABLE `op_merchants`
  ADD COLUMN `is_platform` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

INSERT IGNORE INTO `op_merchants`
  (`uuid`, `name`, `slug`, `email`, `timezone`, `default_currency`, `status`, `is_platform`, `created_at`, `updated_at`)
VALUES
  ('00000000-0000-4000-8000-0000000000aa', 'All Brands (Platform)', '__platform__', 'platform@ownpay.local', 'UTC', 'USD', 'active', 1, NOW(6), NOW(6));
