-- AUD-G5: Add brand-scoped plugin settings support
-- merchant_id NULL = global setting (backward compat), non-NULL = brand override

-- 1. Add column
ALTER TABLE `op_system_settings`
  ADD COLUMN `merchant_id` BIGINT UNSIGNED DEFAULT NULL
  COMMENT 'AUD-G5: NULL=global, set=brand-scoped override'
  AFTER `type`;

-- 2. Drop old unique key and create new one that includes merchant_id
ALTER TABLE `op_system_settings`
  DROP INDEX `uk_group_key`,
  ADD UNIQUE KEY `uk_group_key_merchant` (`group_name`, `key_name`, `merchant_id`),
  ADD KEY `idx_merchant_group` (`merchant_id`, `group_name`),
  ADD CONSTRAINT `fk_settings_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE;
