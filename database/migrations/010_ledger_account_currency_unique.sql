-- Ledger accounts are resolved by (merchant_id, name, currency), but the unique
-- key was only (merchant_id, name). That blocked a second currency for the same
-- account name (e.g. a CASH account in both BDT and USD), failing multi-currency
-- ledger posting. Widen the unique key to include currency. The new key is a
-- superset of the old, so no existing row can violate it.
ALTER TABLE `op_ledger_accounts`
  DROP INDEX `uk_merchant_name`,
  ADD UNIQUE KEY `uk_merchant_name_currency` (`merchant_id`, `name`, `currency`);
