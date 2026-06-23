DELETE wd FROM `op_webhook_deliveries` wd
JOIN `op_webhook_deliveries` keep
  ON keep.`direction` = 'inbound'
 AND wd.`direction` = 'inbound'
 AND IFNULL(keep.`merchant_id`, 0) = IFNULL(wd.`merchant_id`, 0)
 AND keep.`payload_hash` = wd.`payload_hash`
 AND keep.`id` < wd.`id`
WHERE wd.`payload_hash` IS NOT NULL;

ALTER TABLE `op_webhook_deliveries`
  ADD COLUMN `dedup_key` VARCHAR(150)
    GENERATED ALWAYS AS (
      IF(`direction` = 'inbound' AND `payload_hash` IS NOT NULL,
         CONCAT(IFNULL(`merchant_id`, 0), ':', `payload_hash`),
         NULL)
    ) VIRTUAL AFTER `payload_hash`,
  ADD UNIQUE KEY `uk_inbound_dedup` (`dedup_key`);
