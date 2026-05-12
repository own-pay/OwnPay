-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 09, 2026 at 07:23 AM
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

--
-- Dumping data for table `op_api_keys`
--

INSERT INTO `op_api_keys` (`id`, `merchant_id`, `name`, `key_prefix`, `key_hash`, `scopes`, `last_used_at`, `expires_at`, `status`, `created_at`) VALUES
(1, 1, 'Default', '7b2f5ad0', '9933b17920059f0e9f39cf2c1d7def7f9544bad630169e333319af01be9677e4', NULL, NULL, NULL, 'revoked', '2026-05-01 09:00:30.475604'),
(2, 1, 'Default', 'ace48346', '6b040d10ae0a51d448a36559efc96eb99ec5e8f3a8db642a4c8a7d6c8d7ad444', NULL, NULL, NULL, 'active', '2026-05-01 09:01:22.334788'),
(3, 1, 'Default', 'ec4da784', '0c86af5e4865f658bdf2db3d6325d72b4ff244a7180f53558a2e8a4d4acbf246', NULL, NULL, NULL, 'active', '2026-05-01 09:02:15.349598'),
(4, 1, 'Default', '21e67f20', 'ae5070865a21697c262c2dfb5f1982ddea3271f03f3232b027d447cfa4dd9961', NULL, NULL, NULL, 'active', '2026-05-01 09:02:39.483004'),
(5, 1, 'Default', '6c1125e5', '262f1037675c1470e2220beaa375464f99a4073dea2ba323df8d7740097e952b', NULL, NULL, NULL, 'active', '2026-05-01 10:07:31.972175'),
(6, 1, 'Default', '3f27f5c7', 'e0717bb57e7c7dc1d5933aa4620605422683e3f7c690a19b82a49e941b665572', NULL, NULL, NULL, 'active', '2026-05-01 10:07:58.488123'),
(7, 1, 'Default', '5edef37f', '2c8188cb189a89affab8a2b094edfab574f300c7b48f0f5381bc24fc7b0eac5b', NULL, NULL, NULL, 'active', '2026-05-01 10:08:21.067292'),
(8, 1, 'Default', 'aba25d31', '137337b3459e9057db9ecda2ec101eda12ee3b464b9de2503f785061f9a65e8a', NULL, NULL, NULL, 'revoked', '2026-05-01 10:08:41.841322'),
(9, 1, 'Test Key', '1e7e92f6', 'de9e9fed8176daf2726ecfc5927c5ab3d388af120eea3db77e3a655d409849b8', NULL, NULL, NULL, 'active', '2026-05-07 22:17:10.454419'),
(10, 1, 'Test Key (API Testing)', 'op_test_', '41b3b88ccfe70d6cbb450e11e78da8e481b59ed65b4d031fe419425095dcdfb3', '[]', NULL, NULL, 'active', '2026-05-09 04:07:51.000000');

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

--
-- Dumping data for table `op_audit_logs`
--

