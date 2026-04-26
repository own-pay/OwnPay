-- ============================================================================
-- Phase 2.0 — Migration 004: Add optimistic locking to legacy transaction table
-- ============================================================================
-- Adds a `version` column to prevent race conditions during concurrent
-- status updates. Every UPDATE must include `AND version = :current_version`
-- and set `version = version + 1`. A 0-affected-rows result indicates a
-- concurrent modification (optimistic lock failure).
--
-- SAFE TO RUN: Additive change only. No data is modified.
-- ============================================================================

-- For the LEGACY transaction table (op_{prefix}transaction):
-- Run this once per environment, replacing {prefix} with your db_prefix.
-- Example: ALTER TABLE `op_transaction` ADD COLUMN `version` ...

ALTER TABLE `transaction`
    ADD COLUMN `version` INT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'Optimistic lock version — increment on every UPDATE'
    AFTER `updated_date`;

-- Index for the optimistic lock WHERE clause
-- (covered by the existing PK/ref index + this version column)
-- No separate index needed as version is used in conjunction with ref/id.

-- For the NEW SOA transaction table (if already deployed):
-- ALTER TABLE `op_transactions`
--     ADD COLUMN `version` INT UNSIGNED NOT NULL DEFAULT 1
--     COMMENT 'Optimistic lock version'
--     AFTER `updated_at`;
