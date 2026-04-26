-- Migration 005: Convert all "--" magic strings to proper NULL values
-- Run this after deploying the updated insertData/updateData functions
--
-- This migration should be run once per environment.
-- It converts legacy "--" placeholder values to SQL NULL across all tables.
--
-- Tables in this project use a dynamic prefix (default: "op_"). Replace with
-- your actual prefix before running. The statements below assume the prefix
-- "op_" — adjust or wrap each line in your preferred migration runner.

-- ---------------------------------------------------------------------------
-- Core payment tables
-- ---------------------------------------------------------------------------
UPDATE `op_transaction` SET status = NULL WHERE status = '--';
UPDATE `op_transaction` SET sender = NULL WHERE sender = '--';
UPDATE `op_transaction` SET sender_key = NULL WHERE sender_key = '--';
UPDATE `op_transaction` SET webhook_url = NULL WHERE webhook_url = '--';
UPDATE `op_transaction` SET return_url = NULL WHERE return_url = '--';
UPDATE `op_transaction` SET trx_id = NULL WHERE trx_id = '--';
UPDATE `op_transaction` SET trx_slip = NULL WHERE trx_slip = '--';
UPDATE `op_transaction` SET metadata = NULL WHERE metadata = '--';
UPDATE `op_transaction` SET customer_info = NULL WHERE customer_info = '--';
UPDATE `op_transaction` SET note = NULL WHERE note = '--';

UPDATE `op_sms_data` SET device_id = NULL WHERE device_id = '--';
UPDATE `op_sms_data` SET device_name = NULL WHERE device_name = '--';
UPDATE `op_sms_data` SET balance = NULL WHERE balance = '--';
UPDATE `op_sms_data` SET message = NULL WHERE message = '--';
UPDATE `op_sms_data` SET type = NULL WHERE type = '--';
UPDATE `op_sms_data` SET number = NULL WHERE number = '--';
UPDATE `op_sms_data` SET trx_id = NULL WHERE trx_id = '--';
UPDATE `op_sms_data` SET currency = NULL WHERE currency = '--';

-- ---------------------------------------------------------------------------
-- Brand / merchant data
-- ---------------------------------------------------------------------------
UPDATE `op_brands` SET timezone = NULL WHERE timezone = '--';
UPDATE `op_brands` SET payment_tolerance = NULL WHERE payment_tolerance = '--';
UPDATE `op_brands` SET name = NULL WHERE name = '--';
UPDATE `op_brands` SET logo = NULL WHERE logo = '--';
UPDATE `op_brands` SET favicon = NULL WHERE favicon = '--';
UPDATE `op_brands` SET signature = NULL WHERE signature = '--';
UPDATE `op_brands` SET footer = NULL WHERE footer = '--';
UPDATE `op_brands` SET street_address = NULL WHERE street_address = '--';
UPDATE `op_brands` SET city_town = NULL WHERE city_town = '--';
UPDATE `op_brands` SET postal_code = NULL WHERE postal_code = '--';
UPDATE `op_brands` SET country = NULL WHERE country = '--';
UPDATE `op_brands` SET whatsapp_number = NULL WHERE whatsapp_number = '--';
UPDATE `op_brands` SET telegram = NULL WHERE telegram = '--';
UPDATE `op_brands` SET support_email_address = NULL WHERE support_email_address = '--';
UPDATE `op_brands` SET support_phone_number = NULL WHERE support_phone_number = '--';
UPDATE `op_brands` SET support_website = NULL WHERE support_website = '--';
UPDATE `op_brands` SET currency_code = NULL WHERE currency_code = '--';
UPDATE `op_brands` SET language = NULL WHERE language = '--';
UPDATE `op_brands` SET theme = NULL WHERE theme = '--';

UPDATE `op_merchant_users` SET two_fa_secret = NULL WHERE two_fa_secret = '--';
UPDATE `op_merchant_users` SET temp_password = NULL WHERE temp_password = '--';
UPDATE `op_merchant_users` SET suspend_reason = NULL WHERE suspend_reason = '--';

-- ---------------------------------------------------------------------------
-- Customer / gateway / api
-- ---------------------------------------------------------------------------
UPDATE `op_customers` SET suspend_reason = NULL WHERE suspend_reason = '--';
UPDATE `op_customers` SET name = NULL WHERE name = '--';
UPDATE `op_customers` SET email = NULL WHERE email = '--';
UPDATE `op_customers` SET mobile = NULL WHERE mobile = '--';

UPDATE `op_gateways` SET credentials = NULL WHERE credentials = '--';
UPDATE `op_gateways` SET min_allow = NULL WHERE min_allow = '--';
UPDATE `op_gateways` SET max_allow = NULL WHERE max_allow = '--';

UPDATE `op_api_keys` SET expired_date = NULL WHERE expired_date = '--';

UPDATE `op_webhook_log` SET http_code = NULL WHERE http_code = '--';
UPDATE `op_webhook_log` SET response_body = NULL WHERE response_body = '--';

-- ---------------------------------------------------------------------------
-- Invoice / payment links
-- ---------------------------------------------------------------------------
UPDATE `op_invoice` SET metadata = NULL WHERE metadata = '--';
UPDATE `op_invoice` SET note = NULL WHERE note = '--';
UPDATE `op_invoice` SET private_note = NULL WHERE private_note = '--';
UPDATE `op_invoice` SET due_date = NULL WHERE due_date = '--';

UPDATE `op_payment_link` SET metadata = NULL WHERE metadata = '--';
UPDATE `op_payment_link` SET expired_date = NULL WHERE expired_date = '--';

UPDATE `op_payment_link_field` SET value = NULL WHERE value = '--';

UPDATE `op_currency` SET rate = NULL WHERE rate = '--';

-- ---------------------------------------------------------------------------
-- Settings / env
-- ---------------------------------------------------------------------------
UPDATE `op_env` SET value = NULL WHERE value = '--';

UPDATE `op_devices` SET last_sync = NULL WHERE last_sync = '--';

-- ---------------------------------------------------------------------------
-- Post-migration verification (uncomment to run)
-- ---------------------------------------------------------------------------
-- SELECT 'transaction' AS tbl, COUNT(*) AS remaining FROM op_transaction WHERE '--' IN (status, sender, sender_key, webhook_url, trx_id)
-- UNION ALL SELECT 'brands', COUNT(*) FROM op_brands WHERE '--' IN (timezone, name, logo, favicon)
-- UNION ALL SELECT 'env', COUNT(*) FROM op_env WHERE value = '--';

-- After verifying zero remaining "--" rows, remove the transition bridge in
-- app/core/functions.php getData() (the "--" → NULL conversion loop).
