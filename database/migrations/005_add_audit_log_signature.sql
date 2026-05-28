-- Add signature column to op_audit_logs for tamper-proof HMAC verification
ALTER TABLE `op_audit_logs`
ADD COLUMN `signature` VARCHAR(64) DEFAULT NULL AFTER `user_agent`;
