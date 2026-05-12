-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 09, 2026 at 07:24 AM
-- Server version: 9.1.0
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ownpay`
--

-- --------------------------------------------------------

--
-- Table structure for table `op_api_keys`
--

CREATE TABLE `op_api_keys` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_prefix` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` json DEFAULT NULL,
  `last_used_at` datetime(6) DEFAULT NULL,
  `expires_at` datetime(6) DEFAULT NULL,
  `status` enum('active','revoked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_audit_logs`
--

CREATE TABLE `op_audit_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED DEFAULT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `action` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint UNSIGNED DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_cache`
--

CREATE TABLE `op_cache` (
  `key_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumblob NOT NULL,
  `expires_at` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_comm_log`
--

CREATE TABLE `op_comm_log` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED DEFAULT NULL,
  `channel` enum('sms','email','telegram','webhook') COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `provider` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('queued','sent','delivered','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_currencies`
--

CREATE TABLE `op_currencies` (
  `id` bigint UNSIGNED NOT NULL,
  `code` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decimal_places` tinyint UNSIGNED NOT NULL DEFAULT '2',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_customers`
--

CREATE TABLE `op_customers` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_enc` varbinary(512) DEFAULT NULL,
  `email_enc` varbinary(512) DEFAULT NULL,
  `email_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_enc` varbinary(512) DEFAULT NULL,
  `phone_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_device_pairing_tokens`
--

CREATE TABLE `op_device_pairing_tokens` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_disputes`
--

CREATE TABLE `op_disputes` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `transaction_id` bigint UNSIGNED NOT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `evidence` json DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` enum('open','under_review','won','lost','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `resolved_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_domains`
--

CREATE TABLE `op_domains` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `domain` varchar(253) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('checkout','admin','api') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'checkout',
  `verification_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dns_verified` tinyint(1) NOT NULL DEFAULT '0',
  `dns_verified_at` datetime(6) DEFAULT NULL,
  `ssl_status` enum('none','pending','active','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `redirect_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','pending','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_exchange_rates`
--

CREATE TABLE `op_exchange_rates` (
  `id` bigint UNSIGNED NOT NULL,
  `base_currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate` decimal(18,8) NOT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_fee_rules`
--

CREATE TABLE `op_fee_rules` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED DEFAULT NULL,
  `gateway_slug` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('flat','percentage','tiered') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percentage',
  `value` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `min_fee` decimal(15,2) DEFAULT NULL,
  `max_fee` decimal(15,2) DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BDT',
  `tiers` json DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_gateways`
--

CREATE TABLE `op_gateways` (
  `id` bigint UNSIGNED NOT NULL,
  `slug` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('api','manual','plugin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'api',
  `logo_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_builtin` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_gateway_configs`
--

CREATE TABLE `op_gateway_configs` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `gateway_id` bigint UNSIGNED NOT NULL,
  `credentials_enc` text COLLATE utf8mb4_unicode_ci,
  `settings` json DEFAULT NULL,
  `mode` enum('live','sandbox') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sandbox',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inactive',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_idempotency_keys`
--

CREATE TABLE `op_idempotency_keys` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `response_code` smallint UNSIGNED DEFAULT NULL,
  `response_body` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `expires_at` datetime(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_invoices`
--

CREATE TABLE `op_invoices` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` bigint UNSIGNED DEFAULT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total` decimal(15,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BDT',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `due_date` date DEFAULT NULL,
  `status` enum('draft','sent','paid','overdue','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `paid_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_invoice_items`
--

CREATE TABLE `op_invoice_items` (
  `id` bigint UNSIGNED NOT NULL,
  `invoice_id` bigint UNSIGNED NOT NULL,
  `description` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `unit_price` decimal(15,2) NOT NULL,
  `total` decimal(15,2) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_ledger_accounts`
--

CREATE TABLE `op_ledger_accounts` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('asset','liability','equity','revenue','expense') COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BDT',
  `balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_ledger_entries`
--

CREATE TABLE `op_ledger_entries` (
  `id` bigint UNSIGNED NOT NULL,
  `ledger_transaction_id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED NOT NULL,
  `type` enum('debit','credit') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_ledger_transactions`
--

CREATE TABLE `op_ledger_transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_login_attempts`
--

CREATE TABLE `op_login_attempts` (
  `id` bigint UNSIGNED NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_maintenance_locks`
--

CREATE TABLE `op_maintenance_locks` (
  `id` bigint UNSIGNED NOT NULL,
  `reason` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `initiated_by` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `retry_after` int UNSIGNED NOT NULL DEFAULT '600',
  `locked_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `unlocked_at` datetime(6) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_manual_gateways`
--

CREATE TABLE `op_manual_gateways` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `slug` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qr_code_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `colors` json DEFAULT NULL,
  `input_fields` json DEFAULT NULL,
  `instructions` json DEFAULT NULL,
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `sms_verification` tinyint(1) NOT NULL DEFAULT '0',
  `sms_sender_pattern` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_regex_template` text COLLATE utf8mb4_unicode_ci,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BDT',
  `min_amount` decimal(15,2) DEFAULT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_merchants`
--

CREATE TABLE `op_merchants` (
  `id` bigint UNSIGNED NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Asia/Dhaka',
  `default_currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BDT',
  `webhook_secret` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settings` json DEFAULT NULL,
  `status` enum('active','suspended','pending') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_merchant_users`
--

CREATE TABLE `op_merchant_users` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `role_id` bigint UNSIGNED NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `totp_secret_enc` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `last_login_at` datetime(6) DEFAULT NULL,
  `last_login_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','suspended','pending') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `is_superadmin` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_mobile_notifications`
--

CREATE TABLE `op_mobile_notifications` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `device_id` bigint UNSIGNED DEFAULT NULL,
  `type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `data` json DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_paired_devices`
--

CREATE TABLE `op_paired_devices` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `device_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jwt_fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_heartbeat` datetime(6) DEFAULT NULL,
  `status` enum('active','revoked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `paired_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_payment_intents`
--

CREATE TABLE `op_payment_intents` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` bigint UNSIGNED DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BDT',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `redirect_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancel_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `webhook_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','processing','completed','failed','cancelled','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `expires_at` datetime(6) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_payment_links`
--

CREATE TABLE `op_payment_links` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `amount` decimal(15,2) DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BDT',
  `is_amount_fixed` tinyint(1) NOT NULL DEFAULT '1',
  `min_amount` decimal(15,2) DEFAULT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `redirect_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `max_uses` int UNSIGNED DEFAULT NULL,
  `use_count` int UNSIGNED NOT NULL DEFAULT '0',
  `expires_at` datetime(6) DEFAULT NULL,
  `status` enum('active','inactive','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_payment_link_fields`
--

CREATE TABLE `op_payment_link_fields` (
  `id` bigint UNSIGNED NOT NULL,
  `payment_link_id` bigint UNSIGNED NOT NULL,
  `label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('text','number','email','select','textarea') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `options` json DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_permissions`
--

CREATE TABLE `op_permissions` (
  `id` bigint UNSIGNED NOT NULL,
  `slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_plugins`
--

CREATE TABLE `op_plugins` (
  `id` bigint UNSIGNED NOT NULL,
  `slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('gateway','theme','addon') COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entrypoint` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capabilities` json DEFAULT NULL,
  `manifest` json DEFAULT NULL,
  `status` enum('active','inactive','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inactive',
  `installed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_plugin_migrations`
--

CREATE TABLE `op_plugin_migrations` (
  `id` bigint UNSIGNED NOT NULL,
  `plugin_slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `migration` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int UNSIGNED NOT NULL DEFAULT '1',
  `executed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_plugin_settings`
--

CREATE TABLE `op_plugin_settings` (
  `id` bigint UNSIGNED NOT NULL,
  `plugin_slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_queue_jobs`
--

CREATE TABLE `op_queue_jobs` (
  `id` bigint UNSIGNED NOT NULL,
  `queue` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `handler` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `available_at` int UNSIGNED NOT NULL,
  `reserved_at` int UNSIGNED DEFAULT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_rate_limits`
--

CREATE TABLE `op_rate_limits` (
  `id` bigint UNSIGNED NOT NULL,
  `key_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hits` int UNSIGNED NOT NULL DEFAULT '1',
  `window_start` int UNSIGNED NOT NULL,
  `expires_at` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_refunds`
--

CREATE TABLE `op_refunds` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `transaction_id` bigint UNSIGNED NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `processed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_roles`
--

CREATE TABLE `op_roles` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_role_permissions`
--

CREATE TABLE `op_role_permissions` (
  `role_id` bigint UNSIGNED NOT NULL,
  `permission_id` bigint UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_sessions`
--

CREATE TABLE `op_sessions` (
  `id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_settlements`
--

CREATE TABLE `op_settlements` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BDT',
  `method` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `settled_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_settlement_items`
--

CREATE TABLE `op_settlement_items` (
  `id` bigint UNSIGNED NOT NULL,
  `settlement_id` bigint UNSIGNED NOT NULL,
  `transaction_id` bigint UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_sms_parsed`
--

CREATE TABLE `op_sms_parsed` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `device_id` bigint UNSIGNED DEFAULT NULL,
  `sender` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `trx_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_slug` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parser_type` enum('regex','heuristic','manual') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `match_status` enum('matched','unmatched','pending','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `transaction_id` bigint UNSIGNED DEFAULT NULL,
  `raw_data` json DEFAULT NULL,
  `received_at` datetime(6) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_sms_templates`
--

CREATE TABLE `op_sms_templates` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED DEFAULT NULL,
  `gateway_slug` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender_pattern` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_regex` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trx_id_regex` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sender_regex` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` int NOT NULL DEFAULT '10',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_system_settings`
--

CREATE TABLE `op_system_settings` (
  `id` bigint UNSIGNED NOT NULL,
  `group_name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `key_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `type` enum('string','int','bool','json') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_transactions`
--

CREATE TABLE `op_transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trx_id` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_intent_id` bigint UNSIGNED DEFAULT NULL,
  `customer_id` bigint UNSIGNED DEFAULT NULL,
  `gateway_slug` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `fee` decimal(15,2) NOT NULL DEFAULT '0.00',
  `net_amount` decimal(15,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BDT',
  `sender_account` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_trx_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `method` enum('api','manual','sms','link','invoice') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `status` enum('pending','processing','completed','failed','cancelled','refunded','disputed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `metadata` json DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_update_history`
--

CREATE TABLE `op_update_history` (
  `id` bigint UNSIGNED NOT NULL,
  `from_version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('started','backup_created','downloaded','applied','verified','completed','rolled_back','failed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `backup_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checksum` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `started_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `completed_at` datetime(6) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_webhooks`
--

CREATE TABLE `op_webhooks` (
  `id` bigint UNSIGNED NOT NULL,
  `merchant_id` bigint UNSIGNED NOT NULL,
  `url` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `events` json NOT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_webhook_delivery_logs`
--

CREATE TABLE `op_webhook_delivery_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `webhook_event_id` bigint UNSIGNED NOT NULL,
  `response_code` smallint UNSIGNED DEFAULT NULL,
  `response_body` text COLLATE utf8mb4_unicode_ci,
  `duration_ms` int UNSIGNED DEFAULT NULL,
  `error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `op_webhook_events`
--

CREATE TABLE `op_webhook_events` (
  `id` bigint UNSIGNED NOT NULL,
  `webhook_id` bigint UNSIGNED NOT NULL,
  `event_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `status` enum('pending','delivered','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `last_attempt_at` datetime(6) DEFAULT NULL,
  `next_retry_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `op_api_keys`
--
ALTER TABLE `op_api_keys`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_merchant` (`merchant_id`),
  ADD KEY `idx_prefix` (`key_prefix`);

--
-- Indexes for table `op_audit_logs`
--
ALTER TABLE `op_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_merchant_action` (`merchant_id`,`action`),
  ADD KEY `idx_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `op_cache`
--
ALTER TABLE `op_cache`
  ADD PRIMARY KEY (`key_name`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `op_comm_log`
--
ALTER TABLE `op_comm_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_merchant_channel` (`merchant_id`,`channel`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `op_currencies`
--
ALTER TABLE `op_currencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_code` (`code`);

--
-- Indexes for table `op_customers`
--
ALTER TABLE `op_customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_uuid` (`uuid`),
  ADD UNIQUE KEY `uk_merchant_email` (`merchant_id`,`email_hash`),
  ADD KEY `idx_merchant` (`merchant_id`);

--
-- Indexes for table `op_device_pairing_tokens`
--
ALTER TABLE `op_device_pairing_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_token` (`token`),
  ADD KEY `idx_merchant` (`merchant_id`);

--
-- Indexes for table `op_disputes`
--
ALTER TABLE `op_disputes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_merchant` (`merchant_id`),
  ADD KEY `idx_txn` (`transaction_id`);

--
-- Indexes for table `op_domains`
--
ALTER TABLE `op_domains`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_domain` (`domain`),
  ADD KEY `idx_merchant` (`merchant_id`);

--
-- Indexes for table `op_exchange_rates`
--
ALTER TABLE `op_exchange_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_pair` (`base_currency`,`target_currency`);

--
-- Indexes for table `op_fee_rules`
--
ALTER TABLE `op_fee_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_merchant_gw` (`merchant_id`,`gateway_slug`);

--
-- Indexes for table `op_gateways`
--
ALTER TABLE `op_gateways`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_slug` (`slug`);

--
-- Indexes for table `op_gateway_configs`
--
ALTER TABLE `op_gateway_configs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_merchant_gw` (`merchant_id`,`gateway_id`),
  ADD KEY `fk_gc_gateway` (`gateway_id`);

--
-- Indexes for table `op_idempotency_keys`
--
ALTER TABLE `op_idempotency_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_merchant_key` (`merchant_id`,`idempotency_key`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `op_invoices`
--
ALTER TABLE `op_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_uuid` (`uuid`),
  ADD UNIQUE KEY `uk_token` (`token`),
  ADD UNIQUE KEY `uk_merchant_number` (`merchant_id`,`invoice_number`);

--
-- Indexes for table `op_invoice_items`
--
ALTER TABLE `op_invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice` (`invoice_id`);

--
-- Indexes for table `op_ledger_accounts`
--
ALTER TABLE `op_ledger_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_merchant_name` (`merchant_id`,`name`);

--
-- Indexes for table `op_ledger_entries`
--
ALTER TABLE `op_ledger_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_txn` (`ledger_transaction_id`),
  ADD KEY `idx_account` (`account_id`);

--
-- Indexes for table `op_ledger_transactions`
--
ALTER TABLE `op_ledger_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_uuid` (`uuid`),
  ADD KEY `idx_merchant` (`merchant_id`),
  ADD KEY `idx_ref` (`reference_type`,`reference_id`);

--
-- Indexes for table `op_login_attempts`
--
ALTER TABLE `op_login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email_ip` (`email`,`ip_address`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `op_maintenance_locks`
--
ALTER TABLE `op_maintenance_locks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_locked` (`locked_at`);

--
-- Indexes for table `op_manual_gateways`
--
ALTER TABLE `op_manual_gateways`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_merchant_slug` (`merchant_id`,`slug`);

--
-- Indexes for table `op_merchants`
--
ALTER TABLE `op_merchants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_uuid` (`uuid`),
  ADD UNIQUE KEY `uk_slug` (`slug`),
  ADD UNIQUE KEY `uk_email` (`email`);

--
-- Indexes for table `op_merchant_users`
--
ALTER TABLE `op_merchant_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_email` (`email`),
  ADD KEY `idx_merchant` (`merchant_id`),
  ADD KEY `fk_mu_role` (`role_id`);

--
-- Indexes for table `op_mobile_notifications`
--
ALTER TABLE `op_mobile_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_merchant_read` (`merchant_id`,`is_read`),
  ADD KEY `idx_device` (`device_id`);

--
-- Indexes for table `op_paired_devices`
--
ALTER TABLE `op_paired_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_device` (`device_id`),
  ADD KEY `idx_merchant` (`merchant_id`);

--
-- Indexes for table `op_payment_intents`
--
ALTER TABLE `op_payment_intents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_uuid` (`uuid`),
  ADD UNIQUE KEY `uk_token` (`token`),
  ADD KEY `idx_merchant_status` (`merchant_id`,`status`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `op_payment_links`
--
ALTER TABLE `op_payment_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_uuid` (`uuid`),
  ADD UNIQUE KEY `uk_slug` (`slug`),
  ADD KEY `idx_merchant` (`merchant_id`);

--
-- Indexes for table `op_payment_link_fields`
--
ALTER TABLE `op_payment_link_fields`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_link` (`payment_link_id`);

--
-- Indexes for table `op_permissions`
--
ALTER TABLE `op_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_slug` (`slug`);

--
-- Indexes for table `op_plugins`
--
ALTER TABLE `op_plugins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_slug` (`slug`);

--
-- Indexes for table `op_plugin_migrations`
--
ALTER TABLE `op_plugin_migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_plugin_migration` (`plugin_slug`,`migration`);

--
-- Indexes for table `op_plugin_settings`
--
ALTER TABLE `op_plugin_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_plugin_key` (`plugin_slug`,`key_name`);

--
-- Indexes for table `op_queue_jobs`
--
ALTER TABLE `op_queue_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_queue_available` (`queue`,`available_at`);

--
-- Indexes for table `op_rate_limits`
--
ALTER TABLE `op_rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_key` (`key_name`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `op_refunds`
--
ALTER TABLE `op_refunds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_uuid` (`uuid`),
  ADD KEY `idx_txn` (`transaction_id`),
  ADD KEY `fk_ref_merchant` (`merchant_id`);

--
-- Indexes for table `op_roles`
--
ALTER TABLE `op_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_merchant_slug` (`merchant_id`,`slug`);

--
-- Indexes for table `op_role_permissions`
--
ALTER TABLE `op_role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `fk_rp_perm` (`permission_id`);

--
-- Indexes for table `op_sessions`
--
ALTER TABLE `op_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_activity` (`last_activity`);

--
-- Indexes for table `op_settlements`
--
ALTER TABLE `op_settlements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_uuid` (`uuid`),
  ADD KEY `idx_merchant` (`merchant_id`);

--
-- Indexes for table `op_settlement_items`
--
ALTER TABLE `op_settlement_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_settlement` (`settlement_id`),
  ADD KEY `fk_si_txn` (`transaction_id`);

--
-- Indexes for table `op_sms_parsed`
--
ALTER TABLE `op_sms_parsed`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_merchant_status` (`merchant_id`,`match_status`),
  ADD KEY `idx_trx` (`transaction_id`),
  ADD KEY `idx_received` (`received_at`);

--
-- Indexes for table `op_sms_templates`
--
ALTER TABLE `op_sms_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gateway` (`gateway_slug`),
  ADD KEY `idx_merchant` (`merchant_id`);

--
-- Indexes for table `op_system_settings`
--
ALTER TABLE `op_system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_group_key` (`group_name`,`key_name`);

--
-- Indexes for table `op_transactions`
--
ALTER TABLE `op_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_uuid` (`uuid`),
  ADD UNIQUE KEY `uk_trx_id` (`trx_id`),
  ADD KEY `idx_merchant_status` (`merchant_id`,`status`),
  ADD KEY `idx_merchant_created` (`merchant_id`,`created_at`),
  ADD KEY `idx_gateway` (`gateway_slug`),
  ADD KEY `idx_pi` (`payment_intent_id`);

--
-- Indexes for table `op_update_history`
--
ALTER TABLE `op_update_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `op_webhooks`
--
ALTER TABLE `op_webhooks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_merchant` (`merchant_id`);

--
-- Indexes for table `op_webhook_delivery_logs`
--
ALTER TABLE `op_webhook_delivery_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event` (`webhook_event_id`);

--
-- Indexes for table `op_webhook_events`
--
ALTER TABLE `op_webhook_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_webhook_status` (`webhook_id`,`status`),
  ADD KEY `idx_retry` (`next_retry_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `op_api_keys`
--
ALTER TABLE `op_api_keys`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_audit_logs`
--
ALTER TABLE `op_audit_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_comm_log`
--
ALTER TABLE `op_comm_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_currencies`
--
ALTER TABLE `op_currencies`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_customers`
--
ALTER TABLE `op_customers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_device_pairing_tokens`
--
ALTER TABLE `op_device_pairing_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_disputes`
--
ALTER TABLE `op_disputes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_domains`
--
ALTER TABLE `op_domains`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_exchange_rates`
--
ALTER TABLE `op_exchange_rates`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_fee_rules`
--
ALTER TABLE `op_fee_rules`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_gateways`
--
ALTER TABLE `op_gateways`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_gateway_configs`
--
ALTER TABLE `op_gateway_configs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_idempotency_keys`
--
ALTER TABLE `op_idempotency_keys`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_invoices`
--
ALTER TABLE `op_invoices`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_invoice_items`
--
ALTER TABLE `op_invoice_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_ledger_accounts`
--
ALTER TABLE `op_ledger_accounts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_ledger_entries`
--
ALTER TABLE `op_ledger_entries`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_ledger_transactions`
--
ALTER TABLE `op_ledger_transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_login_attempts`
--
ALTER TABLE `op_login_attempts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_maintenance_locks`
--
ALTER TABLE `op_maintenance_locks`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_manual_gateways`
--
ALTER TABLE `op_manual_gateways`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_merchants`
--
ALTER TABLE `op_merchants`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_merchant_users`
--
ALTER TABLE `op_merchant_users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_mobile_notifications`
--
ALTER TABLE `op_mobile_notifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_paired_devices`
--
ALTER TABLE `op_paired_devices`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_payment_intents`
--
ALTER TABLE `op_payment_intents`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_payment_links`
--
ALTER TABLE `op_payment_links`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_payment_link_fields`
--
ALTER TABLE `op_payment_link_fields`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_permissions`
--
ALTER TABLE `op_permissions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_plugins`
--
ALTER TABLE `op_plugins`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_plugin_migrations`
--
ALTER TABLE `op_plugin_migrations`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_plugin_settings`
--
ALTER TABLE `op_plugin_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_queue_jobs`
--
ALTER TABLE `op_queue_jobs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_rate_limits`
--
ALTER TABLE `op_rate_limits`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_refunds`
--
ALTER TABLE `op_refunds`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_roles`
--
ALTER TABLE `op_roles`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_settlements`
--
ALTER TABLE `op_settlements`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_settlement_items`
--
ALTER TABLE `op_settlement_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_sms_parsed`
--
ALTER TABLE `op_sms_parsed`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_sms_templates`
--
ALTER TABLE `op_sms_templates`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_system_settings`
--
ALTER TABLE `op_system_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_transactions`
--
ALTER TABLE `op_transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_update_history`
--
ALTER TABLE `op_update_history`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_webhooks`
--
ALTER TABLE `op_webhooks`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_webhook_delivery_logs`
--
ALTER TABLE `op_webhook_delivery_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_webhook_events`
--
ALTER TABLE `op_webhook_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `op_api_keys`
--
ALTER TABLE `op_api_keys`
  ADD CONSTRAINT `fk_ak_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_customers`
--
ALTER TABLE `op_customers`
  ADD CONSTRAINT `fk_cust_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_device_pairing_tokens`
--
ALTER TABLE `op_device_pairing_tokens`
  ADD CONSTRAINT `fk_dpt_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_disputes`
--
ALTER TABLE `op_disputes`
  ADD CONSTRAINT `fk_dsp_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dsp_txn` FOREIGN KEY (`transaction_id`) REFERENCES `op_transactions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_domains`
--
ALTER TABLE `op_domains`
  ADD CONSTRAINT `fk_dom_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_gateway_configs`
--
ALTER TABLE `op_gateway_configs`
  ADD CONSTRAINT `fk_gc_gateway` FOREIGN KEY (`gateway_id`) REFERENCES `op_gateways` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gc_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_idempotency_keys`
--
ALTER TABLE `op_idempotency_keys`
  ADD CONSTRAINT `fk_ik_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_invoices`
--
ALTER TABLE `op_invoices`
  ADD CONSTRAINT `fk_inv_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_invoice_items`
--
ALTER TABLE `op_invoice_items`
  ADD CONSTRAINT `fk_ii_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `op_invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_ledger_accounts`
--
ALTER TABLE `op_ledger_accounts`
  ADD CONSTRAINT `fk_la_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_ledger_entries`
--
ALTER TABLE `op_ledger_entries`
  ADD CONSTRAINT `fk_le_account` FOREIGN KEY (`account_id`) REFERENCES `op_ledger_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_le_txn` FOREIGN KEY (`ledger_transaction_id`) REFERENCES `op_ledger_transactions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_manual_gateways`
--
ALTER TABLE `op_manual_gateways`
  ADD CONSTRAINT `fk_mg_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_merchant_users`
--
ALTER TABLE `op_merchant_users`
  ADD CONSTRAINT `fk_mu_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_mu_role` FOREIGN KEY (`role_id`) REFERENCES `op_roles` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `op_paired_devices`
--
ALTER TABLE `op_paired_devices`
  ADD CONSTRAINT `fk_pd_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_payment_intents`
--
ALTER TABLE `op_payment_intents`
  ADD CONSTRAINT `fk_pi_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_payment_links`
--
ALTER TABLE `op_payment_links`
  ADD CONSTRAINT `fk_pl_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_payment_link_fields`
--
ALTER TABLE `op_payment_link_fields`
  ADD CONSTRAINT `fk_plf_link` FOREIGN KEY (`payment_link_id`) REFERENCES `op_payment_links` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_refunds`
--
ALTER TABLE `op_refunds`
  ADD CONSTRAINT `fk_ref_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ref_txn` FOREIGN KEY (`transaction_id`) REFERENCES `op_transactions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_roles`
--
ALTER TABLE `op_roles`
  ADD CONSTRAINT `fk_roles_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_role_permissions`
--
ALTER TABLE `op_role_permissions`
  ADD CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `op_permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `op_roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_settlements`
--
ALTER TABLE `op_settlements`
  ADD CONSTRAINT `fk_stl_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_settlement_items`
--
ALTER TABLE `op_settlement_items`
  ADD CONSTRAINT `fk_si_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `op_settlements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_si_txn` FOREIGN KEY (`transaction_id`) REFERENCES `op_transactions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_transactions`
--
ALTER TABLE `op_transactions`
  ADD CONSTRAINT `fk_txn_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_webhooks`
--
ALTER TABLE `op_webhooks`
  ADD CONSTRAINT `fk_wh_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_webhook_delivery_logs`
--
ALTER TABLE `op_webhook_delivery_logs`
  ADD CONSTRAINT `fk_wdl_event` FOREIGN KEY (`webhook_event_id`) REFERENCES `op_webhook_events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `op_webhook_events`
--
ALTER TABLE `op_webhook_events`
  ADD CONSTRAINT `fk_we_webhook` FOREIGN KEY (`webhook_id`) REFERENCES `op_webhooks` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
