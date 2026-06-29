-- ============================================================
-- OwnPay v0.1.0 - Unified Database Schema
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- 1. Merchants & RBAC

CREATE TABLE `op_merchants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `logo_path` VARCHAR(500) DEFAULT NULL,
  `color` VARCHAR(7) DEFAULT NULL,
  `initials` VARCHAR(5) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `timezone` VARCHAR(50) NOT NULL DEFAULT 'Asia/Dhaka',
  `default_currency` CHAR(3) NOT NULL DEFAULT 'BDT',
  `webhook_secret` VARCHAR(128) DEFAULT NULL,
  `settings` JSON DEFAULT NULL,
  `status` ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  `is_platform` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = reserved "All Brands" platform-owner row (not a selectable brand)',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  UNIQUE KEY `uk_slug` (`slug`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_roles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `slug` VARCHAR(60) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_slug` (`merchant_id`, `slug`),
  CONSTRAINT `fk_roles_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(80) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `group_name` VARCHAR(60) NOT NULL DEFAULT 'general',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_role_permissions` (
  `role_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `op_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `op_permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_merchant_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `username` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `avatar_path` VARCHAR(500) DEFAULT NULL,
  `totp_secret_enc` VARCHAR(500) DEFAULT NULL,
  `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `language` VARCHAR(10) DEFAULT NULL,
  `last_login_at` DATETIME(6) DEFAULT NULL,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `status` ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  `is_superadmin` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_merchant` (`merchant_id`),
  CONSTRAINT `fk_mu_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mu_role` FOREIGN KEY (`role_id`) REFERENCES `op_roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_api_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `key_prefix` VARCHAR(8) NOT NULL,
  `key_hash` VARCHAR(255) NOT NULL,
  `scopes` JSON DEFAULT NULL,
  `last_used_at` DATETIME(6) DEFAULT NULL,
  `expires_at` DATETIME(6) DEFAULT NULL,
  `status` ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_merchant` (`merchant_id`),
  KEY `idx_prefix` (`key_prefix`),
  CONSTRAINT `fk_ak_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_domains` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `domain` VARCHAR(253) NOT NULL,
  `type` ENUM('checkout','admin','api') NOT NULL DEFAULT 'checkout',
  `verification_token` VARCHAR(64) DEFAULT NULL,
  `dns_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `dns_verified_at` DATETIME(6) DEFAULT NULL,
  `ssl_status` ENUM('none','pending','active','expired') NOT NULL DEFAULT 'none',
  `redirect_url` VARCHAR(500) DEFAULT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','pending','inactive') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`),
  KEY `idx_merchant` (`merchant_id`),
  CONSTRAINT `fk_dom_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Gateways

CREATE TABLE `op_gateways` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(60) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `type` ENUM('api','manual','plugin') NOT NULL DEFAULT 'api',
  `logo_path` VARCHAR(500) DEFAULT NULL,
  `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_gateway_configs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED DEFAULT NULL,
  `gateway_id` BIGINT UNSIGNED NOT NULL,
  `credentials_enc` TEXT DEFAULT NULL,
  `settings` JSON DEFAULT NULL,
  `mode` ENUM('live','sandbox') NOT NULL DEFAULT 'sandbox',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_gw` (`merchant_id`, `gateway_id`),
  CONSTRAINT `fk_gc_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gc_gateway` FOREIGN KEY (`gateway_id`) REFERENCES `op_gateways` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_manual_gateways` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `slug` VARCHAR(60) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `logo_path` VARCHAR(500) DEFAULT NULL,
  `qr_code_path` VARCHAR(500) DEFAULT NULL,
  `colors` JSON DEFAULT NULL,
  `input_fields` JSON DEFAULT NULL,
  `instructions` JSON DEFAULT NULL,
  `admin_notes` TEXT DEFAULT NULL,
  `sms_verification` TINYINT(1) NOT NULL DEFAULT 0,
  `sms_sender_pattern` VARCHAR(100) DEFAULT NULL,
  `sms_regex_template` TEXT DEFAULT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'BDT',
  `min_amount` DECIMAL(15,2) DEFAULT NULL,
  `max_amount` DECIMAL(15,2) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_slug` (`merchant_id`, `slug`),
  CONSTRAINT `fk_mg_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_currencies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` CHAR(3) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `symbol` VARCHAR(10) NOT NULL,
  `decimal_places` TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_exchange_rates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `base_currency` CHAR(3) NOT NULL,
  `target_currency` CHAR(3) NOT NULL,
  `rate` DECIMAL(18,8) NOT NULL,
  `source` VARCHAR(50) NOT NULL DEFAULT 'manual',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pair` (`base_currency`, `target_currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_system_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_name` VARCHAR(60) NOT NULL DEFAULT 'general',
  `key_name` VARCHAR(100) NOT NULL,
  `value` TEXT DEFAULT NULL,
  `type` ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
  `merchant_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'AUD-G5: NULL=global, set=brand-scoped override',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_key_merchant` (`group_name`, `key_name`, `merchant_id`),
  KEY `idx_merchant_group` (`merchant_id`, `group_name`),
  CONSTRAINT `fk_settings_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Customers

CREATE TABLE `op_customers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `uuid` CHAR(36) NOT NULL,
  `name_enc` VARBINARY(512) DEFAULT NULL,
  `email_enc` VARBINARY(512) DEFAULT NULL,
  `email_hash` VARCHAR(64) NOT NULL,
  `phone_enc` VARBINARY(512) DEFAULT NULL,
  `phone_hash` VARCHAR(64) DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  UNIQUE KEY `uk_merchant_email` (`merchant_id`, `email_hash`),
  KEY `idx_merchant` (`merchant_id`),
  KEY `idx_merchant_phone_hash` (`merchant_id`, `phone_hash`),
  CONSTRAINT `fk_cust_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Payments

CREATE TABLE `op_payment_intents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `uuid` CHAR(36) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `customer_id` BIGINT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'BDT',
  `description` VARCHAR(500) DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `redirect_url` VARCHAR(1000) DEFAULT NULL,
  `cancel_url` VARCHAR(1000) DEFAULT NULL,
  `webhook_url` VARCHAR(1000) DEFAULT NULL,
  `status` ENUM('pending','processing','completed','failed','cancelled','expired') NOT NULL DEFAULT 'pending',
  `expires_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_merchant_status` (`merchant_id`, `status`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_pi_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `uuid` CHAR(36) NOT NULL,
  `trx_id` VARCHAR(30) NOT NULL,
  `payment_intent_id` BIGINT UNSIGNED DEFAULT NULL,
  `customer_id` BIGINT UNSIGNED DEFAULT NULL,
  `gateway_slug` VARCHAR(60) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `fee` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(15,2) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'BDT',
  `sender_account` VARCHAR(100) DEFAULT NULL,
  `reference` VARCHAR(200) DEFAULT NULL,
  `gateway_trx_id` VARCHAR(200) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `provider_trx_id` VARCHAR(100) DEFAULT NULL,
  `method` ENUM('api','manual','sms','link','invoice') NOT NULL DEFAULT 'manual',
  `status` ENUM('pending','created','processing','callback_processing','completed','failed','cancelled','expired','refunded','disputed','awaiting_verification','pending_review') NOT NULL DEFAULT 'pending',
  `metadata` JSON DEFAULT NULL,
  `invoice_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.invoice_id')) AS UNSIGNED)) STORED,
  `payment_link_id` BIGINT UNSIGNED GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.payment_link_id')) AS UNSIGNED)) STORED,
  `completed_at` DATETIME(6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  UNIQUE KEY `uk_trx_id` (`trx_id`),
  KEY `idx_merchant_status` (`merchant_id`, `status`),
  KEY `idx_merchant_created` (`merchant_id`, `created_at`),
  KEY `idx_gateway` (`gateway_slug`),
  KEY `idx_gateway_trx` (`gateway_trx_id`),
  KEY `idx_provider_trx` (`provider_trx_id`),
  KEY `idx_pi` (`payment_intent_id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_payment_link_id` (`payment_link_id`),
  CONSTRAINT `fk_txn_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_txn_customer` FOREIGN KEY (`customer_id`) REFERENCES `op_customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_idempotency_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `request_hash` VARCHAR(64) NOT NULL,
  `response_code` SMALLINT UNSIGNED DEFAULT NULL,
  `response_body` TEXT DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `expires_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_key` (`merchant_id`, `idempotency_key`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_ik_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_refunds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `transaction_id` BIGINT UNSIGNED NOT NULL,
  `uuid` CHAR(36) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `reason` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  `processed_at` DATETIME(6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  KEY `idx_txn` (`transaction_id`),
  CONSTRAINT `fk_ref_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ref_txn` FOREIGN KEY (`transaction_id`) REFERENCES `op_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_payment_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `uuid` CHAR(36) NOT NULL,
  `slug` VARCHAR(80) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `amount` DECIMAL(15,2) DEFAULT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'BDT',
  `is_amount_fixed` TINYINT(1) NOT NULL DEFAULT 1,
  `require_address` TINYINT(1) NOT NULL DEFAULT 0,
  `min_amount` DECIMAL(15,2) DEFAULT NULL,
  `max_amount` DECIMAL(15,2) DEFAULT NULL,
  `redirect_url` VARCHAR(1000) DEFAULT NULL,
  `max_uses` INT UNSIGNED DEFAULT NULL,
  `use_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME(6) DEFAULT NULL,
  `status` ENUM('active','inactive','expired') NOT NULL DEFAULT 'active',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_merchant` (`merchant_id`),
  CONSTRAINT `fk_pl_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_payment_link_fields` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_link_id` BIGINT UNSIGNED NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `type` ENUM('text','number','email','select','textarea') NOT NULL DEFAULT 'text',
  `options` JSON DEFAULT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_link` (`payment_link_id`),
  CONSTRAINT `fk_plf_link` FOREIGN KEY (`payment_link_id`) REFERENCES `op_payment_links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `uuid` CHAR(36) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `invoice_number` VARCHAR(30) NOT NULL,
  `customer_id` BIGINT UNSIGNED DEFAULT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tax` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(15,2) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'BDT',
  `notes` TEXT DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `status` ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `paid_at` DATETIME(6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  UNIQUE KEY `uk_token` (`token`),
  UNIQUE KEY `uk_merchant_number` (`merchant_id`, `invoice_number`),
  CONSTRAINT `fk_inv_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_customer` FOREIGN KEY (`customer_id`) REFERENCES `op_customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_invoice_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `description` VARCHAR(300) NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` DECIMAL(15,2) NOT NULL,
  `total` DECIMAL(15,2) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `fk_ii_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `op_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Webhooks

CREATE TABLE `op_webhooks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `url` VARCHAR(1000) NOT NULL,
  `secret` VARCHAR(128) NOT NULL,
  `events` JSON NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_merchant` (`merchant_id`),
  CONSTRAINT `fk_wh_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `webhook_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(80) NOT NULL,
  `payload` JSON NOT NULL,
  `status` ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME(6) DEFAULT NULL,
  `next_retry_at` DATETIME(6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_webhook_status` (`webhook_id`, `status`),
  KEY `idx_retry` (`next_retry_at`),
  CONSTRAINT `fk_we_webhook` FOREIGN KEY (`webhook_id`) REFERENCES `op_webhooks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_webhook_delivery_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `webhook_event_id` BIGINT UNSIGNED NOT NULL,
  `response_code` SMALLINT UNSIGNED DEFAULT NULL,
  `response_body` TEXT DEFAULT NULL,
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `error` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_event` (`webhook_event_id`),
  CONSTRAINT `fk_wdl_event` FOREIGN KEY (`webhook_event_id`) REFERENCES `op_webhook_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_webhook_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED DEFAULT NULL,
  `gateway` VARCHAR(60) NOT NULL DEFAULT 'system',
  `event` VARCHAR(100) DEFAULT NULL,
  `url` VARCHAR(1000) DEFAULT NULL,
  `direction` ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
  `status_code` SMALLINT UNSIGNED DEFAULT NULL,
  `response_time_ms` INT UNSIGNED DEFAULT NULL,
  `attempt` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('received','delivered','failed','rejected') NOT NULL DEFAULT 'received',
  `payload_hash` VARCHAR(128) DEFAULT NULL,
  `dedup_key` VARCHAR(150)
    GENERATED ALWAYS AS (
      IF(`direction` = 'inbound' AND `payload_hash` IS NOT NULL,
         CONCAT(IFNULL(`merchant_id`, 0), ':', `payload_hash`),
         NULL)
    ) VIRTUAL,
  `error` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inbound_dedup` (`dedup_key`),
  KEY `idx_merchant_created` (`merchant_id`, `created_at`),
  KEY `idx_gateway` (`gateway`),
  KEY `idx_direction_status` (`direction`, `status`),
  CONSTRAINT `fk_wd_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Ledger

CREATE TABLE `op_ledger_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'BDT',
  `balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_name_currency` (`merchant_id`, `name`, `currency`),
  CONSTRAINT `fk_la_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_ledger_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `uuid` CHAR(36) NOT NULL,
  `description` VARCHAR(300) NOT NULL,
  `reference_type` VARCHAR(30) DEFAULT NULL,
  `reference_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  KEY `idx_merchant` (`merchant_id`),
  KEY `idx_ref` (`reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_ledger_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ledger_transaction_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('debit','credit') NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_txn` (`ledger_transaction_id`),
  KEY `idx_account` (`account_id`),
  CONSTRAINT `fk_le_txn` FOREIGN KEY (`ledger_transaction_id`) REFERENCES `op_ledger_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_le_account` FOREIGN KEY (`account_id`) REFERENCES `op_ledger_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_disputes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `transaction_id` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `evidence` JSON DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `status` ENUM('open','under_review','won','lost','closed') NOT NULL DEFAULT 'open',
  `resolved_at` DATETIME(6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_merchant` (`merchant_id`),
  KEY `idx_txn` (`transaction_id`),
  CONSTRAINT `fk_dsp_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dsp_txn` FOREIGN KEY (`transaction_id`) REFERENCES `op_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Fees

CREATE TABLE `op_fee_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED DEFAULT NULL,
  `gateway_slug` VARCHAR(60) DEFAULT NULL,
  `type` ENUM('flat','percentage','tiered') NOT NULL DEFAULT 'percentage',
  `value` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  `min_fee` DECIMAL(15,2) DEFAULT NULL,
  `max_fee` DECIMAL(15,2) DEFAULT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'BDT',
  `tiers` JSON DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_merchant_gw` (`merchant_id`, `gateway_slug`),
  CONSTRAINT `fk_fr_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Audit

CREATE TABLE `op_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED DEFAULT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `entity_type` VARCHAR(60) DEFAULT NULL,
  `entity_id` BIGINT UNSIGNED DEFAULT NULL,
  `old_values` JSON DEFAULT NULL,
  `new_values` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `signature` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_merchant_action` (`merchant_id`, `action`),
  KEY `idx_entity` (`entity_type`, `entity_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_email_ip` (`email`, `ip_address`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_password_resets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME(6) NOT NULL,
  `used_at` DATETIME(6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pwreset_token` (`token_hash`),
  KEY `idx_pwreset_user` (`user_id`),
  KEY `idx_pwreset_expires` (`expires_at`),
  CONSTRAINT `fk_pwreset_user` FOREIGN KEY (`user_id`) REFERENCES `op_merchant_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Devices & SMS

CREATE TABLE `op_device_pairing_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `otp_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME(6) NOT NULL,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `used_at` DATETIME(6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_merchant` (`merchant_id`),
  KEY `idx_hash` (`otp_hash`),
  CONSTRAINT `fk_dpt_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_paired_devices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED DEFAULT NULL,
  `device_id` VARCHAR(64) NOT NULL,
  `device_name` VARCHAR(150) DEFAULT NULL,
  `platform` VARCHAR(30) DEFAULT NULL,
  `jwt_fingerprint` VARCHAR(255) DEFAULT NULL,
  `aes_key_encrypted` TEXT DEFAULT NULL,
  `last_heartbeat` DATETIME(6) DEFAULT NULL,
  `status` ENUM('active','revoked','inactive') NOT NULL DEFAULT 'active',
  `paired_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device` (`device_id`),
  KEY `idx_merchant` (`merchant_id`),
  CONSTRAINT `fk_pd_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_mobile_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `device_uuid` VARCHAR(64) NOT NULL,
  `type` VARCHAR(60) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT DEFAULT NULL,
  `payload` JSON DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` DATETIME(6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_merchant_read` (`merchant_id`, `is_read`),
  KEY `idx_device` (`device_uuid`),
  KEY `idx_merchant_device` (`merchant_id`, `device_uuid`),
  CONSTRAINT `fk_mn_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mn_device` FOREIGN KEY (`device_uuid`) REFERENCES `op_paired_devices` (`device_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_sms_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED DEFAULT NULL,
  `gateway_slug` VARCHAR(60) NOT NULL,
  `sender_pattern` VARCHAR(100) NOT NULL,
  `amount_regex` VARCHAR(500) NOT NULL,
  `trx_id_regex` VARCHAR(500) DEFAULT NULL,
  `sender_regex` VARCHAR(500) DEFAULT NULL,
  `priority` INT NOT NULL DEFAULT 10,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_gateway` (`gateway_slug`),
  KEY `idx_merchant` (`merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_sms_parsed` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED DEFAULT NULL,
  `device_id` VARCHAR(64) NOT NULL,
  `local_id` INT DEFAULT NULL,
  `sender` VARCHAR(100) NOT NULL,
  `body` TEXT DEFAULT NULL,
  `amount` DECIMAL(20,6) DEFAULT NULL,
  `trx_id` VARCHAR(100) DEFAULT NULL,
  `parsed_sender` VARCHAR(255) DEFAULT NULL,
  `parsed_balance` DECIMAL(20,6) DEFAULT NULL,
  `gateway_slug` VARCHAR(60) DEFAULT NULL,
  `parser_type` ENUM('regex','heuristic','manual','unparsed') DEFAULT NULL,
  `parsed_type` VARCHAR(50) DEFAULT NULL,
  `template_id` BIGINT UNSIGNED DEFAULT NULL,
  `parse_confidence` ENUM('high','medium','low') DEFAULT 'low',
  `match_status` ENUM('pending','matched','unmatched','ignored','accepted','parse_error','admin_review') NOT NULL DEFAULT 'pending',
  `transaction_id` BIGINT UNSIGNED DEFAULT NULL,
  `raw_data` JSON DEFAULT NULL,
  `encrypted_raw` TEXT DEFAULT NULL,
  `received_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_merchant_status` (`merchant_id`, `match_status`),
  KEY `idx_trx` (`transaction_id`),
  KEY `idx_received` (`received_at`),
  KEY `idx_device` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Plugins

CREATE TABLE `op_plugins` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(80) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `type` ENUM('gateway','theme','addon') NOT NULL,
  `version` VARCHAR(20) NOT NULL,
  `entrypoint` VARCHAR(255) NOT NULL,
  `capabilities` JSON DEFAULT NULL,
  `manifest` JSON DEFAULT NULL,
  `status` ENUM('active','inactive','error','trashed') NOT NULL DEFAULT 'inactive',
  `installed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_brand_plugins` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `plugin_slug` VARCHAR(80) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_brand_plugin` (`merchant_id`, `plugin_slug`),
  CONSTRAINT `fk_brand_plugins_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_brand_plugins_slug` FOREIGN KEY (`plugin_slug`) REFERENCES `op_plugins` (`slug`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_plugin_migrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_slug` VARCHAR(80) NOT NULL,
  `migration` VARCHAR(200) NOT NULL,
  `batch` INT UNSIGNED NOT NULL DEFAULT 1,
  `executed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin_migration` (`plugin_slug`, `migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_plugin_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_slug` VARCHAR(80) NOT NULL,
  `key_name` VARCHAR(100) NOT NULL,
  `value` TEXT DEFAULT NULL,
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin_key` (`plugin_slug`, `key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. System

CREATE TABLE `op_rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_name` VARCHAR(200) NOT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT 1,
  `window_start` INT UNSIGNED NOT NULL,
  `expires_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key_name`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_sessions` (
  `id` VARCHAR(128) NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `data` MEDIUMTEXT NOT NULL,
  `last_activity` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_queue_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` VARCHAR(60) NOT NULL DEFAULT 'default',
  `handler` VARCHAR(255) NOT NULL,
  `payload` JSON NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `available_at` INT UNSIGNED NOT NULL,
  `reserved_at` INT UNSIGNED DEFAULT NULL,
  `error` TEXT DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_queue_available` (`queue`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_job_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(100) NOT NULL,
  `payload` JSON NOT NULL,
  `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `priority` INT NOT NULL DEFAULT 0,
  `available_at` DATETIME(6) DEFAULT NULL,
  `started_at` DATETIME(6) DEFAULT NULL,
  `completed_at` DATETIME(6) DEFAULT NULL,
  `error` TEXT DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_status_available` (`status`, `available_at`),
  KEY `idx_priority_created` (`priority`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_cache` (
  `key_name` VARCHAR(200) NOT NULL,
  `value` MEDIUMBLOB NOT NULL,
  `expires_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`key_name`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_languages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(10) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `translations` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  CONSTRAINT `chk_translations_json` CHECK (json_valid(`translations`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Communication

CREATE TABLE `op_comm_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED DEFAULT NULL,
  `channel` ENUM('sms','email','telegram','webhook') NOT NULL,
  `recipient` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(300) DEFAULT NULL,
  `body` TEXT DEFAULT NULL,
  `provider` VARCHAR(60) DEFAULT NULL,
  `status` ENUM('queued','sent','delivered','failed') NOT NULL DEFAULT 'queued',
  `error` VARCHAR(500) DEFAULT NULL,
  `sent_at` DATETIME(6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_merchant_channel` (`merchant_id`, `channel`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Auto-Updater

CREATE TABLE `op_update_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_version` VARCHAR(20) NOT NULL,
  `to_version` VARCHAR(20) NOT NULL,
  `status` ENUM('started','backup_created','downloaded','applied','verified','completed','rolled_back','failed') NOT NULL,
  `backup_path` VARCHAR(500) DEFAULT NULL,
  `checksum` VARCHAR(64) DEFAULT NULL,
  `error` TEXT DEFAULT NULL,
  `started_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `completed_at` DATETIME(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_maintenance_locks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reason` VARCHAR(200) NOT NULL,
  `initiated_by` VARCHAR(60) NOT NULL DEFAULT 'system',
  `retry_after` INT UNSIGNED NOT NULL DEFAULT 600,
  `locked_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `unlocked_at` DATETIME(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_locked` (`locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `op_migrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(255) NOT NULL,
  `executed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
