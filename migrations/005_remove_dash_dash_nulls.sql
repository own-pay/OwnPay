-- Migration 005: Convert all "--" magic strings to proper NULL values
-- Run this after deploying the updated insertData/updateData functions
--
-- This migration should be run once per environment.
-- It converts legacy "--" placeholder values to SQL NULL across all tables.

-- Core tables
UPDATE `transaction` SET status = NULL WHERE status = '--';
UPDATE `transaction` SET sender = NULL WHERE sender = '--';
UPDATE `transaction` SET sender_key = NULL WHERE sender_key = '--';
UPDATE `transaction` SET webhook_url = NULL WHERE webhook_url = '--';
UPDATE `transaction` SET trx_id = NULL WHERE trx_id = '--';
UPDATE `transaction` SET metadata = NULL WHERE metadata = '--';
UPDATE `transaction` SET customer_info = NULL WHERE customer_info = '--';

UPDATE `sms_data` SET device_id = NULL WHERE device_id = '--';
UPDATE `sms_data` SET device_name = NULL WHERE device_name = '--';
UPDATE `sms_data` SET balance = NULL WHERE balance = '--';
UPDATE `sms_data` SET message = NULL WHERE message = '--';

UPDATE `brands` SET timezone = NULL WHERE timezone = '--';
UPDATE `brands` SET payment_tolerance = NULL WHERE payment_tolerance = '--';

UPDATE `merchant_users` SET two_fa_secret = NULL WHERE two_fa_secret = '--';
UPDATE `merchant_users` SET temp_password = NULL WHERE temp_password = '--';

UPDATE `gateways` SET credentials = NULL WHERE credentials = '--';

UPDATE `webhook_log` SET http_code = NULL WHERE http_code = '--';

UPDATE `invoice` SET metadata = NULL WHERE metadata = '--';
UPDATE `payment_link` SET metadata = NULL WHERE metadata = '--';

UPDATE `currency` SET rate = NULL WHERE rate = '--';

-- Settings/env values (if stored in a table)
-- Note: .env file values using "--" should be updated to empty string or removed
