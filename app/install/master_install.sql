-- ============================================================================
-- OWN PAY — Master Install Schema (Greenfield)
-- ============================================================================
-- Version:       2.1 (Master Install — Schema + Hardening Consolidated)
-- Engine:        MySQL 8.0.17+ / MariaDB 10.9+ / PostgreSQL 14+
-- Charset:       utf8mb4 (full Unicode support)
-- Collation:     utf8mb4_unicode_ci
-- Auth Model:    Authorization: Bearer <TOKEN>
-- License:       AGPL-3.0 (fork of OwnPay)
--
-- HARDENING INCLUDED:
--   • Monthly Range Partitioning on 5 heavyweight tables
--   • Strict JSON Schema Validation (MySQL 8.0.17+)
--   • Deadlock Prevention / Performance Indexes for Ledger
--   • App-layer FK enforcement docs for partitioned tables
-- ============================================================================
--
-- COMPATIBILITY NOTES (PostgreSQL):
--   1. Replace AUTO_INCREMENT  →  GENERATED ALWAYS AS IDENTITY
--   2. Replace DATETIME(6)     →  TIMESTAMP(6) WITH TIME ZONE
--   3. Replace TINYINT(1)      →  BOOLEAN
--   4. Replace BIGINT UNSIGNED  →  BIGINT (PG has no UNSIGNED)
--   5. JSON type works in all three engines.
--   6. CHECK constraints work in MySQL 8.0.16+, MariaDB 10.2.1+, PG always.
--   7. ENGINE=InnoDB clause is MySQL/MariaDB only; PG ignores it.
-- ============================================================================

SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ############################################################################
-- SECTION 1: CORE IDENTITY & MERCHANT MANAGEMENT
-- ############################################################################

