-- ============================================================================
-- OwnPay Legacy Compatibility Migration
-- ============================================================================
-- Purpose: Create legacy-named tables expected by admin panel controllers.
--          The V2 greenfield schema uses plural/renamed tables, but the
--          admin controllers still reference the legacy naming convention.
-- ============================================================================

SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- ap_env — Key-value environment/settings store (used by EnvironmentService)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_env` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id`      VARCHAR(64)     NOT NULL DEFAULT 'both',
  `option_name`   VARCHAR(255)    NOT NULL,
  `value`         TEXT            NULL,
  `created_date`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_env_brand_option` (`brand_id`, `option_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ap_brands — Legacy brand/merchant store (mapped from ap_merchants)
-- Used by: ThemeController, StaffController, general-setting.php, adapter.php
-- Columns expected: brand_id, identify_name, name, currency_code, currency_symbol,
--   timezone, language, theme, logo, favicon, payment_tolerance, autoExchange,
--   street_address, city_town, postal_code, country, support_phone_number,
--   support_email_address, support_website, whatsapp_number, telegram,
--   facebook_messenger, facebook_page
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_brands` (
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

-- ----------------------------------------------------------------------------
-- ap_admin — Legacy admin/staff user table
-- Used by: StaffController, adapter.php
-- Columns expected: a_id, name, email, username, password, role, status,
--   2fa_status, 2fa_secret, temp_password
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_admin` (
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

-- ----------------------------------------------------------------------------
-- ap_transaction — Legacy transaction table (singular)
-- Used by: DashboardController, TransactionController, adapter.php
-- Columns expected: id, ref, trx_id, brand_id, gateway_id, amount, currency,
--   status, created_date, version, sender_key, type
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_transaction` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref`            VARCHAR(64)     NOT NULL,
  `trx_id`         VARCHAR(128)    NULL,
  `brand_id`       VARCHAR(64)     NOT NULL,
  `gateway_id`     VARCHAR(64)     NOT NULL DEFAULT '',
  `amount`         DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `currency`       CHAR(3)         NOT NULL DEFAULT 'BDT',
  `fee`            DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `net_amount`     DECIMAL(19,4)   NOT NULL DEFAULT 0.0000,
  `sender_key`     VARCHAR(128)    NULL,
  `type`           VARCHAR(30)     NULL,
  `status`         VARCHAR(20)     NOT NULL DEFAULT 'initiated',
  `version`        INT UNSIGNED    NOT NULL DEFAULT 1,
  `customer_name`  VARCHAR(200)    NULL,
  `customer_email` VARCHAR(255)    NULL,
  `customer_phone` VARCHAR(30)     NULL,
  `note`           TEXT            NULL,
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaction_ref` (`ref`),
  INDEX `idx_transaction_brand_status` (`brand_id`, `status`),
  INDEX `idx_transaction_trx_id` (`trx_id`),
  INDEX `idx_transaction_created` (`created_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ap_currency — Legacy currency table (singular)
-- Used by: DashboardController, TransactionController, general-setting.php
-- Columns expected: id, brand_id, code, name, symbol, rate, status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_currency` (
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

-- ----------------------------------------------------------------------------
-- ap_permission — Legacy permission/staff access table (singular)
-- Used by: StaffController
-- Columns expected: id, a_id, brand_id, permission, status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_permission` (
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

-- ----------------------------------------------------------------------------
-- ap_addon — Addon/module registry
-- Used by: AddonController, adapter.php
-- Columns expected: id, addon_id, slug, name, description, version, status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_addon` (
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

-- ----------------------------------------------------------------------------
-- ap_addon_parameter — Addon configuration key-value pairs
-- Used by: AddonController, adapter.php
-- Columns expected: id, addon_id, option_name, value
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_addon_parameter` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `addon_id`       VARCHAR(64)     NOT NULL,
  `option_name`    VARCHAR(255)    NOT NULL,
  `value`          TEXT            NULL,
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_addon_param_addon` (`addon_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ap_plugins — Plugin registry (used by PluginRegistry)
-- Columns expected: slug, type, entrypoint, load_order, status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_plugins` (
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

-- ----------------------------------------------------------------------------
-- ap_webhook_log — Legacy webhook delivery log
-- Used by: TransactionController
-- Columns expected: id, brand_id, ref, gateway_id, type, response, created_date
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_webhook_log` (
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

-- ----------------------------------------------------------------------------
-- ap_gateways_parameter — Legacy gateway config key-value pairs
-- Used by: TransactionController
-- Columns expected: id, gateway_id, option_name, value
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_gateways_parameter` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gateway_id`     VARCHAR(64)     NOT NULL,
  `option_name`    VARCHAR(255)    NOT NULL,
  `value`          TEXT            NULL,
  `created_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_date`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_gw_param_gw` (`gateway_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ap_invoice — Legacy invoice table (singular)
-- Used by: InvoiceController
-- Columns expected: id, invoice_id, brand_id, customer_id, amount, currency,
--   status, due_date, created_date
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_invoice` (
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

-- ----------------------------------------------------------------------------
-- ap_payment_link — Legacy payment link table (singular)
-- Used by: PaymentLinkController
-- Columns expected: id, link_id, brand_id, title, amount, currency, status,
--   slug, created_date
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_payment_link` (
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

-- ----------------------------------------------------------------------------
-- ap_payment_link_field — Payment link custom fields
-- Used by: PaymentLinkController
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_payment_link_field` (
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

-- ----------------------------------------------------------------------------
-- ap_customer — Legacy customer table (singular)
-- Used by: CustomerController
-- Columns expected: id, customer_id, brand_id, name, email, phone, status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_customer` (
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

-- ----------------------------------------------------------------------------
-- ap_api — Legacy API key table
-- Used by: ApiKeyController
-- Columns expected: id, api_id, brand_id, name, api_key, secret_key, status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_api` (
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

-- ----------------------------------------------------------------------------
-- ap_faq — FAQ table
-- Used by: FaqController
-- Columns expected: id, faq_id, brand_id, question, answer, sort_order, status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_faq` (
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

-- ----------------------------------------------------------------------------
-- ap_domain — Domain management table
-- Used by: DomainController
-- Columns expected: id, domain_id, brand_id, domain, status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_domain` (
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

-- ----------------------------------------------------------------------------
-- ap_device — Device management table
-- Used by: DeviceController
-- Columns expected: id, device_id, brand_id, name, type, status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_device` (
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

-- ----------------------------------------------------------------------------
-- ap_balance_verification — Balance verification requests
-- Used by: BalanceVerificationController
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_balance_verification` (
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

-- ----------------------------------------------------------------------------
-- ap_browser_log — Browser/activity log
-- Used by: Logging system
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_browser_log` (
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

-- ============================================================================
-- SEED DATA
-- ============================================================================

-- Seed admin brand
INSERT IGNORE INTO `ap_brands` (`brand_id`, `identify_name`, `name`, `currency_code`, `currency_symbol`, `timezone`, `language`)
SELECT id, business_name, business_name, base_currency, '৳', timezone, locale
FROM `ap_merchants` LIMIT 1;

-- Seed default currency
INSERT IGNORE INTO `ap_currency` (`brand_id`, `code`, `name`, `symbol`, `rate`)
SELECT b.`brand_id`, b.`currency_code`, b.`currency_code`, b.`currency_symbol`, 1.00000000
FROM `ap_brands` b LIMIT 1;
