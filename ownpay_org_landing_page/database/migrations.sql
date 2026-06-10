-- OwnPay Landing Page Database Schema Migration DDL
-- File: migrations.sql

CREATE TABLE IF NOT EXISTS `op_org_subscribers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `subscribed_at` DATETIME NOT NULL,
    `mailerlite_synced` TINYINT(1) DEFAULT 0,
    `mailerlite_id` VARCHAR(100) DEFAULT NULL,
    `source` VARCHAR(50) DEFAULT 'landing_page',
    KEY `idx_email` (`email`),
    KEY `idx_mailerlite_synced` (`mailerlite_synced`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_org_donations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `donor_name` VARCHAR(100) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'BDT',
    `tier` VARCHAR(50) DEFAULT 'Community',
    `message` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `public_display` TINYINT(1) DEFAULT 1,
    `unique_id` VARCHAR(50) NOT NULL UNIQUE,
    KEY `idx_unique_id` (`unique_id`),
    KEY `idx_public_display` (`public_display`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_org_sponsors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `logo_path` VARCHAR(255) NOT NULL,
    `website_url` VARCHAR(255) NOT NULL,
    `nofollow` TINYINT(1) DEFAULT 1,
    `description` TEXT DEFAULT NULL,
    `tier` VARCHAR(50) DEFAULT 'Community',
    `display_order` INT DEFAULT 0,
    `active` TINYINT(1) DEFAULT 1,
    KEY `idx_slug` (`slug`),
    KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_org_contributors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `role` VARCHAR(150) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `profile_url` VARCHAR(255) DEFAULT NULL,
    `avatar_path` VARCHAR(255) DEFAULT NULL,
    `display_order` INT DEFAULT 0,
    `active` TINYINT(1) DEFAULT 1,
    KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_org_admin_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `two_factor_secret` VARCHAR(100) DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_org_admin_sessions` (
    `id` VARCHAR(255) PRIMARY KEY,
    `user_id` INT NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(255) NOT NULL,
    `last_activity` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_org_settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_org_audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `created_at` DATETIME NOT NULL,
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
