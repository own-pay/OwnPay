-- Seed default language rows so brand/checkout forms have a populated language list.
-- Fresh installs get these rows from InstallerController::finalize(); this migration
-- backfills existing deployments whose op_languages table is empty.
-- INSERT IGNORE is idempotent on the uk_code unique key.

INSERT IGNORE INTO `op_languages` (code, name, status, is_default, translations) VALUES
('en', 'English', 'active', 1, '{}'),
('bn', 'Bengali', 'active', 0, '{}'),
('hi', 'Hindi',   'active', 0, '{}'),
('ar', 'Arabic',  'active', 0, '{}');
