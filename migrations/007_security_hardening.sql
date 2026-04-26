-- ============================================================================
-- OWN PAY — Security Hardening Migration v1
-- ============================================================================
-- Date: 2026-04-26
-- Source: docs/security_audit/full_codebase_audit.md
--
-- For UPGRADE installs only. Greenfield installs already include these via
-- master_install.sql v2.2.
--
-- This migration applies the schema-level changes needed for findings:
--   F6  — TOTP replay prevention (last_otp_window column)
--   F10 — API key prefix uniqueness (UNIQUE KEY on key_prefix)
--   F15 — Forgot-password rate limit (kind column on op_login_attempts)
-- ============================================================================

SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ----------------------------------------------------------------------------
-- F6: TOTP replay prevention
--   Tracks the most recent successful TOTP window per user. Codes from
--   already-consumed windows are rejected even within the discrepancy range.
-- ----------------------------------------------------------------------------
ALTER TABLE `op_merchant_users`
  ADD COLUMN `last_otp_window` BIGINT UNSIGNED NULL DEFAULT NULL
  AFTER `two_fa_secret`;

-- ----------------------------------------------------------------------------
-- F10: API key prefix uniqueness
--   Prevents UI ambiguity from two keys sharing the first 8 chars.
--   ApiKeyService::generate() must regenerate-on-collision.
-- ----------------------------------------------------------------------------
ALTER TABLE `op_api_keys`
  ADD UNIQUE KEY `uq_ak_prefix` (`key_prefix`);

-- ----------------------------------------------------------------------------
-- F15: Forgot-password rate limit
--   Reuses op_login_attempts with a `kind` discriminator (login | forgot).
-- ----------------------------------------------------------------------------
ALTER TABLE `op_login_attempts`
  ADD COLUMN `kind` VARCHAR(20) NOT NULL DEFAULT 'login' AFTER `username`,
  ADD INDEX `idx_la_kind_email` (`kind`, `username`);
