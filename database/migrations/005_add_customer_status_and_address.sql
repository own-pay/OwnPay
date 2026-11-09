-- Migration: Add status and address_enc columns to op_customers
-- Adds soft-delete (status) and address field (address_enc) to customers
-- Required by audit fix CUS-4 (soft-delete row exclusion) and audit fix CUS-3 (address PII)

ALTER TABLE `op_customers`
    ADD COLUMN `address_enc` VARBINARY(1024) DEFAULT NULL AFTER `phone_hash`,
    ADD COLUMN `status` ENUM('active','deleted') NOT NULL DEFAULT 'active' AFTER `address_enc`;

-- Index for status filter
CREATE INDEX `idx_merchant_status` ON `op_customers` (`merchant_id`, `status`);
