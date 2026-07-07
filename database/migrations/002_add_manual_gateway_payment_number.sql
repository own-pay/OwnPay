-- Add payment_number column for manual gateways (bKash/Nagad-style account number display).
-- Additive nullable column: existing rows get NULL, existing app code paths are unaffected.

ALTER TABLE `op_manual_gateways` ADD COLUMN `payment_number` VARCHAR(100) DEFAULT NULL AFTER `qr_code_path`;
