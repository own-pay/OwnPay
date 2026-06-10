-- Add provider_trx_id column and index to op_transactions
ALTER TABLE `op_transactions`
  ADD COLUMN `provider_trx_id` VARCHAR(100) DEFAULT NULL AFTER `gateway_trx_id`,
  ADD KEY `idx_provider_trx` (`provider_trx_id`);
