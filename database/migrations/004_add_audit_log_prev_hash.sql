-- SYS-1: Add prev_hash column to op_audit_logs to enable a forward hash chain.
-- Each row's signature now includes the previous row's signature, so deletion
-- or insertion of rows is detectable by verifying the chain links are intact.
-- The column is nullable so existing rows (created before this migration) are
-- treated as "no chain" by verifyIntegrity() — the legacy per-row HMAC check
-- still applies to them.

ALTER TABLE `op_audit_logs`
  ADD COLUMN `prev_hash` VARCHAR(64) NULL DEFAULT NULL AFTER `signature`;
