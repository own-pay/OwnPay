-- Migration 008: Mobile Companion App — Device Pairing & Notifications
-- Part of Pillar 1: Device Pairing & Auth

-- ────────────────────────────────────────────────────────────────────
-- 1. op_device_pairing_tokens
--    Short-lived OTPs for the 5-minute pairing handshake.
--    OTP is stored as SHA-256 hash (never plaintext).
-- ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `op_device_pairing_tokens` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `otp_hash`    VARCHAR(64)     NOT NULL COMMENT 'SHA-256 hash of the 6-digit OTP',
    `brand_id`    INT UNSIGNED    NOT NULL COMMENT 'FK → op_brands.brand_id',
    `created_by`  INT UNSIGNED    NOT NULL COMMENT 'Admin user who generated the OTP',
    `expires_at`  DATETIME        NOT NULL COMMENT 'now() + 5 minutes',
    `is_used`     TINYINT(1)      NOT NULL DEFAULT 0,
    `used_at`     DATETIME        NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_otp_lookup` (`otp_hash`, `is_used`, `expires_at`),
    INDEX `idx_brand`       (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────
-- 2. op_paired_devices
--    One row per paired mobile device. Holds JWT secret, AES key,
--    refresh token hash, and device fingerprint for pinning.
-- ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `op_paired_devices` (
    `id`                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `device_uuid`               CHAR(36)        NOT NULL COMMENT 'Server-generated UUID v4',
    `brand_id`                  INT UNSIGNED    NOT NULL COMMENT 'FK → op_brands.brand_id',
    `device_name`               VARCHAR(100)    NOT NULL DEFAULT '',
    `fingerprint_hash`          VARCHAR(64)     NOT NULL COMMENT 'SHA-256 of android_id:cert_sha256',
    `aes_key_encrypted`         TEXT            NOT NULL COMMENT 'AES-256 key encrypted via FieldEncryptor',
    `refresh_token_hash`        VARCHAR(64)     NOT NULL COMMENT 'SHA-256 of the opaque refresh token',
    `refresh_token_expires_at`  DATETIME        NOT NULL,
    `jwt_secret`                VARCHAR(128)    NOT NULL COMMENT 'Per-device HMAC-SHA256 key (hex)',
    `platform`                  ENUM('android','ios') NOT NULL DEFAULT 'android',
    `app_version`               VARCHAR(20)     NOT NULL DEFAULT '',
    `last_seen_at`              DATETIME        NULL,
    `revoked_at`                DATETIME        NULL COMMENT 'Admin-set; non-null = revoked',
    `created_at`                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_device_uuid`   (`device_uuid`),
    INDEX `idx_brand_active`      (`brand_id`, `revoked_at`),
    INDEX `idx_refresh_lookup`    (`refresh_token_hash`),
    INDEX `idx_fingerprint`       (`fingerprint_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────────
-- 3. op_mobile_notifications
--    Server-side notification queue polled by the mobile app.
--    Replaces FCM/APNs with a self-hosted short-polling model.
-- ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `op_mobile_notifications` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `device_uuid` CHAR(36)        NOT NULL COMMENT 'FK → op_paired_devices.device_uuid',
    `type`        VARCHAR(50)     NOT NULL COMMENT 'e.g. sms_parsed, payment_received, alert',
    `title`       VARCHAR(200)    NOT NULL,
    `body`        TEXT            NULL,
    `payload`     JSON            NULL COMMENT 'Arbitrary data for deep linking',
    `is_read`     TINYINT(1)      NOT NULL DEFAULT 0,
    `read_at`     DATETIME        NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_device_poll`   (`device_uuid`, `is_read`, `created_at`),
    INDEX `idx_device_cleanup`(`device_uuid`, `is_read`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
