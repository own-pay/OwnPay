-- ============================================================
-- OwnPay v0.1.0 - Default System Settings Seed
-- ============================================================

INSERT INTO `op_system_settings` (`group_name`, `key_name`, `value`, `type`) VALUES
-- General
('general', 'site_name', 'OwnPay', 'string'),
('general', 'site_tagline', 'Open Source Payment Gateway', 'string'),
('general', 'default_currency', 'BDT', 'string'),
('general', 'default_timezone', 'Asia/Dhaka', 'string'),
('general', 'landing_page_enabled', '1', 'bool'),
('general', 'login_path', '/login', 'string'),
('general', 'admin_path', '/admin', 'string'),
('general', 'date_format', 'd M Y', 'string'),
('general', 'time_format', 'h:i A', 'string'),
-- Checkout
('checkout', 'timer_seconds', '600', 'int'),
('checkout', 'require_customer_email', '1', 'bool'),
('checkout', 'require_customer_phone', '0', 'bool'),
('checkout', 'show_faq', '1', 'bool'),
('checkout', 'active_theme', 'own-pay', 'string'),
-- Security
('security', 'max_login_attempts', '20', 'int'),
('security', 'lockout_duration', '3', 'int'),
('security', 'session_lifetime', '7200', 'int'),
('security', 'force_https', '1', 'bool'),
('security', 'two_factor_required', '0', 'bool'),
-- API
('api', 'rate_limit_per_minute', '120', 'int'),
('api', 'api_version', '1.0', 'string'),
-- Mobile
('mobile', 'poll_interval_seconds', '30', 'int'),
('mobile', 'max_paired_devices', '5', 'int'),
('mobile', 'sms_auto_clean_days', '7', 'int'),
-- Update
('update', 'auto_check_enabled', '1', 'bool'),
('update', 'auto_update_enabled', '0', 'bool'),
('update', 'update_check_url', 'https://update.ownpay.org/update.json', 'string'),
-- FAQ (JSON array of Q&A pairs)
('faq', 'items', '[]', 'json');
