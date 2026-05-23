-- Migration: Create brand-scoped plugin status mappings table
-- Slug: create_brand_plugins_table

CREATE TABLE IF NOT EXISTS `op_brand_plugins` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `plugin_slug` VARCHAR(80) NOT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'inactive',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_brand_plugin` (`merchant_id`, `plugin_slug`),
  CONSTRAINT `fk_brand_plugins_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_brand_plugins_slug` FOREIGN KEY (`plugin_slug`) REFERENCES `op_plugins` (`slug`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
