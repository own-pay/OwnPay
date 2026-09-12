-- 003: Extend op_update_history to distinguish remote vs manual ZIP uploads.

ALTER TABLE `op_update_history`
  ADD COLUMN `update_type` ENUM('remote','manual') NOT NULL DEFAULT 'remote' AFTER `status`,
  ADD COLUMN `uploaded_by` VARCHAR(120) DEFAULT NULL AFTER `update_type`,
  ADD COLUMN `zip_filename` VARCHAR(255) DEFAULT NULL AFTER `uploaded_by`,
  ADD INDEX `idx_update_type` (`update_type`);
