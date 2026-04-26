-- ============================================================================
-- Migration 009: SMS Parsing Engine Tables
-- Created: 2026-04-27
-- Part 2: op_sms_templates (regex patterns) + op_sms_parsed (mobile-submitted)
-- ============================================================================

-- 1. op_sms_templates — Regex patterns for MFS SMS parsing (Tier 1 engine)
CREATE TABLE IF NOT EXISTS `op_sms_templates` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sender_pattern`   VARCHAR(50)  NOT NULL COMMENT 'Sender match: e.g. "bKash", "16247", "Nagad"',
    `regex_pattern`    TEXT         NOT NULL COMMENT 'PHP PCRE regex with named capture groups',
    `transaction_type` ENUM('credit','debit','both') NOT NULL DEFAULT 'credit',
    `provider_name`    VARCHAR(50)  NOT NULL COMMENT 'Human-readable: bKash, Nagad, Rocket, etc.',
    `priority`         INT          NOT NULL DEFAULT 100 COMMENT 'Lower = try first',
    `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
    `description`      VARCHAR(255) NULL COMMENT 'Admin note about this template',
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_sms_tpl_sender` (`sender_pattern`, `is_active`, `priority`),
    INDEX `idx_sms_tpl_provider` (`provider_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. op_sms_parsed — Mobile-submitted parsed SMS data
--    This is separate from the legacy op_sms_data table (which was for web/API sources).
--    This table stores SMS submitted by the mobile companion app.
CREATE TABLE IF NOT EXISTS `op_sms_parsed` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_uuid`      CHAR(36)        NOT NULL COMMENT 'FK → op_paired_devices.device_uuid',
    `brand_id`         INT UNSIGNED    NOT NULL,
    `local_id`         INT UNSIGNED    NULL COMMENT 'Client-side local ID for dedup',
    `sender`           VARCHAR(50)     NOT NULL COMMENT 'SMS sender ID (bKash, 16247, etc.)',
    `received_at`      DATETIME        NOT NULL COMMENT 'Original SMS timestamp from device',
    `encrypted_raw`    TEXT            NOT NULL COMMENT 'Original AES-encrypted payload (audit)',
    `raw_message`      TEXT            NULL COMMENT 'Decrypted SMS body (for admin review)',
    `parsed_amount`    DECIMAL(15,2)   NULL COMMENT 'Extracted transaction amount',
    `parsed_trx_id`    VARCHAR(50)     NULL COMMENT 'Extracted transaction ID',
    `parsed_sender`    VARCHAR(50)     NULL COMMENT 'Extracted sender phone/name',
    `parsed_balance`   DECIMAL(15,2)   NULL COMMENT 'Extracted post-tx balance',
    `parsed_type`      ENUM('credit','debit','unknown') NOT NULL DEFAULT 'unknown',
    `parse_method`     ENUM('regex','heuristic','unparsed') NOT NULL DEFAULT 'unparsed',
    `template_id`      INT UNSIGNED    NULL COMMENT 'FK → op_sms_templates.id if regex matched',
    `parse_confidence` ENUM('high','medium','low') NOT NULL DEFAULT 'low',
    `status`           ENUM('accepted','duplicate','parse_error','admin_review') NOT NULL DEFAULT 'accepted',
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

-- 3. Seed initial Bangladeshi MFS regex templates
INSERT INTO `op_sms_templates` (`sender_pattern`, `regex_pattern`, `transaction_type`, `provider_name`, `priority`, `description`) VALUES

-- bKash: Cash In / Received
('bKash', '/You have received Tk\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*from\\s*(?P<sender_number>\\d{11})(?:.*?TrxID\\s*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:new balance|Balance)\\s*(?:is\\s*)?Tk\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'bKash', 10, 'bKash money received (personal transfer)'),

-- bKash: Payment received (merchant)
('bKash', '/Payment of Tk\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*(?:has been\\s*)?received(?:.*?from\\s*(?P<sender_number>\\d{11}))?(?:.*?TrxID\\s*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)\\s*(?:is\\s*)?Tk\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'bKash', 15, 'bKash payment received (merchant)'),

-- bKash: Cash Out (debit)
('bKash', '/Cash Out Tk\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*(?:to|from)\\s*(?P<sender_number>\\d{11})(?:.*?TrxID\\s*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)\\s*(?:is\\s*)?Tk\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'debit', 'bKash', 20, 'bKash cash out'),

-- bKash: Send Money (debit)
('bKash', '/You have sent Tk\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*to\\s*(?P<sender_number>\\d{11})(?:.*?TrxID\\s*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)\\s*(?:is\\s*)?Tk\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'debit', 'bKash', 25, 'bKash send money'),

-- Nagad: Received
('Nagad', '/(?:You have received|Received)\\s*Tk\\.?\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*from\\s*(?P<sender_number>\\d{11})(?:.*?TxnID[:\\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)[:\\s]*Tk\\.?\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'Nagad', 10, 'Nagad money received'),

-- Nagad: Send (debit)
('Nagad', '/(?:You have sent|Sent)\\s*Tk\\.?\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*to\\s*(?P<sender_number>\\d{11})(?:.*?TxnID[:\\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)[:\\s]*Tk\\.?\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'debit', 'Nagad', 15, 'Nagad send money'),

-- Rocket (DBBL): Received
('16216', '/(?:Received|Cash In)\\s*Tk\\.?\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*from\\s*(?P<sender_number>\\d{11,})(?:.*?TxnId[:\\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:Bal|Balance)[:\\s]*Tk\\.?\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'Rocket', 10, 'Rocket/DBBL received'),

-- Upay: Received
('Upay', '/(?:You have received|Received)\\s*(?:Tk\\.?)?\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*(?:BDT\\s*)?from\\s*(?P<sender_number>\\d{11})(?:.*?(?:TrxID|TxnID)[:\\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)[:\\s]*(?:Tk\\.?)?\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'Upay', 10, 'Upay received'),

-- SureCash: Received
('SureCash', '/(?:received|Received)\\s*Tk\\.?\\s*(?P<amount>[\\d,]+(?:\\.\\d{1,2})?)\\s*from\\s*(?P<sender_number>\\d{11})(?:.*?(?:TrxID|Ref)[:\\s]*(?P<trx_id>[A-Z0-9]+))?(?:.*?(?:balance|Balance)[:\\s]*Tk\\.?\\s*(?P<balance>[\\d,]+(?:\\.\\d{1,2})?))?/i', 'credit', 'SureCash', 10, 'SureCash received');
