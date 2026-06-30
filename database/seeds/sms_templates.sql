-- ============================================================
-- OwnPay v0.1.0 - Default SMS Templates Seed
-- ============================================================
-- Templates for Bangladeshi MFS providers.
-- merchant_id NULL = global (all merchants can use).

INSERT INTO `op_sms_templates` (`merchant_id`, `gateway_slug`, `sender_pattern`, `amount_regex`, `trx_id_regex`, `sender_regex`, `priority`, `status`) VALUES
-- bKash
(NULL, 'bkash', 'bKash', 'Tk ([\\d,]+\\.?\\d*)', 'TrxID ([A-Za-z0-9]+)', 'from\\s*([0-9]+)', 10, 'active'),
-- Nagad
(NULL, 'nagad', 'Nagad', 'Tk\\.?\\s*([\\d,]+\\.?\\d*)', 'TxnNo\\.?\\s*([A-Za-z0-9]+)', 'from\\s*([0-9]+)', 10, 'active'),
-- Rocket
(NULL, 'rocket', 'Rocket', 'Tk\\.?\\s*([\\d,]+\\.?\\d*)', 'TxnId[:\\s]*([A-Za-z0-9]+)', 'from\\s*([0-9]+)', 10, 'active'),
-- Upay
(NULL, 'upay', 'Upay', 'BDT\\s*([\\d,]+\\.?\\d*)', 'TxnId[:\\s]*([A-Za-z0-9]+)', 'from\\s*([0-9]+)', 20, 'active'),
-- SureCash
(NULL, 'surecash', 'SureCash', 'Tk\\.?\\s*([\\d,]+\\.?\\d*)', NULL, 'from\\s*([0-9]+)', 20, 'active'),
-- Tap (Islami Bank)
(NULL, 'tap', 'Tap', 'BDT\\s*([\\d,]+\\.?\\d*)', 'Ref[:\\s]*([A-Za-z0-9]+)', 'from\\s*([0-9]+)', 20, 'active');
