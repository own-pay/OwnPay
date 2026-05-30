-- Add admin scope to all existing API keys
UPDATE `op_api_keys` SET `scopes` = '["read", "write", "admin"]' WHERE `scopes` = '["read", "write"]' OR `scopes` IS NULL;
