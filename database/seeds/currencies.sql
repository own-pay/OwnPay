-- ============================================================
-- Own Pay v0.1.0 — Currency Seed Data
-- ============================================================

INSERT INTO `op_currencies` (`code`, `name`, `symbol`, `decimal_places`, `status`) VALUES
('BDT', 'Bangladeshi Taka', '৳', 2, 'active'),
('USD', 'US Dollar', '$', 2, 'active'),
('EUR', 'Euro', '€', 2, 'active'),
('GBP', 'British Pound', '£', 2, 'active'),
('INR', 'Indian Rupee', '₹', 2, 'active'),
('JPY', 'Japanese Yen', '¥', 0, 'active'),
('CNY', 'Chinese Yuan', '¥', 2, 'active'),
('CAD', 'Canadian Dollar', 'C$', 2, 'inactive'),
('AUD', 'Australian Dollar', 'A$', 2, 'inactive'),
('SGD', 'Singapore Dollar', 'S$', 2, 'inactive'),
('MYR', 'Malaysian Ringgit', 'RM', 2, 'inactive'),
('AED', 'UAE Dirham', 'د.إ', 2, 'inactive'),
('SAR', 'Saudi Riyal', '﷼', 2, 'inactive'),
('PKR', 'Pakistani Rupee', '₨', 2, 'inactive'),
('NPR', 'Nepalese Rupee', 'रू', 2, 'inactive');

-- Default exchange rates (base: BDT)
INSERT INTO `op_exchange_rates` (`base_currency`, `target_currency`, `rate`, `source`) VALUES
('BDT', 'USD', 0.00833, 'manual'),
('BDT', 'EUR', 0.00769, 'manual'),
('BDT', 'GBP', 0.00658, 'manual'),
('BDT', 'INR', 0.69444, 'manual'),
('USD', 'BDT', 120.00000, 'manual'),
('EUR', 'BDT', 130.00000, 'manual'),
('GBP', 'BDT', 152.00000, 'manual');
