-- Add failure_reason column so a declined/errored gateway refund records WHY it failed,
-- instead of the admin panel only ever showing a generic "failed" status with no detail.
-- Additive nullable column: existing rows get NULL, existing app code paths are unaffected.

ALTER TABLE `op_refunds` ADD COLUMN `failure_reason` VARCHAR(500) DEFAULT NULL AFTER `status`;
