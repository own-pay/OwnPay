-- ============================================================
-- OwnPay Schema Sync Migration — Strategy B
-- Date: 2026-05-09
-- Purpose: Update live DB to match application code expectations
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ──────────────────────────────────────────────────────────────
-- 1. op_device_pairing_tokens
--    Rename: token → otp_hash, used → is_used
--    Add: brand_id, created_by, used_at
-- ──────────────────────────────────────────────────────────────

ALTER TABLE `op_device_pairing_tokens`
  CHANGE COLUMN `token` `otp_hash` VARCHAR(255) NOT NULL,
  CHANGE COLUMN `used` `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `brand_id` BIGINT UNSIGNED DEFAULT NULL AFTER `merchant_id`,
  ADD COLUMN `created_by` BIGINT UNSIGNED DEFAULT NULL AFTER `brand_id`,
  ADD COLUMN `used_at` DATETIME(6) DEFAULT NULL AFTER `is_used`;

-- ──────────────────────────────────────────────────────────────
-- 2. op_mobile_notifications
--    Rename: device_id (BIGINT) → device_uuid (VARCHAR), data → payload
--    Add: read_at
-- ──────────────────────────────────────────────────────────────

ALTER TABLE `op_mobile_notifications`
  CHANGE COLUMN `device_id` `device_uuid` VARCHAR(64) NOT NULL,
  CHANGE COLUMN `data` `payload` JSON DEFAULT NULL,
  ADD COLUMN `read_at` DATETIME(6) DEFAULT NULL AFTER `is_read`;

-- ──────────────────────────────────────────────────────────────
-- 3. op_paired_devices
--    Add: aes_key_encrypted (for SMS decryption key storage)
-- ──────────────────────────────────────────────────────────────

ALTER TABLE `op_paired_devices`
  ADD COLUMN `aes_key_encrypted` TEXT DEFAULT NULL AFTER `jwt_fingerprint`;

-- ──────────────────────────────────────────────────────────────
-- 4. op_sms_parsed
--    Change: device_id BIGINT → VARCHAR(64)
--    Modify: body TEXT NOT NULL → TEXT DEFAULT NULL
--    Expand ENUMs for new parser statuses
--    Add: local_id, encrypted_raw, parsed_sender, parsed_balance,
--         parsed_type, template_id, parse_confidence
-- ──────────────────────────────────────────────────────────────

ALTER TABLE `op_sms_parsed`
  MODIFY COLUMN `device_id` VARCHAR(64) NOT NULL,
  MODIFY COLUMN `body` TEXT DEFAULT NULL,
  MODIFY COLUMN `parser_type` ENUM('regex','heuristic','manual','unparsed') DEFAULT NULL,
  MODIFY COLUMN `match_status` ENUM('pending','matched','unmatched','ignored','accepted','parse_error','admin_review') NOT NULL DEFAULT 'pending',
  ADD COLUMN `local_id` INT DEFAULT NULL AFTER `device_id`,
  ADD COLUMN `encrypted_raw` TEXT DEFAULT NULL AFTER `raw_data`,
  ADD COLUMN `parsed_sender` VARCHAR(255) DEFAULT NULL AFTER `trx_id`,
  ADD COLUMN `parsed_balance` DECIMAL(20,6) DEFAULT NULL AFTER `parsed_sender`,
  ADD COLUMN `parsed_type` VARCHAR(50) DEFAULT NULL AFTER `parser_type`,
  ADD COLUMN `template_id` BIGINT UNSIGNED DEFAULT NULL AFTER `parsed_type`,
  ADD COLUMN `parse_confidence` ENUM('high','medium','low') DEFAULT 'low' AFTER `template_id`;

SET FOREIGN_KEY_CHECKS = 1;