INSERT INTO `op_audit_logs` (`id`, `merchant_id`, `user_id`, `action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 02:08:59.966718'),
(2, 1, 1, 'logout', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 02:09:16.383215'),
(3, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 02:09:28.422628'),
(4, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 02:25:18.581464'),
(5, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 02:36:36.036384'),
(6, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 04:38:13.180413'),
(7, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 04:48:14.575315'),
(8, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 06:04:25.810635'),
(9, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 06:44:02.850093'),
(10, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 08:49:30.065929'),
(11, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 08:51:38.701010'),
(12, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 09:07:05.407539'),
(13, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 09:16:48.942799'),
(14, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 09:56:48.354290'),
(15, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 12:09:03.226899'),
(16, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 12:12:06.014793'),
(17, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 12:27:06.059889'),
(18, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 12:39:48.798743'),
(19, 1, 1, 'settings.saved', 'settings', NULL, NULL, '{\"tab\": \"branding\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 12:42:17.066281'),
(20, 1, 1, 'branding.upload', 'settings', NULL, NULL, '{\"files\": [\"site_favicon\"]}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 12:42:30.920395'),
(21, 1, 1, 'settings.saved', 'settings', NULL, NULL, '{\"tab\": \"branding\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 12:42:43.488219'),
(22, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 12:51:45.376060'),
(23, 1, 1, 'login.success', 'user', 1, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 13:16:18.093846');

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

--
-- Dumping data for table `op_currencies`
--

INSERT INTO `op_currencies` (`id`, `code`, `name`, `symbol`, `decimal_places`, `status`) VALUES
(1, 'BDT', 'Bangladeshi Taka', '৳', 2, 'active');

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

--
-- Dumping data for table `op_customers`
--

INSERT INTO `op_customers` (`id`, `merchant_id`, `uuid`, `name_enc`, `email_enc`, `email_hash`, `phone_enc`, `phone_hash`, `metadata`, `created_at`, `updated_at`) VALUES
(1, 1, 'c6c43558-cb0d-44b6-a871-e7ae402ef5da', 0x427770624a4d56794a48444753675352584d466e732b6833616d6b4c2b51704c64752b7462554a646247725162342b715330383d, 0x423071636e6d77684c64706d3775494b4c4e79384d6651367a747478433932444e664f6a4266734864324e445675454f4a556d4265703052704d413d, '165eb679f3a9389d390f3126bad92af62cf0da9d2d617ea9fb0493fa0c833c92', 0x746e4c576c466a322f4f547a48336f3272454a4e574d6d784c43325153362f674a4f7971616975706d776f52776e486a4f70334739586430, '14845a83ce9faf03826515e86ba4c76952948e32b3caf9351ca42a1987ec2153', NULL, '2026-05-05 20:02:07.627572', '2026-05-05 20:02:07.627572'),
(2, 1, 'c56c8967-91f1-4652-b81c-68ed3c829ae1', 0x6230624254344d4156696f4442664d5135464e78456f5479537a382f51396b626e4759637a786d4d6e7a51477330716a4c68633d, 0x51707562364d3633394d326974495a516d6350796d325a33522f55576e59304f6d4a4e696a5150513158556e74446561434e634e4c626f53, '3b3f29c220220f155adf1e47152dba59c1cbb66b6d7bc7fc4943f1007683321c', 0x596d45782f753863784c38732b3477445a453672504551697179753337334d4661374e6c62443049463576686b3134534c564e355957464f, '2030c7dbb16bc2ed87e5b62d93c91cf0b85016edf1617c32fe833f12570e80d9', NULL, '2026-05-05 20:07:46.565416', '2026-05-05 20:07:46.565416'),
(3, 1, '524d5aa7-4114-4f99-9746-b7e963738678', 0x6953755867416b63657138547a6c62454336524952694a6465675a6d547a75395630504c4565414a4c424e6e7279743978672f42, 0x44414631354e5061384648562f537054365a57684479435a5052486746685a754b33543138394b58585a7a55386f56314b5272657858593d, 'a91e8c21b00b1c25be1012516f75cf8aef2d8638d6abeec9d5e236f2ff5442ea', NULL, NULL, NULL, '2026-05-05 20:12:56.836313', '2026-05-05 20:12:56.836313'),
(4, 1, '44510174-fd14-4355-b1d8-120aaa573ad4', 0x787933442f59505376526a382b4e43536a6a4a6859347533776934544f75794e70416e6a703764585766415874372f42634770745455773d, 0x4d44564f664a346c4f746f466a33496e574a65347a2b364170504f674d4a6565466b6d6345452b3348596a6a562f34386d4c396e70594c6635553041, '9071a7268e66bc06846de3f1ce5d7258c4cc0ea7e60fcc40e99d2f02edbb6ac3', NULL, NULL, NULL, '2026-05-07 15:45:59.424521', '2026-05-07 15:45:59.424521');

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

--
-- Dumping data for table `op_device_pairing_tokens`
--

INSERT INTO `op_device_pairing_tokens` (`id`, `merchant_id`, `token`, `expires_at`, `used`, `created_at`) VALUES
(4, 1, '7f7fd40811bd83daac6882ee51642650a4352fc3035dc66b52ac35aab4dae25b', '2026-05-09 13:06:00.540705', 0, '2026-05-09 13:01:00.543685');

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

--
-- Dumping data for table `op_domains`
--

INSERT INTO `op_domains` (`id`, `merchant_id`, `domain`, `type`, `verification_token`, `dns_verified`, `dns_verified_at`, `ssl_status`, `redirect_url`, `is_primary`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'testfix.example.com', 'checkout', 'op-verify-26ec6e0465aa392ab7311aa8304d73cf', 0, NULL, 'none', NULL, 0, 'pending', '2026-05-07 16:18:58.159932', '2026-05-07 16:18:58.159932'),
(2, 1, 'test.example.com', 'checkout', 'op-verify-8c8e4d3b1f9ab563659d834df95e2ccc', 0, NULL, 'none', NULL, 0, 'pending', '2026-05-09 06:05:08.593140', '2026-05-09 06:05:08.593140');

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

--
-- Dumping data for table `op_invoices`
--

INSERT INTO `op_invoices` (`id`, `merchant_id`, `uuid`, `token`, `invoice_number`, `customer_id`, `subtotal`, `tax`, `discount`, `total`, `currency`, `notes`, `due_date`, `status`, `paid_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'f7342cda-9783-4d69-9229-845ec1c8851d', '3a4be3872d7f7626405c81b4831c677f9450393ca6c53828e492f67c16017a0b', 'INV-TEST-001', NULL, 100.00, 0.00, 0.00, 100.00, 'BDT', 'Test invoice', '2026-06-01', 'draft', NULL, '2026-05-05 19:41:24.000000', '2026-05-05 19:41:24.801172'),
(2, 1, 'd146dcf4-86b7-4906-9c06-b801889331bb', '2369607dd84c6fbc54c0812835499693cba8647ab59921467be4497c4dd8b283', 'INV-2026-001', 3, 500.00, 0.00, 0.00, 500.00, 'BDT', NULL, '2026-05-06', 'draft', NULL, '2026-05-06 21:30:58.000000', '2026-05-06 21:30:58.356097'),
(3, 1, '0383994f-04df-4331-8d92-45735f9c18b8', '9650161ee3ef495d77875ff073da918c17b4ccc48b8a91c3756e55f357174581', 'INV-100', 3, 100.00, 0.00, 0.00, 100.00, 'BDT', NULL, '2026-12-31', 'draft', NULL, '2026-05-07 15:45:44.000000', '2026-05-07 15:45:44.000000'),
(5, 1, 'b930247f-9485-40fa-ab04-cd3873b613ec', '445b83484fde04638dad941fb5a3d3122acdd7a6598a8c665fcd0ae6523fb41b', 'INV-TEST-999', 4, 0.00, 0.00, 0.00, 0.00, 'BDT', 'dsdf', '2025-06-01', 'draft', NULL, '2026-05-07 21:11:50.000000', '2026-05-07 21:42:45.000000');

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

--
-- Dumping data for table `op_invoice_items`
--

INSERT INTO `op_invoice_items` (`id`, `invoice_id`, `description`, `quantity`, `unit_price`, `total`, `sort_order`) VALUES
(1, 1, 'Test Product', 2.00, 50.00, 100.00, 0),
(2, 2, 'Service Fee', 1.00, 500.00, 500.00, 0),
(3, 3, 'Test Item', 1.00, 100.00, 100.00, 0),
(4, 5, 'Deep Test Service', 1.00, 100.00, 100.00, 0);

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

--
-- Dumping data for table `op_login_attempts`
--

