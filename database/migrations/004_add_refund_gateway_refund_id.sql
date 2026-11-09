-- Add gateway_refund_id column so the gateway's own refund transaction ID (e.g. bKash's
-- refundTrxID, Stripe's re_xxx) is recorded on a completed refund instead of being discarded.
-- Additive nullable column: existing rows get NULL, existing app code paths are unaffected.

ALTER TABLE `op_refunds` ADD COLUMN `gateway_refund_id` VARCHAR(200) DEFAULT NULL AFTER `failure_reason`;
ALTER TABLE `op_refunds` ADD KEY `idx_gateway_refund` (`gateway_refund_id`);