-- ----------------------------------------------------------------------------
-- 1.1  op_merchants — Top-level tenant / business entity (replaces op_brands)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_merchants`;
CREATE TABLE `op_merchants` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`       CHAR(36)        NOT NULL,                          -- UUID v4, public-facing
  `business_name`   VARCHAR(255)    NOT NULL,
  `display_name`    VARCHAR(255)    NULL,
  `logo_url`        VARCHAR(2048)   NULL,
  `favicon_url`     VARCHAR(2048)   NULL,
  `support_email`   VARCHAR(255)    NULL,
  `support_phone`   VARCHAR(30)     NULL,
  `support_url`     VARCHAR(2048)   NULL,
  `street_address`  VARCHAR(500)    NULL,
  `city`            VARCHAR(100)    NULL,
  `postal_code`     VARCHAR(20)     NULL,
  `country_code`    CHAR(2)         NULL,                              -- ISO 3166-1 alpha-2
  `base_currency`   CHAR(3)         NOT NULL DEFAULT 'BDT',            -- ISO 4217
  `timezone`        VARCHAR(64)     NOT NULL DEFAULT 'Asia/Dhaka',     -- IANA timezone
  `locale`          VARCHAR(10)     NOT NULL DEFAULT 'en',
  `theme`           VARCHAR(64)     NOT NULL DEFAULT 'own-pay',
  `kyc_status`      VARCHAR(20)     NOT NULL DEFAULT 'pending'
                    CHECK (`kyc_status` IN ('pending','submitted','verified','rejected')),
  `status`          VARCHAR(20)     NOT NULL DEFAULT 'active'
                    CHECK (`status` IN ('active','suspended','closed')),
  `deleted_at`      DATETIME(6)     NULL     DEFAULT NULL,             -- soft delete
  `created_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_merchants_public_id` (`public_id`),
  INDEX `idx_merchants_status` (`status`, `kyc_status`),
  INDEX `idx_merchants_country` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 1.2  op_roles — RBAC role definitions
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_roles`;
CREATE TABLE `op_roles` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(50)     NOT NULL,                              -- e.g. 'owner','admin','staff','viewer'
  `name`        VARCHAR(100)    NOT NULL,
  `description` VARCHAR(500)    NULL,
  `is_system`   TINYINT(1)      NOT NULL DEFAULT 0,                    -- system roles cannot be deleted
  `created_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 1.3  op_permissions — Granular permission tokens
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_permissions`;
CREATE TABLE `op_permissions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(80)     NOT NULL,                              -- e.g. 'payments:read','refunds:create'
  `name`        VARCHAR(150)    NOT NULL,
  `group_name`  VARCHAR(50)     NOT NULL DEFAULT 'general',            -- UI grouping
  `created_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 1.4  op_role_permissions — Many-to-many junction
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_role_permissions`;
CREATE TABLE `op_role_permissions` (
  `role_id`       BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,

  PRIMARY KEY (`role_id`, `permission_id`),
  CONSTRAINT `fk_rp_role`       FOREIGN KEY (`role_id`)       REFERENCES `op_roles`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `op_permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 1.5  op_merchant_users — Users with RBAC mapped to merchants
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_merchant_users`;
CREATE TABLE `op_merchant_users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`     CHAR(36)        NOT NULL,
  `merchant_id`   BIGINT UNSIGNED NOT NULL,
  `role_id`       BIGINT UNSIGNED NOT NULL,
  `full_name`     VARCHAR(200)    NOT NULL,
  `email`         VARCHAR(255)    NOT NULL,
  `username`      VARCHAR(80)     NOT NULL,
  `password_hash` VARCHAR(255)    NOT NULL,                            -- Argon2id / bcrypt
  `temp_password` VARCHAR(255)    NULL,                                -- one-time reset token hash
  `two_fa_status` VARCHAR(10)     NOT NULL DEFAULT 'disabled'
                  CHECK (`two_fa_status` IN ('enabled','disabled')),
  `two_fa_secret` VARCHAR(64)     NULL,                                -- TOTP secret (encrypted at rest)
  `last_otp_window` BIGINT UNSIGNED NULL DEFAULT NULL,                 -- F6: last successful TOTP window (replay prevention)
  `status`        VARCHAR(20)     NOT NULL DEFAULT 'active'
                  CHECK (`status` IN ('active','suspended','invited')),
  `last_login_at` DATETIME(6)     NULL,
  `deleted_at`    DATETIME(6)     NULL     DEFAULT NULL,
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mu_public_id` (`public_id`),
  UNIQUE KEY `uq_mu_email_merchant` (`merchant_id`, `email`),
  UNIQUE KEY `uq_mu_username` (`username`),
  INDEX `idx_mu_status` (`status`),
  CONSTRAINT `fk_mu_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mu_role`     FOREIGN KEY (`role_id`)     REFERENCES `op_roles`(`id`)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 1.6  op_api_keys — Bearer token registry (hash-only storage)
--      Auth model: Authorization: Bearer <TOKEN>
--      Token format: op_live_<random> or op_test_<random>
--      DB stores SHA-256 hash only; raw shown once on creation.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_api_keys`;
CREATE TABLE `op_api_keys` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`     CHAR(36)        NOT NULL,
  `merchant_id`   BIGINT UNSIGNED NOT NULL,
  `name`          VARCHAR(150)    NOT NULL,                            -- human label
  `key_prefix`    VARCHAR(12)     NOT NULL,                            -- first 8-12 chars for identification
  `key_hash`      CHAR(64)        NOT NULL,                            -- SHA-256 hex digest
  `environment`   VARCHAR(10)     NOT NULL DEFAULT 'test'
                  CHECK (`environment` IN ('test','live')),
  `scopes`        JSON            NULL,                                -- ["payments:read","refunds:create"]
  -- JSON VALIDATION: scopes must be array of strings
  CONSTRAINT `chk_ak_scopes_json` CHECK (`scopes` IS NULL OR JSON_SCHEMA_VALID('{"type":"array","items":{"type":"string","minLength":1}}', `scopes`)),
  `last_used_at`  DATETIME(6)     NULL,
  `expires_at`    DATETIME(6)     NULL,                                -- NULL = never expires
  `status`        VARCHAR(20)     NOT NULL DEFAULT 'active'
                  CHECK (`status` IN ('active','revoked')),
  `created_by`    BIGINT UNSIGNED NULL,                                -- merchant_user who created
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ak_public_id` (`public_id`),
  UNIQUE KEY `uq_ak_key_hash` (`key_hash`),                           -- fast lookup by hash
  UNIQUE KEY `uq_ak_prefix` (`key_prefix`),                            -- F10: prevent UI ambiguity
  INDEX `idx_ak_merchant_env` (`merchant_id`, `environment`, `status`),
  CONSTRAINT `fk_ak_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_ak_creator`  FOREIGN KEY (`created_by`)  REFERENCES `op_merchant_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 1.7  op_domains — Whitelisted domains per merchant
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_domains`;
CREATE TABLE `op_domains` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `domain`      VARCHAR(255)    NOT NULL,                              -- e.g. 'example.com'
  `status`      VARCHAR(20)     NOT NULL DEFAULT 'active'
                CHECK (`status` IN ('active','inactive')),
  `verified_at` DATETIME(6)     NULL,
  `created_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_domains_merchant_domain` (`merchant_id`, `domain`),
  CONSTRAINT `fk_domains_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################################
-- SECTION 2: GATEWAY & CONFIGURATION
-- ############################################################################

-- ----------------------------------------------------------------------------
-- 2.1  op_gateways — Gateway plugin registry (46 gateways in current system)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_gateways`;
CREATE TABLE `op_gateways` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`       CHAR(36)        NOT NULL,
  `slug`            VARCHAR(60)     NOT NULL,                          -- e.g. 'bkash-api-tokenized'
  `name`            VARCHAR(150)    NOT NULL,                          -- display name
  `provider`        VARCHAR(80)     NOT NULL,                          -- e.g. 'bKash','Nagad','Stripe'
  `category`        VARCHAR(20)     NOT NULL DEFAULT 'mfs'
                    CHECK (`category` IN ('mfs','bank','global','manual','crypto')),
  `supported_currencies` JSON       NULL,                              -- ["BDT","USD"]
  -- JSON VALIDATION: must be array of 3-char ISO 4217 codes
  CONSTRAINT `chk_gw_currencies_json` CHECK (`supported_currencies` IS NULL OR JSON_SCHEMA_VALID('{"type":"array","items":{"type":"string","minLength":3,"maxLength":3}}', `supported_currencies`)),
  `logo_url`        VARCHAR(2048)   NULL,
  `class_file`      VARCHAR(255)    NULL,                              -- plugin class path
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gw_public_id` (`public_id`),
  UNIQUE KEY `uq_gw_slug` (`slug`),
  INDEX `idx_gw_category` (`category`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2.2  op_gateway_configs — Per-merchant gateway configuration
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_gateway_configs`;
CREATE TABLE `op_gateway_configs` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id`         BIGINT UNSIGNED NOT NULL,
  `gateway_id`          BIGINT UNSIGNED NOT NULL,
  `environment`         VARCHAR(10)     NOT NULL DEFAULT 'test'
                        CHECK (`environment` IN ('test','live')),
  `credentials`         JSON            NOT NULL,                      -- encrypted API keys/secrets
  `min_amount`          DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `max_amount`          DECIMAL(19,4)   NOT NULL DEFAULT 999999999.0000,
  `fixed_fee`           DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `percentage_fee`      DECIMAL(8,4)    NOT NULL DEFAULT 0.0000,       -- e.g. 1.5000 = 1.5%
  `fixed_discount`      DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `percentage_discount` DECIMAL(8,4)    NOT NULL DEFAULT 0.0000,
  `display_name`        VARCHAR(150)    NULL,                          -- merchant-facing override
  `primary_color`       CHAR(7)         NULL,                          -- hex color
  `sort_order`          INT             NOT NULL DEFAULT 0,
  `status`              VARCHAR(20)     NOT NULL DEFAULT 'active'
                        CHECK (`status` IN ('active','inactive')),
  `created_at`          DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`          DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  -- JSON VALIDATION (MySQL 8.0.17+)
  CONSTRAINT `chk_gc_credentials_json` CHECK (
    JSON_SCHEMA_VALID('{"type":"object","required":["env"],"properties":{"env":{"type":"string","enum":["test","live"]},"api_key":{"type":"string"},"api_secret":{"type":"string"},"base_url":{"type":"string"}},"additionalProperties":true}', `credentials`)
  ),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gc_merchant_gw_env` (`merchant_id`, `gateway_id`, `environment`),
  CONSTRAINT `fk_gc_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gc_gateway`  FOREIGN KEY (`gateway_id`)  REFERENCES `op_gateways`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2.3  op_currencies — Currency definitions
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_currencies`;
CREATE TABLE `op_currencies` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        CHAR(3)         NOT NULL,                              -- ISO 4217: BDT, USD, EUR
  `name`        VARCHAR(80)     NOT NULL,
  `symbol`      VARCHAR(10)     NOT NULL,
  `decimals`    TINYINT         NOT NULL DEFAULT 2,                    -- minor units (BDT=2, JPY=0)
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_currencies_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2.4  op_exchange_rates — Rate snapshots per merchant
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_exchange_rates`;
CREATE TABLE `op_exchange_rates` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id`   BIGINT UNSIGNED NOT NULL,
  `from_currency` CHAR(3)         NOT NULL,
  `to_currency`   CHAR(3)         NOT NULL,
  `rate`          DECIMAL(19,8)   NOT NULL,                            -- higher precision for FX
  `source`        VARCHAR(50)     NOT NULL DEFAULT 'manual',           -- 'manual','api','ecb'
  `fetched_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  INDEX `idx_er_merchant_pair` (`merchant_id`, `from_currency`, `to_currency`, `fetched_at`),
  CONSTRAINT `fk_er_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2.5  op_system_settings — Key-value config store (replaces op_env)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_system_settings`;
CREATE TABLE `op_system_settings` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`       VARCHAR(20)     NOT NULL DEFAULT 'global',             -- 'global' or merchant public_id
  `group_name`  VARCHAR(50)     NOT NULL DEFAULT 'general',            -- UI grouping
  `key`         VARCHAR(100)    NOT NULL,
  `value`       TEXT            NULL,
  `is_secret`   TINYINT(1)      NOT NULL DEFAULT 0,                    -- flag for UI masking
  `created_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ss_scope_key` (`scope`, `key`),
  INDEX `idx_ss_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################################
-- SECTION 3: CUSTOMER MANAGEMENT
-- ############################################################################

-- ----------------------------------------------------------------------------
-- 3.1  op_customers — Customer records per merchant
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_customers`;
CREATE TABLE `op_customers` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`     CHAR(36)        NOT NULL,
  `merchant_id`   BIGINT UNSIGNED NOT NULL,
  `name`          VARCHAR(200)    NOT NULL,
  `email`         VARCHAR(255)    NULL,
  `mobile`        VARCHAR(20)     NULL,
  `metadata`      JSON            NULL,                                -- arbitrary merchant-defined fields
  CONSTRAINT `chk_cust_metadata_json` CHECK (`metadata` IS NULL OR JSON_TYPE(`metadata`) = 'OBJECT'),
  `source`        VARCHAR(20)     NOT NULL DEFAULT 'checkout'
                  CHECK (`source` IN ('manual','checkout','api','import')),
  `status`        VARCHAR(20)     NOT NULL DEFAULT 'active'
                  CHECK (`status` IN ('active','suspended')),
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cust_public_id` (`public_id`),
  UNIQUE KEY `uq_cust_merchant_email` (`merchant_id`, `email`),
  INDEX `idx_cust_merchant_mobile` (`merchant_id`, `mobile`),
  INDEX `idx_cust_status` (`status`),
  CONSTRAINT `fk_cust_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################################
-- SECTION 4: PAYMENT & TRANSACTION FLOW (STATE MACHINE)
-- ############################################################################

-- ----------------------------------------------------------------------------
-- 4.1  op_payment_intents — Checkout session lifecycle
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_payment_intents`;
CREATE TABLE `op_payment_intents` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`       CHAR(36)        NOT NULL,                          -- UUID: pi_xxx
  `merchant_id`     BIGINT UNSIGNED NOT NULL,
  `customer_id`     BIGINT UNSIGNED NULL,
  `idempotency_key` VARCHAR(128)    NULL,                              -- client-provided
  `amount`          DECIMAL(19,4)   NOT NULL
                    CHECK (`amount` > 0),
  `currency`        CHAR(3)         NOT NULL,                          -- ISO 4217
  `description`     VARCHAR(500)    NULL,
  `metadata`        JSON            NULL,                              -- merchant custom data
  CONSTRAINT `chk_pi_metadata_json` CHECK (`metadata` IS NULL OR JSON_TYPE(`metadata`) = 'OBJECT'),
  `source`          VARCHAR(30)     NOT NULL DEFAULT 'api'
                    CHECK (`source` IN ('api','invoice','payment_link','payment_link_default','admin')),
  `source_ref`      VARCHAR(64)     NULL,                              -- invoice/payment-link ref
  `return_url`      VARCHAR(2048)   NULL,
  `webhook_url`     VARCHAR(2048)   NULL,
  `status`          VARCHAR(20)     NOT NULL DEFAULT 'initiated'
                    CHECK (`status` IN ('initiated','requires_action','processing','succeeded','failed','canceled','expired')),
  `gateway_config_id` BIGINT UNSIGNED NULL,                            -- selected gateway
  `expires_at`      DATETIME(6)     NULL,
  `succeeded_at`    DATETIME(6)     NULL,
  `canceled_at`     DATETIME(6)     NULL,
  `failed_at`       DATETIME(6)     NULL,
  `failure_reason`  VARCHAR(500)    NULL,
  `created_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pi_public_id` (`public_id`),
  UNIQUE KEY `uq_pi_merchant_idem` (`merchant_id`, `idempotency_key`),
  INDEX `idx_pi_merchant_status` (`merchant_id`, `status`, `created_at`),
  INDEX `idx_pi_customer` (`customer_id`),
  INDEX `idx_pi_source_ref` (`source`, `source_ref`),
  CONSTRAINT `fk_pi_merchant` FOREIGN KEY (`merchant_id`)     REFERENCES `op_merchants`(`id`)       ON DELETE RESTRICT,
  CONSTRAINT `fk_pi_customer` FOREIGN KEY (`customer_id`)     REFERENCES `op_customers`(`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_pi_gw_cfg`   FOREIGN KEY (`gateway_config_id`) REFERENCES `op_gateway_configs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4.2  op_transactions — Actual payment execution against a gateway
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_transactions`;
CREATE TABLE `op_transactions` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`         CHAR(36)        NOT NULL,                        -- UUID: txn_xxx
  `payment_intent_id` BIGINT UNSIGNED NOT NULL,
  `merchant_id`       BIGINT UNSIGNED NOT NULL,
  `gateway_config_id` BIGINT UNSIGNED NOT NULL,
  `amount`            DECIMAL(19,4)   NOT NULL,                        -- amount charged
  `currency`          CHAR(3)         NOT NULL,
  `local_amount`      DECIMAL(19,4)   NULL,                            -- amount in gateway currency
  `local_currency`    CHAR(3)         NULL,
  `exchange_rate`     DECIMAL(19,8)   NULL,
  `processing_fee`    DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `net_amount`        DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,         -- amount - fees
  `gateway_trx_id`    VARCHAR(128)    NULL,                            -- ID from payment provider
  `gateway_response`  JSON            NULL,                            -- full provider response
  `sender`            VARCHAR(100)    NULL,                            -- payer phone/account
  `sender_type`       VARCHAR(30)     NULL,                            -- Personal/Agent/Merchant
  `trx_slip_url`      VARCHAR(2048)   NULL,
  `customer_info`     JSON            NULL,                            -- name/email/mobile snapshot
  `status`            VARCHAR(20)     NOT NULL DEFAULT 'pending'
                      CHECK (`status` IN ('pending','processing','completed','failed','canceled','refunded','partially_refunded')),
  `completed_at`      DATETIME(6)     NULL,
  `failed_at`         DATETIME(6)     NULL,
  `failure_code`      VARCHAR(50)     NULL,
  `failure_message`   VARCHAR(500)    NULL,
  `version`           INT UNSIGNED    NOT NULL DEFAULT 1,              -- Optimistic lock version
  `created_at`        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  -- JSON VALIDATION (MySQL 8.0.17+)
  CONSTRAINT `chk_txn_gw_response_json` CHECK (`gateway_response` IS NULL OR JSON_TYPE(`gateway_response`) = 'OBJECT'),
  CONSTRAINT `chk_txn_cust_info_json`   CHECK (`customer_info` IS NULL OR JSON_TYPE(`customer_info`) = 'OBJECT'),

  -- COMPOSITE PK required for partitioning
  PRIMARY KEY (`id`, `created_at`),
  UNIQUE KEY `uq_txn_public_id` (`public_id`, `created_at`),          -- UUID globally unique; safe
  INDEX `idx_txn_pi` (`payment_intent_id`),
  INDEX `idx_txn_merchant_status` (`merchant_id`, `status`, `created_at`),
  INDEX `idx_txn_gw_trx` (`gateway_trx_id`),
  INDEX `idx_txn_sender` (`sender`)
  -- FK NOTE: MySQL does NOT support FKs on partitioned tables.
  -- APP-LAYER ENFORCEMENT REQUIRED:
  --   payment_intent_id → op_payment_intents.id  (PaymentService)
  --   merchant_id       → op_merchants.id        (PaymentService)
  --   gateway_config_id → op_gateway_configs.id   (PaymentService)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (YEAR(`created_at`) * 100 + MONTH(`created_at`)) (
  PARTITION p2025_01 VALUES LESS THAN (202502),
  PARTITION p2025_02 VALUES LESS THAN (202503),
  PARTITION p2025_03 VALUES LESS THAN (202504),
  PARTITION p2025_04 VALUES LESS THAN (202505),
  PARTITION p2025_05 VALUES LESS THAN (202506),
  PARTITION p2025_06 VALUES LESS THAN (202507),
  PARTITION p2025_07 VALUES LESS THAN (202508),
  PARTITION p2025_08 VALUES LESS THAN (202509),
  PARTITION p2025_09 VALUES LESS THAN (202510),
  PARTITION p2025_10 VALUES LESS THAN (202511),
  PARTITION p2025_11 VALUES LESS THAN (202512),
  PARTITION p2025_12 VALUES LESS THAN (202601),
  PARTITION p2026_01 VALUES LESS THAN (202602),
  PARTITION p2026_02 VALUES LESS THAN (202603),
  PARTITION p2026_03 VALUES LESS THAN (202604),
  PARTITION p2026_04 VALUES LESS THAN (202605),
  PARTITION p2026_05 VALUES LESS THAN (202606),
  PARTITION p2026_06 VALUES LESS THAN (202607),
  PARTITION p2026_07 VALUES LESS THAN (202608),
  PARTITION p2026_08 VALUES LESS THAN (202609),
  PARTITION p2026_09 VALUES LESS THAN (202610),
  PARTITION p2026_10 VALUES LESS THAN (202611),
  PARTITION p2026_11 VALUES LESS THAN (202612),
  PARTITION p2026_12 VALUES LESS THAN (202701),
  PARTITION p_future  VALUES LESS THAN MAXVALUE
);

-- ----------------------------------------------------------------------------
-- 4.3  op_idempotency_keys — Replay prevention for API calls
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_idempotency_keys`;
CREATE TABLE `op_idempotency_keys` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`           VARCHAR(64)     NOT NULL,                          -- e.g. 'checkout','refund'
  `merchant_id`     BIGINT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(128)    NOT NULL,                          -- client-provided key
  `request_hash`    CHAR(64)        NOT NULL,                          -- SHA-256 of request body
  `response_code`   INT             NULL,                              -- cached HTTP status
  `response_body`   JSON            NULL,                              -- cached response
  `locked_at`       DATETIME(6)     NULL,                              -- concurrency lock
  `locked_until`    DATETIME(6)     NULL,
  `created_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `expires_at`      DATETIME(6)     NOT NULL,                          -- TTL for cleanup

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idem_scope_merchant_key` (`scope`, `merchant_id`, `idempotency_key`),
  INDEX `idx_idem_expires` (`expires_at`),
  CONSTRAINT `fk_idem_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4.4  op_refunds — Partial and full refunds linked to transactions
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_refunds`;
CREATE TABLE `op_refunds` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`       CHAR(36)        NOT NULL,                          -- UUID: rfd_xxx
  `transaction_id`  BIGINT UNSIGNED NOT NULL,
  `merchant_id`     BIGINT UNSIGNED NOT NULL,
  `amount`          DECIMAL(19,4)   NOT NULL
                    CHECK (`amount` > 0),
  `currency`        CHAR(3)         NOT NULL,
  `reason`          VARCHAR(500)    NULL,
  `gateway_refund_id` VARCHAR(128)  NULL,
  `gateway_response`  JSON          NULL,
  `status`          VARCHAR(20)     NOT NULL DEFAULT 'pending'
                    CHECK (`status` IN ('pending','processing','succeeded','failed')),
  `initiated_by`    BIGINT UNSIGNED NULL,                              -- merchant_user who initiated
  `succeeded_at`    DATETIME(6)     NULL,
  `failed_at`       DATETIME(6)     NULL,
  `failure_reason`  VARCHAR(500)    NULL,
  `created_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rfd_public_id` (`public_id`),
  INDEX `idx_rfd_txn` (`transaction_id`),
  INDEX `idx_rfd_merchant_status` (`merchant_id`, `status`),
  -- FK NOTE: fk_rfd_txn removed — op_transactions is partitioned.
  -- APP-LAYER: PaymentService must validate transaction_id before INSERT.
  CONSTRAINT `fk_rfd_merchant`  FOREIGN KEY (`merchant_id`)    REFERENCES `op_merchants`(`id`)       ON DELETE RESTRICT,
  CONSTRAINT `fk_rfd_initiator` FOREIGN KEY (`initiated_by`)   REFERENCES `op_merchant_users`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4.5  op_payment_links — Shareable payment link generation
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_payment_links`;
CREATE TABLE `op_payment_links` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`   CHAR(36)        NOT NULL,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `ref`         VARCHAR(30)     NOT NULL,                              -- short URL slug
  `title`       VARCHAR(300)    NOT NULL,
  `description` TEXT            NULL,
  `image_url`   VARCHAR(2048)   NULL,
  `amount`      DECIMAL(19,4)   NULL,                                  -- NULL = customer decides
  `currency`    CHAR(3)         NOT NULL,
  `quantity`    INT             NOT NULL DEFAULT 0,                     -- 0 = unlimited
  `sold_count`  INT             NOT NULL DEFAULT 0,
  `metadata`    JSON            NULL,
  `expires_at`  DATETIME(6)     NULL,
  `status`      VARCHAR(20)     NOT NULL DEFAULT 'active'
                CHECK (`status` IN ('active','inactive','expired')),
  `created_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pl_public_id` (`public_id`),
  UNIQUE KEY `uq_pl_merchant_ref` (`merchant_id`, `ref`),
  INDEX `idx_pl_status` (`status`),
  CONSTRAINT `fk_pl_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4.6  op_payment_link_fields — Custom form fields per payment link
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_payment_link_fields`;
CREATE TABLE `op_payment_link_fields` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_link_id` BIGINT UNSIGNED NOT NULL,
  `field_type`      VARCHAR(30)     NOT NULL,                          -- text,email,number,tel,file,select
  `field_name`      VARCHAR(150)    NOT NULL,
  `placeholder`     VARCHAR(300)    NULL,
  `options`         JSON            NULL,                              -- for select/radio
  `is_required`     TINYINT(1)      NOT NULL DEFAULT 1,
  `sort_order`      INT             NOT NULL DEFAULT 0,
  `created_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  INDEX `idx_plf_link` (`payment_link_id`),
  CONSTRAINT `fk_plf_link` FOREIGN KEY (`payment_link_id`) REFERENCES `op_payment_links`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4.7  op_invoices — Invoice records
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_invoices`;
CREATE TABLE `op_invoices` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`     CHAR(36)        NOT NULL,
  `merchant_id`   BIGINT UNSIGNED NOT NULL,
  `customer_id`   BIGINT UNSIGNED NULL,
  `ref`           VARCHAR(30)     NOT NULL,
  `currency`      CHAR(3)         NOT NULL,
  `subtotal`      DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `tax_amount`    DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `discount`      DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `shipping`      DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `total`         DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `note`          TEXT            NULL,
  `private_note`  TEXT            NULL,
  `due_date`      DATE            NULL,
  `status`        VARCHAR(20)     NOT NULL DEFAULT 'draft'
                  CHECK (`status` IN ('draft','sent','paid','partially_paid','refunded','canceled','overdue')),
  `paid_at`       DATETIME(6)     NULL,
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_public_id` (`public_id`),
  UNIQUE KEY `uq_inv_merchant_ref` (`merchant_id`, `ref`),
  INDEX `idx_inv_status` (`status`),
  INDEX `idx_inv_customer` (`customer_id`),
  CONSTRAINT `fk_inv_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inv_customer` FOREIGN KEY (`customer_id`) REFERENCES `op_customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4.8  op_invoice_items — Line items per invoice
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_invoice_items`;
CREATE TABLE `op_invoice_items` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id`  BIGINT UNSIGNED NOT NULL,
  `description` VARCHAR(500)    NOT NULL,
  `quantity`    INT             NOT NULL DEFAULT 1,
  `unit_price`  DECIMAL(19,4)   NOT NULL,
  `discount`    DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `tax_rate`    DECIMAL(8,4)    NOT NULL DEFAULT 0.0000,               -- percentage
  `line_total`  DECIMAL(19,4)   NOT NULL,                              -- computed: (qty * price) - discount + tax
  `sort_order`  INT             NOT NULL DEFAULT 0,
  `created_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  INDEX `idx_ii_invoice` (`invoice_id`),
  CONSTRAINT `fk_ii_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `op_invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################################
-- SECTION 5: WEBHOOK SYSTEM
-- ############################################################################

-- ----------------------------------------------------------------------------
-- 5.1  op_webhooks — Outbound webhook endpoint config per merchant
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_webhooks`;
CREATE TABLE `op_webhooks` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`       CHAR(36)        NOT NULL,
  `merchant_id`     BIGINT UNSIGNED NOT NULL,
  `url`             VARCHAR(2048)   NOT NULL,
  `signing_secret`  VARCHAR(255)    NOT NULL,                          -- HMAC secret (encrypted at rest)
  `events`          JSON            NOT NULL,                          -- ["payment.succeeded","refund.created"]
  -- JSON VALIDATION: must be array of event pattern strings
  CONSTRAINT `chk_wh_events_json` CHECK (
    JSON_SCHEMA_VALID('{"type":"array","minItems":1,"items":{"type":"string"}}', `events`)
  ),
  `max_retries`     INT             NOT NULL DEFAULT 5,
  `timeout_seconds` INT             NOT NULL DEFAULT 30,
  `status`          VARCHAR(20)     NOT NULL DEFAULT 'active'
                    CHECK (`status` IN ('active','inactive')),
  `created_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wh_public_id` (`public_id`),
  INDEX `idx_wh_merchant` (`merchant_id`, `status`),
  CONSTRAINT `fk_wh_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5.2  op_webhook_events — Inbound event ingestion + dedupe
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_webhook_events`;
CREATE TABLE `op_webhook_events` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id`       BIGINT UNSIGNED NOT NULL,
  `provider`          VARCHAR(60)     NOT NULL,                        -- gateway slug
  `provider_event_id` VARCHAR(128)    NOT NULL,                        -- unique ID from provider
  `event_type`        VARCHAR(100)    NOT NULL,
  `payload`           JSON            NOT NULL,
  `signature`         VARCHAR(512)    NULL,                            -- raw signature header
  `signature_valid`   TINYINT(1)      NULL,                            -- NULL=unchecked, 1=valid, 0=invalid
  `processed`         TINYINT(1)      NOT NULL DEFAULT 0,
  `processed_at`      DATETIME(6)     NULL,
  `error_message`     VARCHAR(500)    NULL,
  `created_at`        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`, `created_at`),
  UNIQUE KEY `uq_we_provider_event` (`merchant_id`, `provider`, `provider_event_id`, `created_at`),
  INDEX `idx_we_processed` (`processed`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (YEAR(`created_at`) * 100 + MONTH(`created_at`)) (
  PARTITION p2025_01 VALUES LESS THAN (202502),
  PARTITION p2025_02 VALUES LESS THAN (202503),
  PARTITION p2025_03 VALUES LESS THAN (202504),
  PARTITION p2025_04 VALUES LESS THAN (202505),
  PARTITION p2025_05 VALUES LESS THAN (202506),
  PARTITION p2025_06 VALUES LESS THAN (202507),
  PARTITION p2025_07 VALUES LESS THAN (202508),
  PARTITION p2025_08 VALUES LESS THAN (202509),
  PARTITION p2025_09 VALUES LESS THAN (202510),
  PARTITION p2025_10 VALUES LESS THAN (202511),
  PARTITION p2025_11 VALUES LESS THAN (202512),
  PARTITION p2025_12 VALUES LESS THAN (202601),
  PARTITION p2026_01 VALUES LESS THAN (202602),
  PARTITION p2026_02 VALUES LESS THAN (202603),
  PARTITION p2026_03 VALUES LESS THAN (202604),
  PARTITION p2026_04 VALUES LESS THAN (202605),
  PARTITION p2026_05 VALUES LESS THAN (202606),
  PARTITION p2026_06 VALUES LESS THAN (202607),
  PARTITION p2026_07 VALUES LESS THAN (202608),
  PARTITION p2026_08 VALUES LESS THAN (202609),
  PARTITION p2026_09 VALUES LESS THAN (202610),
  PARTITION p2026_10 VALUES LESS THAN (202611),
  PARTITION p2026_11 VALUES LESS THAN (202612),
  PARTITION p2026_12 VALUES LESS THAN (202701),
  PARTITION p_future  VALUES LESS THAN MAXVALUE
);

-- ----------------------------------------------------------------------------
-- 5.3  op_webhook_delivery_logs — Outbound delivery tracking
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_webhook_delivery_logs`;
CREATE TABLE `op_webhook_delivery_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `webhook_id`    BIGINT UNSIGNED NOT NULL,
  `event_type`    VARCHAR(100)    NOT NULL,
  `payload`       JSON            NOT NULL,
  `attempt`       INT             NOT NULL DEFAULT 1,
  `http_status`   INT             NULL,
  `response_body` TEXT            NULL,
  `response_ms`   INT             NULL,                                -- response time in ms
  `status`        VARCHAR(20)     NOT NULL DEFAULT 'pending'
                  CHECK (`status` IN ('pending','delivered','failed','canceled')),
  `next_retry_at` DATETIME(6)     NULL,
  `delivered_at`  DATETIME(6)     NULL,
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  INDEX `idx_wdl_webhook` (`webhook_id`, `status`),
  INDEX `idx_wdl_retry` (`status`, `next_retry_at`),
  CONSTRAINT `fk_wdl_webhook` FOREIGN KEY (`webhook_id`) REFERENCES `op_webhooks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################################
-- SECTION 6: IMMUTABLE DUAL-ENTRY LEDGER SYSTEM
-- ############################################################################
-- DESIGN PRINCIPLE: Ledger entries are APPEND-ONLY / IMMUTABLE.
-- Corrections are made via reverse journal entries, never by UPDATE/DELETE.
-- For every ledger_transaction: SUM(debit) MUST EQUAL SUM(credit).

-- ----------------------------------------------------------------------------
-- 6.1  op_ledger_accounts — Chart of accounts (internal wallets)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_ledger_accounts`;
CREATE TABLE `op_ledger_accounts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`     CHAR(36)        NOT NULL,
  `code`          VARCHAR(20)     NOT NULL,                            -- e.g. 'MERCH-BAL','GW-RECV','PLAT-REV','TAX-PAY'
  `name`          VARCHAR(200)    NOT NULL,
  `account_type`  VARCHAR(20)     NOT NULL
                  CHECK (`account_type` IN ('asset','liability','equity','revenue','expense')),
  `merchant_id`   BIGINT UNSIGNED NULL,                                -- NULL = system/platform account
  `currency`      CHAR(3)         NOT NULL DEFAULT 'BDT',
  `balance`       DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,             -- denormalized running balance
  `is_system`     TINYINT(1)      NOT NULL DEFAULT 0,                  -- system accounts cannot be deleted
  `status`        VARCHAR(20)     NOT NULL DEFAULT 'active'
                  CHECK (`status` IN ('active','frozen','closed')),
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_la_public_id` (`public_id`),
  UNIQUE KEY `uq_la_code_merchant` (`code`, `merchant_id`),
  INDEX `idx_la_type` (`account_type`),
  INDEX `idx_la_merchant` (`merchant_id`),
  -- DEADLOCK PREVENTION: Covering index for hot-path balance UPDATE
  INDEX `idx_la_balance_update` (`id`, `currency`, `balance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6.2  op_ledger_transactions — Journal header (one business event = one journal)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_ledger_transactions`;
CREATE TABLE `op_ledger_transactions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`     CHAR(36)        NOT NULL,
  `event_type`    VARCHAR(60)     NOT NULL,                            -- 'payment_completed','refund_issued','fee_charged','settlement'
  `reference_type` VARCHAR(40)    NOT NULL,                            -- 'transaction','refund','settlement','manual'
  `reference_id`  BIGINT UNSIGNED NULL,                                -- FK to source record (polymorphic)
  `description`   VARCHAR(500)    NULL,
  `currency`      CHAR(3)         NOT NULL,
  `total_amount`  DECIMAL(19,4)   NOT NULL,                            -- absolute value of movement
  `posted_by`     VARCHAR(80)     NULL,                                -- 'system','admin:user_id','api'
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- NO updated_at: journals are immutable

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lt_public_id` (`public_id`),
  UNIQUE KEY `uq_lt_event_ref` (`event_type`, `reference_type`, `reference_id`),
  INDEX `idx_lt_created` (`created_at`),
  INDEX `idx_lt_ref` (`reference_type`, `reference_id`),
  -- DEADLOCK PREVENTION: Covering index for reconciliation lookups
  INDEX `idx_lt_reconcile` (`reference_type`, `reference_id`, `event_type`, `total_amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6.3  op_ledger_entries — Debit/credit lines (IMMUTABLE, no UPDATE/DELETE)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_ledger_entries`;
CREATE TABLE `op_ledger_entries` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ledger_transaction_id` BIGINT UNSIGNED NOT NULL,
  `account_id`            BIGINT UNSIGNED NOT NULL,
  `entry_type`            VARCHAR(10)     NOT NULL
                          CHECK (`entry_type` IN ('debit','credit')),
  `amount`                DECIMAL(19,4)   NOT NULL
                          CHECK (`amount` > 0),
  `currency`              CHAR(3)         NOT NULL,
  `description`           VARCHAR(300)    NULL,
  `created_at`            DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- NO updated_at: entries are immutable

  -- COMPOSITE PK for partitioning
  PRIMARY KEY (`id`, `created_at`),
  INDEX `idx_le_journal` (`ledger_transaction_id`),
  INDEX `idx_le_account` (`account_id`, `created_at`),
  -- DEADLOCK PREVENTION: Covering indexes for fast balance queries
  INDEX `idx_le_balance_calc` (`account_id`, `currency`, `entry_type`, `amount`),
  INDEX `idx_le_statement` (`account_id`, `created_at`, `entry_type`, `amount`)
  -- FK NOTE: FKs removed — op_ledger_entries is partitioned.
  -- APP-LAYER: LedgerService must validate ledger_transaction_id and account_id.
  -- LOCK ORDERING: Always acquire locks in ascending account_id order!
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (YEAR(`created_at`) * 100 + MONTH(`created_at`)) (
  PARTITION p2025_01 VALUES LESS THAN (202502),
  PARTITION p2025_02 VALUES LESS THAN (202503),
  PARTITION p2025_03 VALUES LESS THAN (202504),
  PARTITION p2025_04 VALUES LESS THAN (202505),
  PARTITION p2025_05 VALUES LESS THAN (202506),
  PARTITION p2025_06 VALUES LESS THAN (202507),
  PARTITION p2025_07 VALUES LESS THAN (202508),
  PARTITION p2025_08 VALUES LESS THAN (202509),
  PARTITION p2025_09 VALUES LESS THAN (202510),
  PARTITION p2025_10 VALUES LESS THAN (202511),
  PARTITION p2025_11 VALUES LESS THAN (202512),
  PARTITION p2025_12 VALUES LESS THAN (202601),
  PARTITION p2026_01 VALUES LESS THAN (202602),
  PARTITION p2026_02 VALUES LESS THAN (202603),
  PARTITION p2026_03 VALUES LESS THAN (202604),
  PARTITION p2026_04 VALUES LESS THAN (202605),
  PARTITION p2026_05 VALUES LESS THAN (202606),
  PARTITION p2026_06 VALUES LESS THAN (202607),
  PARTITION p2026_07 VALUES LESS THAN (202608),
  PARTITION p2026_08 VALUES LESS THAN (202609),
  PARTITION p2026_09 VALUES LESS THAN (202610),
  PARTITION p2026_10 VALUES LESS THAN (202611),
  PARTITION p2026_11 VALUES LESS THAN (202612),
  PARTITION p2026_12 VALUES LESS THAN (202701),
  PARTITION p_future  VALUES LESS THAN MAXVALUE
);


-- ############################################################################
-- SECTION 7: SETTLEMENT & DISPUTE MANAGEMENT
-- ############################################################################

-- ----------------------------------------------------------------------------
-- 7.1  op_settlements — Settlement batch records
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_settlements`;
CREATE TABLE `op_settlements` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`       CHAR(36)        NOT NULL,
  `merchant_id`     BIGINT UNSIGNED NOT NULL,
  `currency`        CHAR(3)         NOT NULL,
  `gross_amount`    DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `total_fees`      DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `total_refunds`   DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `total_disputes`  DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `net_amount`      DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,           -- gross - fees - refunds - disputes
  `transaction_count` INT           NOT NULL DEFAULT 0,
  `period_start`    DATETIME(6)     NOT NULL,
  `period_end`      DATETIME(6)     NOT NULL,
  `status`          VARCHAR(20)     NOT NULL DEFAULT 'pending'
                    CHECK (`status` IN ('pending','processing','completed','failed','on_hold')),
  `settled_at`      DATETIME(6)     NULL,
  `payout_reference` VARCHAR(128)   NULL,                              -- bank transfer ref
  `notes`           TEXT            NULL,
  `created_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stl_public_id` (`public_id`),
  INDEX `idx_stl_merchant_status` (`merchant_id`, `status`, `period_start`),
  CONSTRAINT `fk_stl_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7.2  op_settlement_items — Transactions included in a settlement
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_settlement_items`;
CREATE TABLE `op_settlement_items` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `settlement_id`  BIGINT UNSIGNED NOT NULL,
  `transaction_id` BIGINT UNSIGNED NOT NULL,
  `amount`         DECIMAL(19,4)   NOT NULL,
  `fee`            DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `net`            DECIMAL(19,4)   NOT NULL,
  `created_at`     DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  INDEX `idx_si_settlement` (`settlement_id`),
  UNIQUE KEY `uq_si_txn` (`transaction_id`),                           -- each txn settled once
  CONSTRAINT `fk_si_settlement`  FOREIGN KEY (`settlement_id`)  REFERENCES `op_settlements`(`id`)   ON DELETE CASCADE
  -- FK NOTE: fk_si_transaction removed — op_transactions is partitioned.
  -- APP-LAYER: SettlementService must validate transaction_id before INSERT.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7.3  op_disputes — Chargeback and dispute records
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_disputes`;
CREATE TABLE `op_disputes` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`       CHAR(36)        NOT NULL,
  `transaction_id`  BIGINT UNSIGNED NOT NULL,
  `merchant_id`     BIGINT UNSIGNED NOT NULL,
  `amount`          DECIMAL(19,4)   NOT NULL,
  `currency`        CHAR(3)         NOT NULL,
  `reason`          VARCHAR(100)    NOT NULL,                          -- 'fraudulent','duplicate','product_not_received','unrecognized','other'
  `description`     TEXT            NULL,
  `evidence`        JSON            NULL,                              -- uploaded evidence metadata
  -- JSON VALIDATION: evidence must be array of {type, url} objects
  CONSTRAINT `chk_dsp_evidence_json` CHECK (
    `evidence` IS NULL OR JSON_SCHEMA_VALID('{"type":"array","items":{"type":"object","required":["type","url"],"properties":{"type":{"type":"string"},"url":{"type":"string"},"name":{"type":"string"}}}}', `evidence`)
  ),
  `gateway_dispute_id` VARCHAR(128) NULL,
  `status`          VARCHAR(30)     NOT NULL DEFAULT 'needs_response'
                    CHECK (`status` IN ('needs_response','under_review','won','lost','accepted','expired')),
  `due_date`        DATE            NULL,                              -- deadline for evidence
  `resolved_at`     DATETIME(6)     NULL,
  `created_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dsp_public_id` (`public_id`),
  INDEX `idx_dsp_txn` (`transaction_id`),
  INDEX `idx_dsp_merchant_status` (`merchant_id`, `status`),
  -- FK NOTE: fk_dsp_txn removed — op_transactions is partitioned.
  -- APP-LAYER: DisputeService must validate transaction_id before INSERT.
  CONSTRAINT `fk_dsp_merchant` FOREIGN KEY (`merchant_id`)    REFERENCES `op_merchants`(`id`)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################################
-- SECTION 8: FEE & PRICING RULES
-- ############################################################################

-- ----------------------------------------------------------------------------
-- 8.1  op_fee_rules — Configurable fee structure per merchant/gateway
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_fee_rules`;
CREATE TABLE `op_fee_rules` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id`   BIGINT UNSIGNED NOT NULL,
  `gateway_id`    BIGINT UNSIGNED NULL,                                -- NULL = applies to all gateways
  `name`          VARCHAR(150)    NOT NULL,
  `fee_type`      VARCHAR(20)     NOT NULL
                  CHECK (`fee_type` IN ('fixed','percentage','tiered','flat_rate')),
  `fixed_amount`  DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `percentage`    DECIMAL(8,4)    NOT NULL DEFAULT 0.0000,
  `min_fee`       DECIMAL(19,4)   NULL,                                -- floor
  `max_fee`       DECIMAL(19,4)   NULL,                                -- cap
  `currency`      CHAR(3)         NOT NULL DEFAULT 'BDT',
  `tiers`         JSON            NULL,                                -- for tiered: [{"min":0,"max":1000,"fee":10},...]
  -- JSON VALIDATION: tiers must be array of {min, max, fee} objects
  CONSTRAINT `chk_fr_tiers_json` CHECK (
    `tiers` IS NULL OR JSON_SCHEMA_VALID('{"type":"array","items":{"type":"object","required":["min","max","fee"],"properties":{"min":{"type":"number","minimum":0},"max":{"type":"number","minimum":0},"fee":{"type":"number","minimum":0}}}}', `tiers`)
  ),
  `applies_to`    VARCHAR(30)     NOT NULL DEFAULT 'payment'
                  CHECK (`applies_to` IN ('payment','refund','settlement','payout')),
  `priority`      INT             NOT NULL DEFAULT 0,                  -- higher = applied first
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `effective_from` DATETIME(6)    NULL,
  `effective_to`  DATETIME(6)     NULL,
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  INDEX `idx_fr_merchant_gw` (`merchant_id`, `gateway_id`, `is_active`),
  INDEX `idx_fr_applies` (`applies_to`, `is_active`),
  CONSTRAINT `fk_fr_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fr_gateway`  FOREIGN KEY (`gateway_id`)  REFERENCES `op_gateways`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################################
-- SECTION 9: SECURITY & AUDIT LOGGING
-- ############################################################################

-- ----------------------------------------------------------------------------
-- 9.1  op_audit_logs — WHO did WHAT, WHEN, to WHICH entity
--      Immutable append-only table for compliance and forensics.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_audit_logs`;
CREATE TABLE `op_audit_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id`   BIGINT UNSIGNED NULL,                                -- NULL for system/global events
  `actor_type`    VARCHAR(30)     NOT NULL,                            -- 'merchant_user','system','api_key','webhook'
  `actor_id`      VARCHAR(80)     NOT NULL,                            -- user public_id or 'system'
  `action`        VARCHAR(100)    NOT NULL,                            -- 'payment.created','api_key.revoked','refund.initiated'
  `entity_type`   VARCHAR(60)     NOT NULL,                            -- 'transaction','api_key','merchant','refund'
  `entity_id`     VARCHAR(80)     NOT NULL,                            -- public_id of affected entity
  `old_payload`   JSON            NULL,                                -- state before change
  `new_payload`   JSON            NULL,                                -- state after change
  `ip_address`    VARBINARY(16)   NULL,                                -- IPv4 (4 bytes) or IPv6 (16 bytes)
  `ip_text`       VARCHAR(45)     NULL,                                -- human-readable IP
  `user_agent`    VARCHAR(500)    NULL,
  `request_id`    CHAR(36)        NULL,                                -- correlation ID for tracing
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- NO updated_at: audit logs are immutable

  PRIMARY KEY (`id`, `created_at`),
  INDEX `idx_al_merchant_action` (`merchant_id`, `action`, `created_at`),
  INDEX `idx_al_entity` (`entity_type`, `entity_id`, `created_at`),
  INDEX `idx_al_actor` (`actor_type`, `actor_id`, `created_at`),
  INDEX `idx_al_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (YEAR(`created_at`) * 100 + MONTH(`created_at`)) (
  PARTITION p2025_01 VALUES LESS THAN (202502),
  PARTITION p2025_02 VALUES LESS THAN (202503),
  PARTITION p2025_03 VALUES LESS THAN (202504),
  PARTITION p2025_04 VALUES LESS THAN (202505),
  PARTITION p2025_05 VALUES LESS THAN (202506),
  PARTITION p2025_06 VALUES LESS THAN (202507),
  PARTITION p2025_07 VALUES LESS THAN (202508),
  PARTITION p2025_08 VALUES LESS THAN (202509),
  PARTITION p2025_09 VALUES LESS THAN (202510),
  PARTITION p2025_10 VALUES LESS THAN (202511),
  PARTITION p2025_11 VALUES LESS THAN (202512),
  PARTITION p2025_12 VALUES LESS THAN (202601),
  PARTITION p2026_01 VALUES LESS THAN (202602),
  PARTITION p2026_02 VALUES LESS THAN (202603),
  PARTITION p2026_03 VALUES LESS THAN (202604),
  PARTITION p2026_04 VALUES LESS THAN (202605),
  PARTITION p2026_05 VALUES LESS THAN (202606),
  PARTITION p2026_06 VALUES LESS THAN (202607),
  PARTITION p2026_07 VALUES LESS THAN (202608),
  PARTITION p2026_08 VALUES LESS THAN (202609),
  PARTITION p2026_09 VALUES LESS THAN (202610),
  PARTITION p2026_10 VALUES LESS THAN (202611),
  PARTITION p2026_11 VALUES LESS THAN (202612),
  PARTITION p2026_12 VALUES LESS THAN (202701),
  PARTITION p_future  VALUES LESS THAN MAXVALUE
);

-- ----------------------------------------------------------------------------
-- 9.2  op_login_attempts — Brute-force and credential stuffing protection
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_login_attempts`;
CREATE TABLE `op_login_attempts` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier`  VARCHAR(255)    NOT NULL,                              -- username or email attempted
  `kind`        VARCHAR(20)     NOT NULL DEFAULT 'login',              -- 'login' or 'forgot'
  `ip_address`  VARCHAR(45)     NOT NULL,
  `user_agent`  VARCHAR(500)    NULL,
  `success`     TINYINT(1)      NOT NULL DEFAULT 0,
  `failure_reason` VARCHAR(100) NULL,                                  -- 'invalid_password','account_suspended','2fa_failed'
  `created_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`, `created_at`),
  INDEX `idx_la_kind_identifier` (`kind`, `identifier`, `created_at`),
  INDEX `idx_la_identifier` (`identifier`, `created_at`),
  INDEX `idx_la_ip` (`ip_address`, `created_at`),
  INDEX `idx_la_cleanup` (`created_at`)                                -- for periodic TTL cleanup
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (YEAR(`created_at`) * 100 + MONTH(`created_at`)) (
  PARTITION p2025_01 VALUES LESS THAN (202502),
  PARTITION p2025_02 VALUES LESS THAN (202503),
  PARTITION p2025_03 VALUES LESS THAN (202504),
  PARTITION p2025_04 VALUES LESS THAN (202505),
  PARTITION p2025_05 VALUES LESS THAN (202506),
  PARTITION p2025_06 VALUES LESS THAN (202507),
  PARTITION p2025_07 VALUES LESS THAN (202508),
  PARTITION p2025_08 VALUES LESS THAN (202509),
  PARTITION p2025_09 VALUES LESS THAN (202510),
  PARTITION p2025_10 VALUES LESS THAN (202511),
  PARTITION p2025_11 VALUES LESS THAN (202512),
  PARTITION p2025_12 VALUES LESS THAN (202601),
  PARTITION p2026_01 VALUES LESS THAN (202602),
  PARTITION p2026_02 VALUES LESS THAN (202603),
  PARTITION p2026_03 VALUES LESS THAN (202604),
  PARTITION p2026_04 VALUES LESS THAN (202605),
  PARTITION p2026_05 VALUES LESS THAN (202606),
  PARTITION p2026_06 VALUES LESS THAN (202607),
  PARTITION p2026_07 VALUES LESS THAN (202608),
  PARTITION p2026_08 VALUES LESS THAN (202609),
  PARTITION p2026_09 VALUES LESS THAN (202610),
  PARTITION p2026_10 VALUES LESS THAN (202611),
  PARTITION p2026_11 VALUES LESS THAN (202612),
  PARTITION p2026_12 VALUES LESS THAN (202701),
  PARTITION p_future  VALUES LESS THAN MAXVALUE
);


-- ############################################################################
-- SECTION 10: DEVICE / COMPANION APP / SMS MATCHING
-- ############################################################################

-- ----------------------------------------------------------------------------
-- 10.1  op_devices — Companion app device registry
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_devices`;
CREATE TABLE `op_devices` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`       CHAR(36)        NOT NULL,
  `device_code`     VARCHAR(40)     NOT NULL,                          -- hardware identifier
  `name`            VARCHAR(150)    NULL,                              -- user-given name
  `model`           VARCHAR(100)    NULL,
  `os_version`      VARCHAR(50)     NULL,
  `app_version`     VARCHAR(20)     NULL,
  `otp`             VARCHAR(20)     NULL,                              -- pairing OTP (hashed)
  `status`          VARCHAR(20)     NOT NULL DEFAULT 'pairing'
                    CHECK (`status` IN ('pairing','active','inactive','revoked')),
  `paired_at`       DATETIME(6)     NULL,
  `last_sync_at`    DATETIME(6)     NULL,
  `created_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dev_public_id` (`public_id`),
  UNIQUE KEY `uq_dev_code` (`device_code`),
  INDEX `idx_dev_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10.2  op_companion_tokens — Auth tokens for companion devices
--       Tokens are rotated on each use (sliding window).
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_companion_tokens`;
CREATE TABLE `op_companion_tokens` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`     BIGINT UNSIGNED NOT NULL,
  `token_hash`    CHAR(64)        NOT NULL,                            -- SHA-256 of bearer token
  `token_prefix`  VARCHAR(12)     NOT NULL,                            -- first chars for identification
  `scopes`        JSON            NULL,                                -- device-level permissions
  -- JSON VALIDATION: scopes must be array of strings
  CONSTRAINT `chk_ct_scopes_json` CHECK (`scopes` IS NULL OR JSON_SCHEMA_VALID('{"type":"array","items":{"type":"string","minLength":1}}', `scopes`)),
  `last_used_at`  DATETIME(6)     NULL,
  `last_ip`       VARCHAR(45)     NULL,
  `expires_at`    DATETIME(6)     NOT NULL,
  `status`        VARCHAR(20)     NOT NULL DEFAULT 'active'
                  CHECK (`status` IN ('active','revoked','expired')),
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_token_hash` (`token_hash`),
  INDEX `idx_ct_device` (`device_id`, `status`),
  INDEX `idx_ct_expires` (`expires_at`),
  CONSTRAINT `fk_ct_device` FOREIGN KEY (`device_id`) REFERENCES `op_devices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10.3  op_sms_data — SMS transaction matching engine
--       Core automation: companion app forwards incoming SMS from MFS providers.
--       System parses amount/trx_id/sender and matches against pending transactions.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `op_sms_data`;
CREATE TABLE `op_sms_data` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`     BIGINT UNSIGNED NOT NULL,
  `merchant_id`   BIGINT UNSIGNED NULL,                                -- resolved after matching
  `source`        VARCHAR(10)     NOT NULL DEFAULT 'app'
                  CHECK (`source` IN ('app','web','api')),
  `sender_key`    VARCHAR(30)     NOT NULL,                            -- gateway account identifier
  `type`          VARCHAR(20)     NOT NULL DEFAULT 'Personal'
                  CHECK (`type` IN ('Personal','Agent','Merchant')),    -- alias: sender_type for legacy compat
  `sim_slot`      VARCHAR(6)      NULL,
  `number`        VARCHAR(20)     NULL,                                -- sender phone (legacy: 'number')
  `amount`        DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `currency`      CHAR(3)         NOT NULL DEFAULT 'BDT',
  `trx_id`        VARCHAR(128)    NULL,                                -- parsed transaction ID
  `balance`       DECIMAL(19,4)   NULL,                                -- post-transaction balance
  `raw_message`   TEXT            NULL,                                -- original SMS body
  `message`       TEXT            NULL,                                -- alias for raw_message (legacy compat)
  `parsed_data`   JSON            NULL,                                -- structured extraction
  `status`        VARCHAR(20)     NOT NULL DEFAULT 'pending'
                  CHECK (`status` IN ('pending','approved','used','error','manual_review')),
  `matched_txn_id` BIGINT UNSIGNED NULL,                               -- linked op_transactions.id
  `matched_at`    DATETIME(6)     NULL,
  `reason`        VARCHAR(300)    NULL,                                -- match failure reason
  `created_date`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- legacy column name
  `updated_date`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- legacy column name
  `created_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at`    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (`id`),
  INDEX `idx_sms_device` (`device_id`),
  INDEX `idx_sms_trx_id` (`trx_id`),
  INDEX `idx_sms_sender` (`sender_key`, `amount`),
  INDEX `idx_sms_status` (`status`, `created_at`),
  INDEX `idx_sms_matched_txn` (`matched_txn_id`),
  CONSTRAINT `fk_sms_device`  FOREIGN KEY (`device_id`)      REFERENCES `op_devices`(`id`)       ON DELETE RESTRICT
  -- FK NOTE: fk_sms_txn removed — op_transactions is partitioned.
  -- APP-LAYER: SmsMatchingService must validate matched_txn_id before UPDATE.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################################
-- SECTION 11: LEGACY COMPATIBILITY TABLES (Admin Panel Controllers)
-- ############################################################################

-- 11.1 op_env
CREATE TABLE IF NOT EXISTS `op_env` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id`      VARCHAR(64)     NOT NULL DEFAULT 'both',
  `option_name`   VARCHAR(255)    NOT NULL,
  `value`         TEXT            NULL,
  `created_date`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_env_brand_option` (`brand_id`, `option_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.2 op_brands
CREATE TABLE IF NOT EXISTS `op_brands` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id`               VARCHAR(64)     NOT NULL,
  `identify_name`          VARCHAR(255)    NOT NULL DEFAULT '',
  `name`                   VARCHAR(255)    NOT NULL DEFAULT '',
  `currency_code`          CHAR(3)         NOT NULL DEFAULT 'BDT',
  `currency_symbol`        VARCHAR(10)     NOT NULL DEFAULT '৳',
  `timezone`               VARCHAR(64)     NOT NULL DEFAULT 'Asia/Dhaka',
  `language`               VARCHAR(10)     NOT NULL DEFAULT 'en',
  `theme`                  VARCHAR(64)     NOT NULL DEFAULT 'own-pay',
  `logo`                   VARCHAR(2048)   NULL,
  `favicon`                VARCHAR(2048)   NULL,
  `payment_tolerance`      DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `autoExchange`           VARCHAR(20)     NOT NULL DEFAULT 'disabled',
  `street_address`         VARCHAR(500)    NULL,
  `city_town`              VARCHAR(100)    NULL,
  `postal_code`            VARCHAR(20)     NULL,
  `country`                VARCHAR(100)    NULL,
  `support_phone_number`   VARCHAR(30)     NULL,
  `support_email_address`  VARCHAR(255)    NULL,
  `support_website`        VARCHAR(2048)   NULL,
  `whatsapp_number`        VARCHAR(30)     NULL,
  `telegram`               VARCHAR(100)    NULL,
  `facebook_messenger`     VARCHAR(100)    NULL,
  `facebook_page`          VARCHAR(100)    NULL,
  `primary_color`          VARCHAR(20)     NULL DEFAULT '#6366f1',
  `status`                 VARCHAR(20)     NOT NULL DEFAULT 'active',
  `created_date`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brands_brand_id` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.3 op_admin
CREATE TABLE IF NOT EXISTS `op_admin` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `a_id`           VARCHAR(64)     NOT NULL,
  `name`           VARCHAR(200)    NOT NULL DEFAULT '',
  `email`          VARCHAR(255)    NOT NULL,
  `username`       VARCHAR(80)     NOT NULL,
  `password`       VARCHAR(255)    NOT NULL,
  `temp_password`  VARCHAR(255)    NULL,
  `role`           VARCHAR(20)     NOT NULL DEFAULT 'admin',
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'active',
  `2fa_status`     VARCHAR(20)     NOT NULL DEFAULT 'disable',
  `2fa_secret`     VARCHAR(64)     NULL,
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_email` (`email`),
  UNIQUE KEY `uq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.4 op_transaction
CREATE TABLE IF NOT EXISTS `op_transaction` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref`             VARCHAR(64)     NOT NULL,
  `trx_id`          VARCHAR(128)    NULL,
  `brand_id`        VARCHAR(64)     NOT NULL,
  `gateway_id`      VARCHAR(64)     NOT NULL DEFAULT '',
  `amount`          DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `currency`        CHAR(3)         NOT NULL DEFAULT 'BDT',
  `local_currency`  CHAR(3)         NOT NULL DEFAULT 'BDT',            -- local currency for FX
  `fee`             DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `processing_fee`  DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,           -- alias for fee (V2 compat)
  `discount_amount` DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `net_amount`      DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `local_net_amount` DECIMAL(19,4)  NOT NULL DEFAULT 0.0000,           -- net amount in local currency
  `sender_key`      VARCHAR(128)    NULL,
  `sender_type`     VARCHAR(20)     NOT NULL DEFAULT 'Personal',       -- Personal/Agent/Merchant
  `sender`          VARCHAR(100)    NULL,                              -- resolved sender phone/account
  `type`            VARCHAR(30)     NULL,
  `status`          VARCHAR(20)     NOT NULL DEFAULT 'initiated',
  `version`         INT UNSIGNED    NOT NULL DEFAULT 1,
  `customer_name`   VARCHAR(200)    NULL,
  `customer_email`  VARCHAR(255)    NULL,
  `customer_phone`  VARCHAR(30)     NULL,
  `customer_info`   JSON            NULL,                              -- full customer snapshot (V2 compat)
  `metadata`        JSON            NULL,                              -- arbitrary merchant metadata
  `webhook_url`     VARCHAR(2048)   NULL,                              -- per-transaction webhook
  `note`            TEXT            NULL,
  `created_date`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaction_ref` (`ref`),
  INDEX `idx_transaction_brand_status` (`brand_id`, `status`),
  INDEX `idx_transaction_trx_id` (`trx_id`),
  INDEX `idx_transaction_sender_key` (`sender_key`),
  INDEX `idx_transaction_created` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.5 op_currency
CREATE TABLE IF NOT EXISTS `op_currency` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `code`           CHAR(3)         NOT NULL,
  `name`           VARCHAR(80)     NOT NULL DEFAULT '',
  `symbol`         VARCHAR(10)     NOT NULL DEFAULT '',
  `rate`           DECIMAL(19,8)   NOT NULL DEFAULT 1.00000000,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'active',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_currency_brand` (`brand_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.6 op_permission
CREATE TABLE IF NOT EXISTS `op_permission` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `a_id`           VARCHAR(64)     NOT NULL,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `permission`     TEXT            NULL,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'active',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_permission_aid` (`a_id`, `brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.7 op_addon
CREATE TABLE IF NOT EXISTS `op_addon` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `addon_id`       VARCHAR(64)     NOT NULL,
  `slug`           VARCHAR(100)    NOT NULL,
  `name`           VARCHAR(200)    NOT NULL DEFAULT '',
  `description`    TEXT            NULL,
  `version`        VARCHAR(20)     NOT NULL DEFAULT '1.0.0',
  `author`         VARCHAR(200)    NULL,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'inactive',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_addon_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.8 op_addon_parameter
CREATE TABLE IF NOT EXISTS `op_addon_parameter` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `addon_id`       VARCHAR(64)     NOT NULL,
  `option_name`    VARCHAR(255)    NOT NULL,
  `value`          TEXT            NULL,
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_addon_param_addon` (`addon_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.9 op_plugins
CREATE TABLE IF NOT EXISTS `op_plugins` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`           VARCHAR(100)    NOT NULL,
  `name`           VARCHAR(200)    NOT NULL DEFAULT '',
  `type`           VARCHAR(30)     NOT NULL DEFAULT 'gateway',
  `entrypoint`     VARCHAR(500)    NOT NULL DEFAULT '',
  `load_order`     INT             NOT NULL DEFAULT 100,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'inactive',
  `version`        VARCHAR(20)     NOT NULL DEFAULT '1.0.0',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plugins_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.10 op_webhook_log
CREATE TABLE IF NOT EXISTS `op_webhook_log` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `ref`            VARCHAR(64)     NOT NULL,
  `gateway_id`     VARCHAR(64)     NOT NULL DEFAULT '',
  `type`           VARCHAR(30)     NULL,
  `response`       TEXT            NULL,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'received',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_webhook_log_ref` (`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.11 op_gateways_parameter
CREATE TABLE IF NOT EXISTS `op_gateways_parameter` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gateway_id`     VARCHAR(64)     NOT NULL,
  `option_name`    VARCHAR(255)    NOT NULL,
  `value`          TEXT            NULL,
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_gw_param_gw` (`gateway_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.12 op_invoice
CREATE TABLE IF NOT EXISTS `op_invoice` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id`     VARCHAR(64)     NOT NULL,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `customer_id`    VARCHAR(64)     NULL,
  `title`          VARCHAR(255)    NOT NULL DEFAULT '',
  `description`    TEXT            NULL,
  `amount`         DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `currency`       CHAR(3)         NOT NULL DEFAULT 'BDT',
  `tax`            DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `discount`       DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `total`          DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'draft',
  `due_date`       DATE            NULL,
  `note`           TEXT            NULL,
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_id` (`invoice_id`),
  INDEX `idx_invoice_brand` (`brand_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.13 op_payment_link
CREATE TABLE IF NOT EXISTS `op_payment_link` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `link_id`        VARCHAR(64)     NOT NULL,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `title`          VARCHAR(255)    NOT NULL DEFAULT '',
  `description`    TEXT            NULL,
  `amount`         DECIMAL(19,4)   NULL,
  `currency`       CHAR(3)         NOT NULL DEFAULT 'BDT',
  `slug`           VARCHAR(255)    NOT NULL,
  `type`           VARCHAR(20)     NOT NULL DEFAULT 'standard',
  `redirect_url`   VARCHAR(2048)   NULL,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'active',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_link_id` (`link_id`),
  INDEX `idx_payment_link_brand` (`brand_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.14 op_payment_link_field
CREATE TABLE IF NOT EXISTS `op_payment_link_field` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `link_id`        VARCHAR(64)     NOT NULL,
  `field_name`     VARCHAR(100)    NOT NULL,
  `field_type`     VARCHAR(30)     NOT NULL DEFAULT 'text',
  `required`       TINYINT(1)      NOT NULL DEFAULT 0,
  `sort_order`     INT             NOT NULL DEFAULT 0,
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_plf_link` (`link_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.15 op_customer
CREATE TABLE IF NOT EXISTS `op_customer` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`    VARCHAR(64)     NOT NULL,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `name`           VARCHAR(200)    NOT NULL DEFAULT '',
  `email`          VARCHAR(255)    NULL,
  `phone`          VARCHAR(30)     NULL,
  `address`        TEXT            NULL,
  `note`           TEXT            NULL,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'active',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_id` (`customer_id`),
  INDEX `idx_customer_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.16 op_api
CREATE TABLE IF NOT EXISTS `op_api` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_id`         VARCHAR(64)     NOT NULL,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `name`           VARCHAR(200)    NOT NULL DEFAULT '',
  `api_key`        VARCHAR(255)    NOT NULL,
  `secret_key`     VARCHAR(255)    NOT NULL,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'active',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_id` (`api_id`),
  INDEX `idx_api_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.17 op_faq
CREATE TABLE IF NOT EXISTS `op_faq` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `faq_id`         VARCHAR(64)     NOT NULL,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `question`       VARCHAR(500)    NOT NULL,
  `answer`         TEXT            NULL,
  `sort_order`     INT             NOT NULL DEFAULT 0,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'active',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_faq_id` (`faq_id`),
  INDEX `idx_faq_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.18 op_domain
CREATE TABLE IF NOT EXISTS `op_domain` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain_id`      VARCHAR(64)     NOT NULL,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `domain`         VARCHAR(255)    NOT NULL,
  `type`           VARCHAR(20)     NOT NULL DEFAULT 'custom',
  `ssl_status`     VARCHAR(20)     NOT NULL DEFAULT 'pending',
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'active',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_domain_id` (`domain_id`),
  INDEX `idx_domain_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.19 op_device
CREATE TABLE IF NOT EXISTS `op_device` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`      VARCHAR(64)     NOT NULL,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `name`           VARCHAR(200)    NOT NULL DEFAULT '',
  `type`           VARCHAR(30)     NOT NULL DEFAULT 'mobile',
  `identifier`     VARCHAR(255)    NULL,
  `last_active`    DATETIME        NULL,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'active',
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_id` (`device_id`),
  INDEX `idx_device_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.20 op_balance_verification
CREATE TABLE IF NOT EXISTS `op_balance_verification` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `verification_id` VARCHAR(64)   NOT NULL,
  `brand_id`       VARCHAR(64)    NOT NULL,
  `gateway_id`     VARCHAR(64)    NOT NULL DEFAULT '',
  `expected_amount` DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
  `actual_amount`  DECIMAL(19,4)  NULL,
  `currency`       CHAR(3)        NOT NULL DEFAULT 'BDT',
  `status`         VARCHAR(20)    NOT NULL DEFAULT 'pending',
  `note`           TEXT           NULL,
  `created_date`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bv_id` (`verification_id`),
  INDEX `idx_bv_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11.21 op_browser_log
CREATE TABLE IF NOT EXISTS `op_browser_log` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id`       VARCHAR(64)     NOT NULL DEFAULT '',
  `user_id`        VARCHAR(64)     NULL,
  `action`         VARCHAR(100)    NOT NULL DEFAULT '',
  `ip`             VARCHAR(45)     NULL,
  `user_agent`     TEXT            NULL,
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_browser_log_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################################
-- SCHEMA COMPLETE — COMMIT
-- ############################################################################

COMMIT;

-- ============================================================================
-- OWN PAY SCHEMA v1.0 — TABLE SUMMARY
-- ============================================================================
--
-- Section 1: Core Identity & Merchant Management
--   1.  op_merchants              — Tenant / business entity
--   2.  op_roles                  — RBAC role definitions
--   3.  op_permissions            — Granular permission tokens
--   4.  op_role_permissions       — Role ↔ Permission junction
--   5.  op_merchant_users         — Users with RBAC per merchant
--   6.  op_api_keys               — Bearer token registry (hash-only)
--   7.  op_domains                — Whitelisted domains per merchant
--
-- Section 2: Gateway & Configuration
--   8.  op_gateways               — Gateway plugin registry
--   9.  op_gateway_configs        — Per-merchant gateway settings
--  10.  op_currencies             — ISO 4217 currency definitions
--  11.  op_exchange_rates         — FX rate snapshots
--  12.  op_system_settings        — Key-value config store
--
-- Section 3: Customer Management
--  13.  op_customers              — Customer records per merchant
--
-- Section 4: Payment & Transaction Flow
--  14.  op_payment_intents        — Checkout session lifecycle
--  15.  op_transactions           — Payment execution records
--  16.  op_idempotency_keys       — API replay prevention
--  17.  op_refunds                — Partial/full refund records
--  18.  op_payment_links          — Shareable payment links
--  19.  op_payment_link_fields    — Custom form fields
--  20.  op_invoices               — Invoice records
--  21.  op_invoice_items          — Invoice line items
--
-- Section 5: Webhook System
--  22.  op_webhooks               — Outbound endpoint config
--  23.  op_webhook_events         — Inbound event dedupe
--  24.  op_webhook_delivery_logs  — Outbound delivery tracking
--
-- Section 6: Dual-Entry Ledger
--  25.  op_ledger_accounts        — Chart of accounts
--  26.  op_ledger_transactions    — Journal headers (immutable)
--  27.  op_ledger_entries         — Debit/credit lines (immutable)
--
-- Section 7: Settlement & Disputes
--  28.  op_settlements            — Settlement batch records
--  29.  op_settlement_items       — Txn ↔ Settlement junction
--  30.  op_disputes               — Chargeback/dispute records
--
-- Section 8: Fee & Pricing
--  31.  op_fee_rules              — Fee structure configuration
--
-- Section 9: Security & Audit
--  32.  op_audit_logs             — Immutable audit trail
--  33.  op_login_attempts         — Brute-force protection
--
-- Section 10: Device / Companion / SMS
--  34.  op_devices                — Companion app devices
--  35.  op_companion_tokens       — Device auth tokens
--  36.  op_sms_data               — SMS transaction matching
--
-- Section 11: Legacy Compatibility (Admin Panel)
--  37.  op_env                    — Key-value environment store
--  38.  op_brands                 — Legacy brand/merchant store
--  39.  op_admin                  — Legacy admin/staff users
--  40.  op_transaction            — Legacy transaction (singular)
--  41.  op_currency               — Legacy currency (singular)
--  42.  op_permission             — Legacy permission store
--  43.  op_addon                  — Addon/module registry
--  44.  op_addon_parameter        — Addon config key-value
--  45.  op_plugins                — Plugin registry
--  46.  op_webhook_log            — Legacy webhook log
--  47.  op_gateways_parameter     — Legacy gateway config
--  48.  op_invoice                — Legacy invoice (singular)
--  49.  op_payment_link           — Legacy payment link (singular)
--  50.  op_payment_link_field     — Payment link custom fields
--  51.  op_customer               — Legacy customer (singular)
--  52.  op_api                    — Legacy API keys
--  53.  op_faq                    — FAQ entries
--  54.  op_domain                 — Domain management
--  55.  op_device                 — Device management
--  56.  op_balance_verification   — Balance verification
--  57.  op_browser_log            — Browser activity log
--

--
-- Section 12: Mobile Companion & SMS Parsing (Pillar 1 & 2)
-- ----------------------------------------------------------------------------
-- 12.1 op_device_pairing_tokens
CREATE TABLE IF NOT EXISTS `op_device_pairing_tokens` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `otp_hash`    VARCHAR(64)     NOT NULL COMMENT 'SHA-256 hash of the 6-digit OTP',
    `brand_id`    VARCHAR(64)     NOT NULL COMMENT 'FK → op_brands.brand_id (VARCHAR match)',
    `created_by`  BIGINT UNSIGNED NOT NULL COMMENT 'Admin user id who generated the OTP',
    `expires_at`  DATETIME(6)     NOT NULL COMMENT 'now() + 5 minutes',
    `is_used`     TINYINT(1)      NOT NULL DEFAULT 0,
    `used_at`     DATETIME(6)     NULL,
    `created_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX `idx_otp_lookup` (`otp_hash`, `is_used`, `expires_at`),
    INDEX `idx_brand`       (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12.2 op_paired_devices
CREATE TABLE IF NOT EXISTS `op_paired_devices` (
    `id`                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `device_uuid`               CHAR(36)        NOT NULL COMMENT 'Server-generated UUID v4',
    `brand_id`                  VARCHAR(64)     NOT NULL COMMENT 'FK → op_brands.brand_id (VARCHAR match)',
    `device_name`               VARCHAR(100)    NOT NULL DEFAULT '',
    `fingerprint_hash`          VARCHAR(64)     NOT NULL COMMENT 'SHA-256 of android_id:cert_sha256',
    `aes_key_encrypted`         TEXT            NOT NULL COMMENT 'AES-256 key encrypted via FieldEncryptor',
    `refresh_token_hash`        VARCHAR(64)     NOT NULL COMMENT 'SHA-256 of the opaque refresh token',
    `refresh_token_expires_at`  DATETIME(6)     NOT NULL,
    `jwt_secret`                VARCHAR(128)    NOT NULL COMMENT 'Per-device HMAC-SHA256 key (hex)',
    `platform`                  ENUM('android','ios') NOT NULL DEFAULT 'android',
    `app_version`               VARCHAR(20)     NOT NULL DEFAULT '',
    `last_seen_at`              DATETIME(6)     NULL,
    `revoked_at`                DATETIME(6)     NULL COMMENT 'Admin-set; non-null = revoked',
    `created_at`                DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at`                DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY `uq_device_uuid`   (`device_uuid`),
    INDEX `idx_brand_active`      (`brand_id`, `revoked_at`),
    INDEX `idx_refresh_lookup`    (`refresh_token_hash`),
    INDEX `idx_fingerprint`       (`fingerprint_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12.3 op_mobile_notifications
CREATE TABLE IF NOT EXISTS `op_mobile_notifications` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `device_uuid` CHAR(36)        NOT NULL COMMENT 'FK → op_paired_devices.device_uuid',
    `type`        VARCHAR(50)     NOT NULL COMMENT 'e.g. sms_parsed, payment_received, alert',
    `title`       VARCHAR(200)    NOT NULL,
    `body`        TEXT            NULL,
    `payload`     JSON            NULL COMMENT 'Arbitrary data for deep linking',
    `is_read`     TINYINT(1)      NOT NULL DEFAULT 0,
    `read_at`     DATETIME(6)     NULL,
    `created_at`  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT `fk_mn_device` FOREIGN KEY (`device_uuid`) REFERENCES `op_paired_devices`(`device_uuid`) ON DELETE CASCADE,
    INDEX `idx_device_poll`   (`device_uuid`, `is_read`, `created_at`),
    INDEX `idx_device_cleanup`(`device_uuid`, `is_read`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12.4 op_sms_templates
CREATE TABLE IF NOT EXISTS `op_sms_templates` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sender_pattern`   VARCHAR(50)  NOT NULL COMMENT 'Sender match: e.g. "bKash", "16247", "Nagad"',
    `regex_pattern`    TEXT         NOT NULL COMMENT 'PHP PCRE regex with named capture groups',
    `transaction_type` ENUM('credit','debit','both') NOT NULL DEFAULT 'credit',
    `provider_name`    VARCHAR(50)  NOT NULL COMMENT 'Human-readable: bKash, Nagad, Rocket, etc.',
    `currency`         CHAR(3)      NOT NULL DEFAULT 'BDT',
    `balance_verify`   TINYINT(1)   NOT NULL DEFAULT 1,
    `priority`         INT          NOT NULL DEFAULT 100 COMMENT 'Lower = try first',
    `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
    `description`      VARCHAR(255) NULL COMMENT 'Admin note about this template',
    `created_at`       DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at`       DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    INDEX `idx_sms_tpl_sender` (`sender_pattern`, `is_active`, `priority`),
    INDEX `idx_sms_tpl_provider` (`provider_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12.5 op_sms_parsed
CREATE TABLE IF NOT EXISTS `op_sms_parsed` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_uuid`      CHAR(36)        NOT NULL COMMENT 'FK → op_paired_devices.device_uuid',
    `brand_id`         INT UNSIGNED    NOT NULL,
    `local_id`         INT UNSIGNED    NULL COMMENT 'Client-side local ID for dedup',
    `sender`           VARCHAR(50)     NOT NULL COMMENT 'SMS sender ID (bKash, 16247, etc.)',
    `received_at`      DATETIME        NOT NULL COMMENT 'Original SMS timestamp from device',
    `encrypted_raw`    TEXT            NOT NULL COMMENT 'Original AES-encrypted payload (audit)',
    `raw_message`      TEXT            NULL COMMENT 'Decrypted SMS body (for admin review)',
    `parsed_amount`    DECIMAL(19,4)   NULL COMMENT 'Extracted transaction amount',
    `parsed_trx_id`    VARCHAR(128)    NULL COMMENT 'Extracted transaction ID',
    `parsed_sender`    VARCHAR(100)    NULL COMMENT 'Extracted sender phone/name',
    `parsed_balance`   DECIMAL(19,4)   NULL COMMENT 'Extracted post-tx balance',
    `parsed_type`      ENUM('credit','debit','unknown') NOT NULL DEFAULT 'unknown',
    `parse_method`     ENUM('regex','heuristic','unparsed') NOT NULL DEFAULT 'unparsed',
    `template_id`      INT UNSIGNED    NULL COMMENT 'FK → op_sms_templates.id if regex matched',
    `parse_confidence` ENUM('high','medium','low') NOT NULL DEFAULT 'low',
    `status`           ENUM('accepted','duplicate','parse_error','admin_review','used') NOT NULL DEFAULT 'accepted',
    `processed_at`     DATETIME        NULL COMMENT 'Server parse timestamp',
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sp_device` (`device_uuid`, `created_at`),
    INDEX `idx_sp_brand` (`brand_id`, `created_at`),
    INDEX `idx_sp_trx_id` (`parsed_trx_id`),
    INDEX `idx_sp_status` (`status`, `parse_method`),
    INDEX `idx_sp_dedup` (`device_uuid`, `sender`, `received_at`),
    INDEX `idx_sp_template` (`template_id`),
    CONSTRAINT `fk_sp_device` FOREIGN KEY (`device_uuid`) REFERENCES `op_paired_devices`(`device_uuid`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sp_template` FOREIGN KEY (`template_id`) REFERENCES `op_sms_templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `op_sms_templates` (`sender_pattern`, `regex_pattern`, `transaction_type`, `provider_name`, `currency`, `balance_verify`, `priority`, `description`) VALUES
('bKash', '/You have received Tk\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*from\\s*(?P<sender_number>\\d{11})(?:.*?TrxID\\s*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:new balance|Balance)\\s*(?:is\\s*)?Tk\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'bKash', 'BDT', 1, 10, 'bKash money received (personal transfer)'),
('bKash', '/Payment of Tk\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*(?:has been\\s*)?received(?:.*?from\\s*(?P<sender_number>\\d{11}))?(?:.*?TrxID\\s*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)\\s*(?:is\\s*)?Tk\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'bKash', 'BDT', 1, 15, 'bKash payment received (merchant)'),
('bKash', '/Cash Out Tk\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*(?:to|from)\\s*(?P<sender_number>\\d{11})(?:.*?TrxID\\s*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)\\s*(?:is\\s*)?Tk\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'debit', 'bKash', 'BDT', 1, 20, 'bKash cash out'),
('bKash', '/You have sent Tk\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*to\\s*(?P<sender_number>\\d{11})(?:.*?TrxID\\s*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)\\s*(?:is\\s*)?Tk\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'debit', 'bKash', 'BDT', 1, 25, 'bKash send money'),
('Nagad', '/(?:You have received|Received)\\s*Tk\\.?\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*from\\s*(?P<sender_number>\\d{11})(?:.*?TxnID[:\\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)[:\\s]*Tk\\.?\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'Nagad', 'BDT', 1, 10, 'Nagad money received'),
('Nagad', '/(?:You have sent|Sent)\\s*Tk\\.?\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*to\\s*(?P<sender_number>\\d{11})(?:.*?TxnID[:\\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)[:\\s]*Tk\\.?\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'debit', 'Nagad', 'BDT', 1, 15, 'Nagad send money'),
('16216', '/(?:Received|Cash In)\\s*Tk\\.?\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*from\\s*(?P<sender_number>\\d{11,})(?:.*?TxnId[:\\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:Bal|Balance)[:\\s]*Tk\\.?\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'Rocket', 'BDT', 1, 10, 'Rocket/DBBL received'),
('Upay', '/(?:You have received|Received)\\s*(?:Tk\\.?)?\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*(?:BDT\\s*)?from\\s*(?P<sender_number>\\d{11})(?:.*?(?:TrxID|TxnID)[:\\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)[:\\s]*(?:Tk\\.?)?\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'Upay', 'BDT', 1, 10, 'Upay received'),
('SureCash', '/(?:received|Received)\\s*Tk\\.?\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*from\\s*(?P<sender_number>\\d{11})(?:.*?(?:TrxID|Ref)[:\\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)[:\\s]*Tk\\.?\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'SureCash', 'BDT', 1, 10, 'SureCash received');


-- TOTAL: 62 tables (41 V2 + 21 legacy) | 5 partitioned | 11 JSON constraints | 4 deadlock indexes
-- ============================================================================
-- Schema Version: 2.1 (Unified Production — Schema + Hardening + Audit Fixes)
-- Fixes applied (v2.1):
--   • op_sms_parsed.status ENUM: added 'used' value (required by SmsVerificationJob)
--   • op_sms_parsed: DECIMAL(15,2) → DECIMAL(19,4), VARCHAR widths aligned to V2 standard
--   • op_sms_data: renamed match_status→status, phone_number→number, added message alias,
--                  replaced updated_at/created_at with legacy _date columns for app compat
--   • op_transaction: added sender_type, sender, local_net_amount, local_currency,
--                     processing_fee, discount_amount, customer_info, metadata, webhook_url
--   • op_device_pairing_tokens: brand_id INT→VARCHAR(64), created_by INT→BIGINT, DATETIME→DATETIME(6)
--   • op_paired_devices: brand_id INT→VARCHAR(64), all DATETIME→DATETIME(6)
--   • op_mobile_notifications: DATETIME→DATETIME(6), added FK to op_paired_devices
--   • op_sms_templates: DATETIME→DATETIME(6)
--   • Removed duplicate Section 11 header comment