INSERT INTO `op_login_attempts` (`id`, `email`, `ip_address`, `user_agent`, `success`, `created_at`) VALUES
(3, 'admin@ownpay.test', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 0, '2026-05-01 05:39:07.767376'),
(13, 'foryou.fn@gmail.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 0, '2026-05-01 06:47:57.127451'),
(14, 'admin@admin.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 0, '2026-05-01 06:48:17.321914'),
(15, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-01 06:50:10.559801'),
(16, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-01 06:50:30.293536'),
(17, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-01 06:50:57.345567'),
(18, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-01 06:51:20.515884'),
(19, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-01 06:51:42.794141'),
(20, 'wrong@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 0, '2026-05-01 06:52:13.242405'),
(21, 'admin@example.com', '127.0.0.1', '', 1, '2026-05-01 06:55:03.460072'),
(22, 'admin@example.com', '127.0.0.1', '', 1, '2026-05-01 06:55:20.118063'),
(23, 'admin@example.com', '127.0.0.1', '', 1, '2026-05-01 06:57:15.785153'),
(24, 'admin@example.com', '127.0.0.1', '', 1, '2026-05-01 06:57:44.800667'),
(25, 'admin@example.com', '127.0.0.1', '', 1, '2026-05-01 06:58:01.164114'),
(26, 'admin@example.com', '127.0.0.1', '', 1, '2026-05-01 06:58:23.572029'),
(27, 'admin@example.com', '127.0.0.1', '', 1, '2026-05-01 06:58:39.771437'),
(28, 'admin@example.com', '127.0.0.1', '', 1, '2026-05-01 06:58:55.051345'),
(29, 'admin@example.com', '127.0.0.1', '', 1, '2026-05-01 07:03:53.921345'),
(30, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-01 07:10:34.381384'),
(31, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', 1, '2026-05-01 07:42:54.839847'),
(32, '', '0.0.0.0', '', 0, '2026-05-01 08:12:47.495612'),
(33, '', '0.0.0.0', '', 0, '2026-05-01 08:37:35.272124'),
(34, '', '0.0.0.0', '', 0, '2026-05-01 08:43:30.459436'),
(35, '', '0.0.0.0', '', 0, '2026-05-01 08:46:49.407642'),
(36, '', '0.0.0.0', '', 0, '2026-05-01 08:50:08.891348'),
(37, '', '0.0.0.0', '', 0, '2026-05-01 08:51:56.590372'),
(38, '', '0.0.0.0', '', 0, '2026-05-01 08:52:33.519722'),
(39, '', '0.0.0.0', '', 0, '2026-05-01 08:58:21.512286'),
(40, '', '0.0.0.0', '', 0, '2026-05-01 09:00:30.497278'),
(41, '', '0.0.0.0', '', 0, '2026-05-01 09:01:22.376989'),
(42, '', '0.0.0.0', '', 0, '2026-05-01 09:02:15.367182'),
(43, '', '0.0.0.0', '', 0, '2026-05-01 09:02:39.498611'),
(44, '', '0.0.0.0', '', 0, '2026-05-01 10:07:31.997830'),
(45, '', '0.0.0.0', '', 0, '2026-05-01 10:07:58.506774'),
(46, '', '0.0.0.0', '', 0, '2026-05-01 10:08:21.086656'),
(47, '', '0.0.0.0', '', 0, '2026-05-01 10:08:41.860815'),
(48, 'foryou.fn@gmail.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 0, '2026-05-04 16:56:02.650288'),
(49, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-04 16:56:21.486063'),
(50, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-04 17:33:08.420376'),
(51, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-04 22:39:01.846026'),
(52, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 04:32:22.743331'),
(53, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 04:36:06.667378'),
(54, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 0, '2026-05-05 04:48:03.393994'),
(55, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 04:48:14.486697'),
(56, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 04:53:55.427980'),
(57, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 04:55:52.977949'),
(58, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 05:03:52.824499'),
(59, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 05:10:06.431106'),
(60, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 05:11:18.609122'),
(61, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 05:21:05.664544'),
(62, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 05:40:03.181065'),
(63, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 05:53:22.200398'),
(64, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 05:59:06.025613'),
(65, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 06:07:25.712512'),
(66, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 06:17:49.186304'),
(67, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 06:26:13.504565'),
(68, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 18:45:15.807318'),
(69, 'wrong@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 0, '2026-05-05 18:48:45.949162'),
(70, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 18:48:56.281802'),
(71, 'wrong@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 0, '2026-05-05 18:49:25.628359'),
(72, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 18:50:16.721530'),
(73, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 18:57:17.965239'),
(74, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 19:09:03.161110'),
(75, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 19:40:46.756018'),
(76, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 19:44:18.030880'),
(77, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 19:56:50.158718'),
(78, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 20:18:05.381417'),
(79, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-05 23:39:11.469666'),
(80, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-06 21:29:04.288151'),
(81, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-07 15:30:49.980895'),
(82, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-07 15:43:59.194123'),
(83, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-07 17:26:28.470744'),
(84, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-07 21:10:49.283544'),
(85, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-07 22:06:30.896187'),
(86, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-07 22:11:06.144515'),
(87, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-07 22:16:42.202952'),
(88, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-07 22:21:58.337637'),
(89, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-07 22:27:37.134358'),
(90, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-08 22:37:04.930070'),
(91, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-08 22:41:14.151293'),
(92, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 02:08:59.957289'),
(93, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 02:09:28.414279'),
(94, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 02:25:18.572276'),
(95, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 02:36:35.984438'),
(96, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 04:38:13.170647'),
(97, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 04:48:14.539584'),
(98, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 06:04:25.803584'),
(99, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 06:44:02.839787'),
(100, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 08:49:30.054588'),
(101, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 08:51:38.693628'),
(102, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 09:07:05.382856'),
(103, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 09:16:48.926747'),
(104, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 09:56:48.345250'),
(105, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 12:09:03.139643'),
(106, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 12:12:06.004898'),
(107, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 12:27:06.051860'),
(108, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 12:39:48.789019'),
(109, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 12:51:45.360947'),
(110, 'admin@example.com', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 1, '2026-05-09 13:16:18.084193');

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

--
-- Dumping data for table `op_manual_gateways`
--

INSERT INTO `op_manual_gateways` (`id`, `merchant_id`, `slug`, `name`, `logo_path`, `qr_code_path`, `colors`, `input_fields`, `instructions`, `admin_notes`, `sms_verification`, `sms_sender_pattern`, `sms_regex_template`, `currency`, `min_amount`, `max_amount`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'test-bkashtest-bkash', 'Test bKash', NULL, NULL, '{\"text\": \"#ffffff\", \"primary\": \"#e2136e\", \"secondary\": \"#ffffff\"}', '[]', '{\"steps\": [\"Send to 01700000000. Type: mobile_banking.\"]}', NULL, 0, NULL, NULL, 'BDT', 0.00, 0.00, 0, 'active', '2026-05-07 15:44:56.444467', '2026-05-07 15:44:56.444467'),
(2, 1, 'test-gateway', 'Test Gateway', NULL, NULL, '{\"text\": \"#ffffff\", \"primary\": \"#e2136e\", \"secondary\": \"#ffffff\"}', '[]', '{\"steps\": []}', NULL, 0, NULL, NULL, 'BDT', 0.00, 0.00, 0, 'active', '2026-05-07 16:19:46.604686', '2026-05-07 16:19:46.604686');

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

--
-- Dumping data for table `op_merchants`
--

INSERT INTO `op_merchants` (`id`, `uuid`, `name`, `slug`, `email`, `phone`, `logo_path`, `timezone`, `default_currency`, `webhook_secret`, `settings`, `status`, `created_at`, `updated_at`) VALUES
(1, 'd790fe5b-46f4-4790-b6cb-a6aa7a70ce3b', 'System Default', 'system-default', 'admin@example.com', '123456789', NULL, 'Asia/Dhaka', 'BDT', NULL, NULL, 'active', '2026-05-01 04:38:34.000000', '2026-05-07 15:46:33.808239'),
(2, 'e2a44e71-7606-45fe-a4de-8164aaf78e77', '', '', '', '', NULL, 'Asia/Dhaka', 'BDT', NULL, NULL, 'active', '2026-05-01 08:12:49.000000', '2026-05-01 08:12:49.111271'),
(10, '3239cc1c-34e3-494a-9e9d-304ded0ba02b', 'Builder Hall Ltd.', 'builder-hall-ltd', 'iamnaime@builderhall.com', '01715938284', NULL, 'Asia/Dhaka', 'BDT', NULL, NULL, 'active', '2026-05-01 10:32:38.000000', '2026-05-01 10:32:38.721907'),
(11, 'f2ffc495-69bf-4f1a-b0bb-b4e2ed813bd0', 'Test Brand 2', 'test-brand-2', 'brand2@example.com', '', NULL, 'Asia/Dhaka', 'BDT', '589047e728016da5b69c20c5b46e661ecf96145d306fe4a384135c3ebb523452', NULL, 'active', '2026-05-07 15:44:24.622100', '2026-05-07 15:44:24.622100'),
(12, '2473e259-e5f4-4e4d-8a52-9e1de674c311', 'Test Brand', 'test-brand', 'test@example.com', '', NULL, 'Asia/Dhaka', 'BDT', '21fc5c61eb22c9886600a68a1ca741310e4f831b744ddafd27f751d3b6188871', NULL, 'active', '2026-05-07 15:50:51.676781', '2026-05-07 15:50:51.676781');

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

--
-- Dumping data for table `op_merchant_users`
--

INSERT INTO `op_merchant_users` (`id`, `merchant_id`, `role_id`, `name`, `email`, `password_hash`, `phone`, `avatar_path`, `totp_secret_enc`, `two_factor_enabled`, `last_login_at`, `last_login_ip`, `status`, `is_superadmin`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Admin Test', 'admin@example.com', '$argon2id$v=19$m=65536,t=4,p=1$UUI3eGUxenRvNmJTNjhpSg$9iWFI8XUsbqQrytxehLgQSgYKrxOfkVNwLCg7SOyzwE', NULL, NULL, '6OVXMQU53PH4JCWYM5PL74FFSE6BNFDC', 0, '2026-05-09 13:16:18.087180', '127.0.0.1', 'active', 1, '2026-05-01 04:38:34.000000', '2026-05-09 13:16:18.087180'),
(3, 1, 4, 'Test Staff', 'staff@test.com', '$argon2id$v=19$m=65536,t=4,p=1$UE1kcjB1OVQ5UnhLOTlxWA$1TOstttA54NV16sl49v6A7IktIRmdj/pcCgrh3lF9NU', NULL, NULL, NULL, 0, NULL, NULL, 'active', 0, '2026-05-07 16:19:27.867846', '2026-05-07 16:19:27.867846');

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

--
-- Dumping data for table `op_payment_links`
--

INSERT INTO `op_payment_links` (`id`, `merchant_id`, `uuid`, `slug`, `title`, `description`, `amount`, `currency`, `is_amount_fixed`, `min_amount`, `max_amount`, `redirect_url`, `max_uses`, `use_count`, `expires_at`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, '20be7f08-ddc8-4360-9270-728b538e7bc7', 'est-ayment-ink-004f', 'Test Payment Link', NULL, 250.00, 'BDT', 0, NULL, NULL, NULL, NULL, 2, NULL, 'active', '2026-05-05 19:44:42.000000', '2026-05-05 20:08:56.522961'),
(2, 1, '11e7c359-eecb-4e98-a7ad-a83c26eae25c', 'test-payment-v2-0512', 'Test Payment v2', NULL, 500.00, 'BDT', 0, NULL, NULL, NULL, NULL, 1, NULL, 'active', '2026-05-05 19:58:00.000000', '2026-05-05 20:19:13.886376'),
(3, 1, '28d78b2a-ee07-4da6-a902-081519619350', 'donate-now-a752', 'Donate Now', NULL, 100.00, 'BDT', 0, NULL, NULL, NULL, NULL, 14, NULL, 'active', '2026-05-05 20:03:14.000000', '2026-05-07 21:16:22.769668'),
(4, 1, '81e8f9a3-82b0-40b4-b757-c2c593bd0c9f', 'donate-today-170b', 'Donate Today', NULL, 200.00, 'BDT', 0, NULL, NULL, NULL, NULL, 5, NULL, 'active', '2026-05-05 20:08:20.000000', '2026-05-06 23:23:12.194242'),
(5, 1, '38ddbf0c-4257-4cba-9a3c-47dd9ae0f7b8', 'test-custom-amount-6492', 'Test Custom Amount', NULL, NULL, 'BDT', 0, NULL, NULL, NULL, NULL, 2, NULL, 'active', '2026-05-05 20:23:56.000000', '2026-05-05 20:37:57.415013'),
(6, 1, 'ab0a1ecb-6b8b-45ae-ab55-af970a22752b', 'test-link-0100', 'Test Link', NULL, 200.00, 'BDT', 0, NULL, NULL, NULL, NULL, 1, NULL, 'active', '2026-05-06 21:30:18.000000', '2026-05-06 21:32:09.944931'),
(7, 1, 'b48e05ab-7fec-426a-b128-518db56ddaad', 'test-link-new-9a4c', 'Test Link New', NULL, 500.00, 'BDT', 0, NULL, NULL, NULL, NULL, 0, NULL, 'active', '2026-05-07 15:45:11.000000', '2026-05-07 15:45:11.574948'),
(8, 1, 'c0c78819-9562-4740-8620-254f694d9271', 'test-link-3-bca0', 'Test Link 3', NULL, 100.00, 'BDT', 0, NULL, NULL, NULL, NULL, 0, NULL, 'active', '2026-05-07 15:52:18.000000', '2026-05-07 15:52:18.581158'),
(9, 1, '8769e8f6-2e97-435e-a51f-5b12bb33bf3a', 'deep-test-link-566f', 'Deep Test Link', NULL, 50.00, 'BDT', 0, NULL, NULL, NULL, NULL, 0, NULL, 'active', '2026-05-07 21:12:11.000000', '2026-05-07 21:12:11.881955');

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

--
-- Dumping data for table `op_plugins`
--

INSERT INTO `op_plugins` (`id`, `slug`, `name`, `type`, `version`, `entrypoint`, `capabilities`, `manifest`, `status`, `installed_at`, `updated_at`) VALUES
(1, 'bkash-api', 'bKash API', 'gateway', '1.0.0', 'BkashApiGateway.php', '[\"gateway\"]', '{\"name\": \"bKash API\", \"slug\": \"bkash-api\", \"type\": \"gateway\", \"author\": \"OwnPay Core\", \"version\": \"1.0.0\", \"requires\": {\"php\": \">=8.1\", \"core\": \">=0.1.0\"}, \"entrypoint\": \"BkashApiGateway.php\", \"description\": \"bKash tokenized checkout API integration\", \"permissions\": [\"gateway.process\"], \"capabilities\": [\"gateway\"]}', 'active', '2026-05-05 00:03:53.326005', '2026-05-05 00:03:53.337759'),
(2, 'sslcommerz', 'SSLCommerz', 'gateway', '1.0.0', 'SslCommerzGateway.php', '[\"gateway\"]', '{\"name\": \"SSLCommerz\", \"slug\": \"sslcommerz\", \"type\": \"gateway\", \"author\": \"OwnPay Core\", \"version\": \"1.0.0\", \"requires\": {\"php\": \">=8.1\", \"core\": \">=0.1.0\"}, \"entrypoint\": \"SslCommerzGateway.php\", \"description\": \"SSLCommerz payment gateway for Bangladesh\", \"permissions\": [\"gateway.process\"], \"capabilities\": [\"gateway\"]}', 'active', '2026-05-05 00:04:37.473084', '2026-05-05 00:04:37.476031'),
(3, 'stripe', 'Stripe', 'gateway', '1.0.0', 'StripeGateway.php', '[\"gateway\"]', '{\"name\": \"stripe\", \"slug\": \"stripe\", \"type\": \"gateway\", \"author\": \"OwnPay Core\", \"version\": \"1.0.0\", \"requires\": {\"php\": \">=8.1\", \"core\": \">=0.1.0\"}, \"entrypoint\": \"StripeGateway.php\", \"description\": \"Stripe payment gateway — cards, wallets, international payments\", \"permissions\": [\"gateway.process\", \"gateway.refund\"], \"capabilities\": [\"gateway\"]}', 'inactive', '2026-05-05 00:05:12.867106', '2026-05-09 09:14:06.647283'),
(4, 'mail-gateway', 'Mail Gateway', 'addon', '1.0.0', 'Plugin.php', '[]', '{\"name\": \"mail-gateway\", \"slug\": \"mail-gateway\", \"type\": \"addon\", \"author\": \"Own Pay\", \"version\": \"1.0.0\", \"requires\": {\"ownpay\": \">=0.1.0\"}, \"entrypoint\": \"Plugin.php\", \"description\": \"Mail Gateway addon — send emails via SMTP, Mailgun, or SendGrid.\", \"permissions\": [], \"capabilities\": []}', 'active', '2026-05-05 00:06:48.543071', '2026-05-09 09:14:06.653486'),
(5, 'sms-gateway', 'SMS Gateway', 'addon', '1.0.0', 'Plugin.php', '[]', '{\"name\": \"sms-gateway\", \"slug\": \"sms-gateway\", \"type\": \"addon\", \"author\": \"Own Pay\", \"version\": \"1.0.0\", \"requires\": {\"ownpay\": \">=0.1.0\"}, \"entrypoint\": \"Plugin.php\", \"description\": \"SMS Gateway addon — send SMS via Twilio, Vonage, or custom HTTP API.\", \"permissions\": [], \"capabilities\": []}', 'active', '2026-05-05 06:06:58.218515', '2026-05-09 09:14:06.658820'),
(6, 'own-pay', 'Own Pay Theme', 'theme', '2.0.0', 'Theme.php', '[]', '{\"name\": \"own-pay-theme\", \"slug\": \"own-pay-theme\", \"type\": \"theme\", \"author\": \"Own Pay\", \"version\": \"2.0.0\", \"requires\": {\"ownpay\": \">=0.1.0\"}, \"entrypoint\": \"Theme.php\", \"description\": \"Default Own Pay checkout theme — premium, responsive, multi-gateway.\", \"permissions\": [], \"capabilities\": []}', 'active', '2026-05-05 06:19:57.123721', '2026-05-09 09:15:19.775152'),
(7, 'telegram-bot', 'Telegram Bot', 'addon', '1.0.0', 'Plugin.php', '[]', '{\"name\": \"telegram-bot\", \"slug\": \"telegram-bot\", \"type\": \"addon\", \"author\": \"Own Pay\", \"version\": \"1.0.0\", \"requires\": {\"ownpay\": \">=0.1.0\"}, \"entrypoint\": \"Plugin.php\", \"description\": \"Telegram Bot addon — real-time transaction alerts and commands.\", \"permissions\": [], \"capabilities\": []}', 'active', '2026-05-05 06:40:49.116932', '2026-05-09 09:14:06.661705');

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

--
-- Dumping data for table `op_rate_limits`
--

INSERT INTO `op_rate_limits` (`id`, `key_name`, `hits`, `window_start`, `expires_at`) VALUES
(2625, 'rl:127.0.0.1:admin.settings.payment', 2, 1778310950, 1778311010),
(2626, 'rl:127.0.0.1:admin', 1, 1778310978, 1778311038);

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

--
-- Dumping data for table `op_roles`
--

INSERT INTO `op_roles` (`id`, `merchant_id`, `name`, `slug`, `description`, `is_system`, `created_at`) VALUES
(1, 1, 'Owner', 'owner', 'System owner role', 1, '2026-05-01 04:38:34.000000'),
(2, 12, 'Staff', 'staff', 'Default staff role with limited permissions', 1, '2026-05-07 16:17:10.740096'),
(3, 10, 'Staff', 'staff', 'Default staff role with limited permissions', 1, '2026-05-07 16:17:10.741985'),
(4, 1, 'Staff', 'staff', 'Default staff role with limited permissions', 1, '2026-05-07 16:17:10.743672'),
(5, 2, 'Staff', 'staff', 'Default staff role with limited permissions', 1, '2026-05-07 16:17:10.744656'),
(6, 11, 'Staff', 'staff', 'Default staff role with limited permissions', 1, '2026-05-07 16:17:10.745598');

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

--
-- Dumping data for table `op_system_settings`
--

INSERT INTO `op_system_settings` (`id`, `group_name`, `key_name`, `value`, `type`, `updated_at`) VALUES
(1, 'general', 'app_name', 'Own Pay', 'string', '2026-05-07 17:20:52.218749'),
(2, 'general', 'timezone', 'Asia/Dhaka', 'string', '2026-05-01 04:38:48.001625'),
(3, 'general', 'currency', 'BDT', 'string', '2026-05-01 04:38:48.003743'),
(4, 'general', 'theme', 'own-pay', 'string', '2026-05-01 04:38:48.005842'),
(5, 'general', 'version', '0.1.0', 'string', '2026-05-01 04:38:48.007198'),
(6, 'general', 'faqs', '[{\"question\":\"Faq qued\",\"answer\":\"answ\"}]', 'string', '2026-05-01 10:20:27.734717'),
(7, 'general', 'auto_update', '0', 'string', '2026-05-01 08:43:30.730241'),
(8, 'general', 'base_url', 'https://ownpay.test', 'string', '2026-05-01 10:19:46.728881'),
(9, 'general', 'webhook_url', '', 'string', '2026-05-01 10:19:46.731595'),
(10, 'general', 'api_rate_limit', '60', 'string', '2026-05-01 10:19:46.732981'),
(11, 'general', 'default_currency', 'BDT', 'string', '2026-05-01 10:19:46.734182'),
(12, 'general', 'exchange_rate_mode', 'auto', 'string', '2026-05-01 10:19:46.735240'),
(13, 'general', 'theme_primary', '#5be6e4', 'string', '2026-05-01 10:21:27.398495'),
(14, 'general', 'theme_accent', '#0b1960', 'string', '2026-05-01 10:21:51.397589'),
(15, 'general', 'theme_mode', 'light', 'string', '2026-05-01 10:20:41.893155'),
(16, 'general', 'maintenance_mode', '0', 'string', '2026-05-07 17:20:52.220243'),
(17, 'appearance', 'active_theme', 'own-pay', 'string', '2026-05-09 09:15:19.780444'),
(18, 'general', 'smtp_host', '', 'string', '2026-05-05 06:37:57.482001'),
(19, 'general', 'smtp_port', '587', 'string', '2026-05-05 06:37:57.483514'),
(20, 'general', 'smtp_encryption', 'tls', 'string', '2026-05-05 06:37:57.484049'),
(21, 'general', 'smtp_username', '', 'string', '2026-05-05 06:37:57.484616'),
(22, 'general', 'smtp_password', '', 'string', '2026-05-05 06:37:57.485048'),
(23, 'general', 'mail_from_email', '', 'string', '2026-05-05 06:37:57.485427'),
(24, 'general', 'mail_from_name', '', 'string', '2026-05-05 06:37:57.485792'),
(25, 'general', 'session_timeout', '120', 'string', '2026-05-05 06:37:57.486406'),
(26, 'general', 'max_login_attempts', '5', 'string', '2026-05-05 06:37:57.486861'),
(27, 'general', 'ip_allowlist', '', 'string', '2026-05-05 06:37:57.487264'),
(28, 'general', 'force_https', '1', 'string', '2026-05-05 06:37:57.487638'),
(29, 'general', 'support_email', '', 'string', '2026-05-07 17:11:37.888384'),
(30, 'general', 'footer_text', '', 'string', '2026-05-07 17:11:37.889059'),
(31, 'general', 'payment_expiry_minutes', '30', 'string', '2026-05-07 17:11:37.891707'),
(32, 'general', 'invoice_due_days', '7', 'string', '2026-05-07 17:11:37.892657'),
(33, 'general', 'checkout_title', 'Complete Payment', 'string', '2026-05-07 17:11:37.900218'),
(34, 'general', 'checkout_success_msg', 'Payment completed successfully! Thank you for your payment.', 'string', '2026-05-07 17:11:37.900869'),
(35, 'general', 'checkout_pending_msg', 'Your payment is being verified. You will receive a confirmation shortly.', 'string', '2026-05-07 17:11:37.901558'),
(36, 'general', 'checkout_failed_msg', 'This payment session has expired or was cancelled.', 'string', '2026-05-07 17:11:37.902206'),
(37, 'general', 'terms_url', '', 'string', '2026-05-07 17:11:37.902885'),
(38, 'general', 'privacy_url', '', 'string', '2026-05-07 17:11:37.903418'),
(39, 'general', 'admin_notification_email', '', 'string', '2026-05-07 17:11:37.903850'),
(40, 'general', 'require_2fa', '0', 'string', '2026-05-07 17:11:37.905955'),
(41, 'general', 'sms_verification', '0', 'string', '2026-05-07 17:11:37.906403'),
(42, 'general', 'auto_approve_payments', '0', 'string', '2026-05-07 17:11:37.906786'),
(43, 'general', 'email_on_payment', '0', 'string', '2026-05-07 17:11:37.907168'),
(44, 'general', 'email_on_refund', '0', 'string', '2026-05-07 17:11:37.907627'),
(45, 'branding', 'app_name', 'Own Pay', 'string', '2026-05-09 12:42:17.061275'),
(46, 'branding', 'base_url', 'https://ownpay.test', 'string', '2026-05-09 12:42:17.061883'),
(47, 'branding', 'timezone', 'Asia/Dhaka', 'string', '2026-05-09 12:42:17.062409'),
(48, 'branding', 'support_email', '', 'string', '2026-05-09 12:42:17.062907'),
(49, 'branding', 'footer_text', '', 'string', '2026-05-09 12:42:17.063300'),
(50, 'branding', 'admin_panel_title', '', 'string', '2026-05-09 12:42:17.063665'),
(51, 'branding', 'site_seo_title', '', 'string', '2026-05-09 12:42:17.064027'),
(52, 'branding', 'site_meta_description', '', 'string', '2026-05-09 12:42:17.064393'),
(53, 'branding', 'site_keywords', '', 'string', '2026-05-09 12:42:17.064769'),
(54, 'branding', 'brand_tagline', '', 'string', '2026-05-09 12:42:17.065123'),
(55, 'branding', 'site_favicon', '/assets/uploads/site_favicon_1778308950.webp', 'string', '2026-05-09 12:42:30.918728');

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

--
-- Dumping data for table `op_transactions`
--

INSERT INTO `op_transactions` (`id`, `merchant_id`, `uuid`, `trx_id`, `payment_intent_id`, `customer_id`, `gateway_slug`, `amount`, `fee`, `net_amount`, `currency`, `sender_account`, `reference`, `gateway_trx_id`, `method`, `status`, `metadata`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'c7a155ca-7b7e-488f-9758-54c3c852f104', 'TXN-166F65E7B4D2C642', NULL, NULL, 'link', 250.00, 0.00, 250.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 1}', NULL, '2026-05-05 20:07:36.513069', '2026-05-05 20:07:36.513069'),
(2, 1, '1c4786f7-60ac-46fa-a6c3-8fbc5cb25c36', 'TXN-6BBDFE651B5D3850', NULL, NULL, 'link', 200.00, 0.00, 200.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 4}', NULL, '2026-05-05 20:08:25.822747', '2026-05-05 20:08:25.822747'),
(3, 1, '9e38f26d-be77-41da-acb5-8c0f9cb8214a', 'TXN-A354422D4105C5AD', NULL, NULL, 'link', 250.00, 0.00, 250.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 1}', NULL, '2026-05-05 20:08:56.518385', '2026-05-05 20:08:56.518385'),
(4, 1, '80ddd2c5-5d03-4c93-95b6-5ac8227e1839', 'TXN-666FCF7639A47756', NULL, NULL, 'link', 200.00, 0.00, 200.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 4}', NULL, '2026-05-05 20:09:19.398025', '2026-05-05 20:09:19.398025'),
(5, 1, '70989dd2-737f-4f71-bb34-2388119f1d32', 'TXN-4D110C1938E67994', NULL, NULL, 'link', 200.00, 0.00, 200.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 4}', NULL, '2026-05-05 20:13:01.521563', '2026-05-05 20:13:01.521563'),
(6, 1, '38610a71-fd28-4fbb-8034-20a9c66d4c6f', 'TXN-37521C2AED8136EB', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 20:13:13.731985', '2026-05-05 20:13:13.731985'),
(7, 1, '8721b5e3-ff62-4763-92f2-4aca96782d4e', 'TXN-77B8AB89E339577F', NULL, NULL, 'link', 200.00, 0.00, 200.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 4}', NULL, '2026-05-05 20:15:28.822261', '2026-05-05 20:15:28.822261'),
(8, 1, '2e4076db-20ba-451b-a742-1cd79fec77b7', 'TXN-D1C16AFED17F184C', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 20:15:39.558763', '2026-05-05 20:15:39.558763'),
(9, 1, '86b61b7b-b1e5-4870-9844-fad5d78d2a56', 'TXN-62D86E56C6FACC6B', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 20:18:24.842631', '2026-05-05 20:18:24.842631'),
(10, 1, '61f76ffa-3f2b-45a1-adc0-32bd5fdf4ecd', 'TXN-296A981999091B54', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 20:19:01.185650', '2026-05-05 20:19:01.185650'),
(11, 1, '5082204c-5d28-4e41-992e-4ba861e1dc7f', 'TXN-E83BF8D1A58C90C1', NULL, NULL, 'link', 500.00, 0.00, 500.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 2}', NULL, '2026-05-05 20:19:13.882874', '2026-05-05 20:19:13.882874'),
(12, 1, '5506e88e-b3e6-4bb0-8763-1cf6a3120a9f', 'TXN-D2784E7CD4CB0472', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 20:21:45.693098', '2026-05-05 20:21:45.693098'),
(13, 1, '8583e19c-b447-4d4c-b5bf-4bc1237b6066', 'TXN-E7E4AA39E383A37F', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 20:27:34.676503', '2026-05-05 20:27:34.676503'),
(14, 1, 'ba104cfa-92ba-4b97-84ac-51bbd420f2cb', 'TXN-EEB9DE3FFF137A6B', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 20:30:51.116156', '2026-05-05 20:30:51.116156'),
(15, 1, '746c6bfd-ff26-46d1-b885-35751b4af78a', 'TXN-0DDF252E51924BA1', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 20:31:13.356189', '2026-05-05 20:31:13.356189'),
(16, 1, 'e8718612-ffad-4580-898e-3b45a644ae73', 'TXN-E50A5998CAF9543C', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 5}', NULL, '2026-05-05 20:33:33.098934', '2026-05-05 20:33:33.098934'),
(17, 1, '355a1baa-131b-4f83-8a3a-6d21c4582399', 'TXN-B39AC1680485CBBA', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 20:36:22.086794', '2026-05-05 20:36:22.086794'),
(18, 1, '65865ccf-b0ea-4790-a8be-d67d7248cfe1', 'TXN-7ED1466EB21F5F51', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 20:36:52.881902', '2026-05-05 20:36:52.881902'),
(19, 1, '35081840-f7bd-4231-bac2-6a63ccf33149', 'TXN-765CAFFDAC4779F0', NULL, NULL, 'link', 250.00, 0.00, 250.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 5}', NULL, '2026-05-05 20:37:57.410591', '2026-05-05 20:37:57.410591'),
(20, 1, 'a4e77a1f-84e4-4c47-8913-777c973a6a3c', 'TXN-00479DBB6F6A9EAC', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-05 23:39:22.457047', '2026-05-05 23:39:22.457047'),
(24, 1, '7f17ce58-272d-4a09-b00b-db275f624ae1', 'TXN-68FC16B8AD931DDE', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-06 21:28:22.485006', '2026-05-06 21:28:22.485006'),
(25, 1, '77995dc5-e2a5-4ca3-9e30-db60a0af163a', 'TXN-25562802765234D6', NULL, NULL, 'link', 200.00, 0.00, 200.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 6}', NULL, '2026-05-06 21:32:09.942667', '2026-05-06 21:32:09.942667'),
(27, 1, '47066bd8-e37d-4e92-9b4f-b31739e870c9', 'TXN-4227B9F9502F4EF2', NULL, NULL, 'link', 200.00, 0.00, 200.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 4}', NULL, '2026-05-06 23:23:12.164159', '2026-05-06 23:23:12.164159'),
(29, 1, '8b82e3da-b2d5-43a3-874d-8434577c5022', 'TXN-8A2B476C95544482', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-07 21:14:35.883724', '2026-05-07 21:14:35.883724'),
(30, 1, 'f00f7d04-a7cf-4d0a-8c39-ae50eba8d1c6', 'TXN-9D0097518F5B795E', NULL, NULL, 'link', 100.00, 0.00, 100.00, 'BDT', NULL, NULL, NULL, 'link', 'pending', '{\"payment_link_id\": 3}', NULL, '2026-05-07 21:16:22.767068', '2026-05-07 21:16:22.767068');

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

--
-- Dumping data for table `op_update_history`
--

INSERT INTO `op_update_history` (`id`, `from_version`, `to_version`, `status`, `backup_path`, `checksum`, `error`, `started_at`, `completed_at`) VALUES
(1, '0.0.0', '', 'started', NULL, NULL, NULL, '2026-05-01 08:43:30.725663', NULL),
(2, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 08:46:49.626539', '2026-05-01 08:46:49.628500'),
(3, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 08:50:09.269919', '2026-05-01 08:50:09.271645'),
(4, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 08:51:56.713306', '2026-05-01 08:51:56.714892'),
(5, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 08:52:34.392372', '2026-05-01 08:52:34.395326'),
(6, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 08:58:22.323486', '2026-05-01 08:58:22.324931'),
(7, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 09:00:31.369884', '2026-05-01 09:00:31.371717'),
(8, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 09:01:23.284663', '2026-05-01 09:01:23.286562'),
(9, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 09:02:16.216953', '2026-05-01 09:02:16.220211'),
(10, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 09:02:40.305368', '2026-05-01 09:02:40.307495'),
(11, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 10:07:33.175453', '2026-05-01 10:07:33.177614'),
(12, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 10:07:59.444424', '2026-05-01 10:07:59.446849'),
(13, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 10:08:22.048580', '2026-05-01 10:08:22.050932'),
(14, '', '', 'failed', NULL, NULL, 'SQLSTATE[01000]: Warning: 1265 Data truncated for column \'status\' at row 1', '2026-05-01 10:08:42.836546', '2026-05-01 10:08:42.840106');

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
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `op_audit_logs`
--
ALTER TABLE `op_audit_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `op_comm_log`
--
ALTER TABLE `op_comm_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_currencies`
--
ALTER TABLE `op_currencies`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `op_customers`
--
ALTER TABLE `op_customers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `op_device_pairing_tokens`
--
ALTER TABLE `op_device_pairing_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `op_disputes`
--
ALTER TABLE `op_disputes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_domains`
--
ALTER TABLE `op_domains`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `op_invoice_items`
--
ALTER TABLE `op_invoice_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

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
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `op_maintenance_locks`
--
ALTER TABLE `op_maintenance_locks`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_manual_gateways`
--
ALTER TABLE `op_manual_gateways`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `op_merchants`
--
ALTER TABLE `op_merchants`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `op_merchant_users`
--
ALTER TABLE `op_merchant_users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

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
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

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
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

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
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2627;

--
-- AUTO_INCREMENT for table `op_refunds`
--
ALTER TABLE `op_refunds`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `op_roles`
--
ALTER TABLE `op_roles`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

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
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `op_transactions`
--
ALTER TABLE `op_transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `op_update_history`
--
ALTER TABLE `op_update_history`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

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
