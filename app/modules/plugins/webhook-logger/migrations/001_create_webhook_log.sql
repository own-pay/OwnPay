-- Webhook Logger: create the webhook log table
-- Use {prefix} placeholder for table prefix

CREATE TABLE IF NOT EXISTS `{prefix}webhook_log` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_type`    VARCHAR(100) NOT NULL,
    `source`        VARCHAR(100) NOT NULL DEFAULT '',
    `payload`       JSON NULL,
    `headers`       JSON NULL,
    `ip_address`    VARCHAR(45) NOT NULL DEFAULT '',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_webhook_log_event` (`event_type`),
    INDEX `idx_webhook_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
