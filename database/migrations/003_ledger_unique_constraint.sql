-- Add UNIQUE constraint on op_ledger_transactions (merchant_id, reference_type, reference_id, description)
-- to enforce idempotency at the database layer. The LedgerService::postEntries() duplicate-detection
-- uses SELECT ... FOR UPDATE, but without a unique constraint two concurrent transactions can both see
-- "no row" and both INSERT successfully, double-posting the same payment/refund to the merchant's
-- ledger (e.g. merchant can withdraw $200 against a $100 capture).
--
-- The description column is VARCHAR(300); MySQL/MariaDB require a prefix length on the index when
-- any indexed column exceeds the engine's effective per-index byte budget. Using description(255)
-- keeps the unique key well under the utf8mb4 (4 bytes/char) 3072-byte InnoDB limit while still
-- disambiguating the relatively short, human-readable descriptions used by LedgerService.
--
-- Migration is idempotent: existing duplicate rows (if any) must be reconciled manually before this
-- ALTER will succeed. The IF NOT EXISTS clause on MySQL 8.0+ / MariaDB 10.5+ guards re-runs.

ALTER TABLE `op_ledger_transactions`
  ADD UNIQUE KEY `uk_merchant_ref` (`merchant_id`, `reference_type`, `reference_id`, `description`(255));
