-- Migration: Add missing UI columns
-- Description: Add require_address on op_payment_links, color/initials/description on op_merchants, and ip_address on op_transactions.

ALTER TABLE `op_payment_links` ADD COLUMN `require_address` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_amount_fixed`;

ALTER TABLE `op_merchants` 
  ADD COLUMN `color` VARCHAR(7) DEFAULT NULL AFTER `logo_path`,
  ADD COLUMN `initials` VARCHAR(5) DEFAULT NULL AFTER `color`,
  ADD COLUMN `description` VARCHAR(255) DEFAULT NULL AFTER `initials`;

ALTER TABLE `op_transactions` ADD COLUMN `ip_address` VARCHAR(45) DEFAULT NULL AFTER `gateway_trx_id`;
