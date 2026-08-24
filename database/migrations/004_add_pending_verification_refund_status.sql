-- Add 'pending_verification' status to op_refunds.
-- Audit fix CRON-7: RefundReconciliationJob previously auto-failed any refund
-- stuck in 'pending' for >24h by setting status='failed'. If the gateway
-- actually processed the refund but the process died before reconcile, the
-- customer got their money back but the merchant's withheld balance was
-- released - leading to a double refund if the merchant retried.
--
-- The job now sets status='pending_verification' instead, which preserves the
-- balance hold and surfaces the refund for explicit admin review.
--
-- Additive ENUM widening: existing 'pending'/'completed'/'failed' rows and
-- all existing app code paths are unaffected.

ALTER TABLE `op_refunds` MODIFY COLUMN `status` ENUM('pending','completed','failed','pending_verification') NOT NULL DEFAULT 'pending';
