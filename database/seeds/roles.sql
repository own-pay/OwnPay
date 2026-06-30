-- ============================================================
-- OwnPay v0.1.0 - Default Roles & Permissions Seed
-- ============================================================

-- Permissions (grouped by feature area)
INSERT INTO `op_permissions` (`slug`, `name`, `group_name`) VALUES
-- Dashboard
('dashboard.view', 'View Dashboard', 'dashboard'),
('dashboard.stats', 'View Statistics', 'dashboard'),
-- Transactions
('transactions.view', 'View Transactions', 'transactions'),
('transactions.manage', 'Manage Transactions', 'transactions'),
('transactions.update', 'Update Transactions', 'transactions'),
('transactions.export', 'Export Transactions', 'transactions'),
-- Invoices
('invoices.view', 'View Invoices', 'invoices'),
('invoices.manage', 'Manage Invoices', 'invoices'),
('invoices.create', 'Create Invoices', 'invoices'),
('invoices.update', 'Update Invoices', 'invoices'),
('invoices.delete', 'Delete Invoices', 'invoices'),
-- Payment Links
('payment_links.view', 'View Payment Links', 'payment_links'),
('payment_links.manage', 'Manage Payment Links', 'payment_links'),
('payment_links.create', 'Create Payment Links', 'payment_links'),
('payment_links.update', 'Update Payment Links', 'payment_links'),
('payment_links.delete', 'Delete Payment Links', 'payment_links'),
-- Customers
('customers.view', 'View Customers', 'customers'),
('customers.manage', 'Manage Customers', 'customers'),
('customers.create', 'Create Customers', 'customers'),
('customers.update', 'Update Customers', 'customers'),
-- Gateways
('gateways.view', 'View Gateways', 'gateways'),
('gateways.manage', 'Manage Gateways', 'gateways'),
-- Staff
('staff.view', 'View Staff', 'staff'),
('staff.manage', 'Manage Staff', 'staff'),
('staff.create', 'Create Staff', 'staff'),
('staff.update', 'Update Staff', 'staff'),
('staff.delete', 'Delete Staff', 'staff'),
('brands.view', 'View Brands', 'brands'),
('brands.manage', 'Manage Brands', 'brands'),
-- Merchants
('merchants.view', 'View Merchants', 'merchants'),
('merchants.create', 'Create Merchants', 'merchants'),
('merchants.update', 'Update Merchants', 'merchants'),
-- Settings
('settings.view', 'View Settings', 'settings'),
('settings.manage', 'Manage Settings', 'settings'),
('settings.update', 'Update Settings', 'settings'),
-- API Keys
('api_keys.view', 'View API Keys', 'api_keys'),
('api_keys.manage', 'Manage API Keys', 'api_keys'),
-- Webhooks
('webhooks.view', 'View Webhooks', 'webhooks'),
('webhooks.manage', 'Manage Webhooks', 'webhooks'),
-- Domains
('domains.view', 'View Domains', 'domains'),
('domains.manage', 'Manage Domains', 'domains'),
-- SMS
('sms.view', 'View SMS Data', 'sms'),
('sms.manage', 'Manage SMS Templates', 'sms'),
-- Devices
('devices.view', 'View Devices', 'devices'),
('devices.manage', 'Manage Devices', 'devices'),
-- Plugins
('plugins.view', 'View Plugins', 'plugins'),
('plugins.manage', 'Install/Remove Plugins', 'plugins'),
-- System
('system.update', 'System Updates', 'system'),
('system.audit', 'View Audit Log', 'system'),
('system.reports', 'View Reports', 'system'),
('system.balance', 'Balance Verification', 'system'),
('admin.access', 'Basic Admin Access', 'admin');
