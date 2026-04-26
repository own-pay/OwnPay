-- Migration 006: Create op_plugins and op_plugin_migrations tables
-- Part of Milestone 2: Plugin Loader & Registry

CREATE TABLE IF NOT EXISTS `op_plugins` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug`            VARCHAR(60) NOT NULL,
    `name`            VARCHAR(150) NOT NULL,
    `type`            ENUM('plugin','gateway','theme') NOT NULL DEFAULT 'plugin',
    `version`         VARCHAR(20) NOT NULL,
    `status`          ENUM('installed','active','inactive') NOT NULL DEFAULT 'installed',
    `entrypoint`      VARCHAR(100) NOT NULL DEFAULT 'Plugin.php',
    `capabilities`    JSON NULL,
    `manifest_hash`   CHAR(64) NOT NULL,
    `load_order`      INT NOT NULL DEFAULT 100,
    `activated_at`    DATETIME NULL,
    `installed_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_plugin_slug` (`slug`),
    INDEX `idx_plugin_status` (`status`, `load_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_plugin_migrations` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `plugin_slug` VARCHAR(60) NOT NULL,
    `migration`   VARCHAR(255) NOT NULL,
    `batch`       INT NOT NULL,
    `applied_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_plugin_migration` (`plugin_slug`, `migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
