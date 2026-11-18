-- Adds the sms.view_raw permission (SMS raw-body reveal gate) for existing installations.
-- Super Admins bypass permission checks; brand roles can grant this per-role as needed.
INSERT IGNORE INTO `op_permissions` (`slug`, `name`, `group_name`) VALUES
('sms.view_raw', 'View Raw SMS Data', 'sms');
