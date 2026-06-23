<?php
declare(strict_types=1);

/**
 * OwnPay Local Development Database Seeder
 * 
 * Standalone script to seed the database with diverse dummy data.
 * Safe to run multiple times (uses INSERT IGNORE / check-before-insert).
 */

// 1. Boot Application Kernel and Container
require_once dirname(__DIR__) . '/vendor/autoload.php';
$kernel = new \OwnPay\Kernel();

// Boot the kernel and get the container
$reflection = new \ReflectionClass($kernel);
$bootMethod = $reflection->getMethod('boot');
$bootMethod->setAccessible(true);
$bootMethod->invoke($kernel);

$container = $kernel->getContainer();
$pdo = $container->get(\PDO::class);
$encryptor = $container->get(\OwnPay\Security\FieldEncryptor::class);

echo "\033[36m[OwnPay Seeder] Seeding comprehensive dummy data into local database...\033[0m\n";

// Disable foreign key checks for clean truncation/deletes
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// Truncate/Clear all transactional tables for a clean seed state
$tablesToClear = [
    'op_audit_logs',
    'op_login_attempts',
    'op_ledger_entries',
    'op_ledger_transactions',
    'op_ledger_accounts',
    'op_sms_parsed',
    'op_mobile_notifications',
    'op_paired_devices',
    'op_webhook_delivery_logs',
    'op_webhook_events',
    'op_webhook_deliveries',
    'op_webhooks',
    'op_disputes',
    'op_refunds',
    'op_transactions',
    'op_payment_intents',
    'op_invoice_items',
    'op_invoices',
    'op_payment_links',
    'op_payment_link_fields',
    'op_customers',
    'op_domains',
    'op_gateway_configs',
    'op_merchant_users',
    'op_roles',
    'op_merchants',
    'op_manual_gateways',
    'op_gateways',
    'op_exchange_rates',
    'op_role_permissions'
];

foreach ($tablesToClear as $tbl) {
    $pdo->exec("TRUNCATE TABLE `{$tbl}`");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// Helper for query execution
function executeQuery(\PDO $pdo, string $sql, array $params = []): void {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

// Helper for generating UUIDs
function makeUuid(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// 2. Seed Permissions (if empty)
$permissionsCount = $pdo->query("SELECT COUNT(*) FROM `op_permissions`")->fetchColumn();
if ($permissionsCount == 0) {
    echo "  -> Seeding default permissions...\n";
    $permissionsSql = @file_get_contents(dirname(__DIR__) . '/database/seeds/roles.sql');
    if ($permissionsSql) {
        $cleanSql = preg_replace('/--.*$/m', '', $permissionsSql);
        $cleanSql = preg_replace('/#.*$/m', '', $cleanSql);
        $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt) && str_starts_with(strtoupper($stmt), 'INSERT')) {
                $pdo->exec($stmt);
            }
        }
    }
}

// 3. Seed Currencies (if empty)
$currenciesCount = $pdo->query("SELECT COUNT(*) FROM `op_currencies`")->fetchColumn();
if ($currenciesCount == 0) {
    echo "  -> Seeding currencies...\n";
    $currenciesSql = @file_get_contents(dirname(__DIR__) . '/database/seeds/currencies.sql');
    if ($currenciesSql) {
        $cleanSql = preg_replace('/--.*$/m', '', $currenciesSql);
        $cleanSql = preg_replace('/#.*$/m', '', $cleanSql);
        $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt) && str_starts_with(strtoupper($stmt), 'INSERT')) {
                $pdo->exec($stmt);
            }
        }
    }
} else {
    executeQuery($pdo, "INSERT IGNORE INTO `op_currencies` (`code`, `name`, `symbol`, `decimal_places`, `status`) VALUES 
        ('BDT', 'Bangladeshi Taka', '৳', 2, 'active'),
        ('USD', 'US Dollar', '$', 2, 'active'),
        ('EUR', 'Euro', '€', 2, 'active')");
}

// 4. Seed Exchange Rates
executeQuery($pdo, "INSERT IGNORE INTO `op_exchange_rates` (`base_currency`, `target_currency`, `rate`, `source`) VALUES 
    ('USD', 'BDT', 117.50, 'manual'),
    ('EUR', 'BDT', 126.20, 'manual'),
    ('BDT', 'USD', 0.0085, 'manual')");

// 5. Seed Merchants/Brands
$merchants = [
    [1, 'default-brand-uuid-00000000001', 'Default Brand Store', 'default-brand', 'contact@myshop.com', '01700000000', 'Asia/Dhaka', 'BDT', 'active'],
    [2, 'secondary-brand-uuid-000000002', 'Secondary Digital Shop', 'secondary-brand', 'billing@digitalgoods.net', '01900000000', 'Asia/Dhaka', 'BDT', 'active'],
    [3, 'sandbox-brand-uuid-00000000003', 'Sandbox Test Brand', 'sandbox-brand', 'sandbox@ownpay.org', '01800000000', 'Asia/Dhaka', 'BDT', 'active']
];

echo "  -> Seeding merchants...\n";
foreach ($merchants as $m) {
    executeQuery($pdo, "INSERT INTO `op_merchants` (`id`, `uuid`, `name`, `slug`, `email`, `phone`, `timezone`, `default_currency`, `status`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $m[0], $m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $m[7], $m[8]
        ]);
}

// 6. Seed System Roles for each Merchant/Brand
echo "  -> Seeding system roles & wiring permissions...\n";
$roles = [
    ['Super Administrator', 'superadmin', 'Full access to all system settings, brands, and transactions', 1],
    ['Merchant Owner', 'owner', 'Full access to assigned merchant brands and store transactions', 1],
    ['Billing Manager', 'billing', 'Can view transactions, refunds, and manage invoices', 0],
    ['Support Agent', 'support', 'Can view transactions and process refunds, no system configurations', 0]
];

$allPermissions = $pdo->query("SELECT id, slug FROM `op_permissions`")->fetchAll(\PDO::FETCH_KEY_PAIR);

foreach ([1, 2, 3] as $merchantId) {
    foreach ($roles as $roleInfo) {
        executeQuery($pdo, "INSERT INTO `op_roles` (`merchant_id`, `name`, `slug`, `description`, `is_system`) 
            VALUES (?, ?, ?, ?, ?)", [
                $merchantId, $roleInfo[0], $roleInfo[1], $roleInfo[2], $roleInfo[3]
            ]);
        
        $roleIdStmt = $pdo->prepare("SELECT id FROM `op_roles` WHERE `merchant_id` = ? AND `slug` = ? LIMIT 1");
        $roleIdStmt->execute([$merchantId, $roleInfo[1]]);
        $roleId = $roleIdStmt->fetchColumn();

        if ($roleId) {
            $rolePerms = [];
            if ($roleInfo[1] === 'superadmin') {
                $rolePerms = array_keys($allPermissions);
            } elseif ($roleInfo[1] === 'owner') {
                $rolePerms = array_filter(array_keys($allPermissions), function($id) use ($allPermissions) {
                    $slugs = ['settings.view', 'settings.manage', 'settings.update', 'system.update'];
                    $slug = $allPermissions[$id];
                    return !in_array($slug, $slugs);
                });
            } elseif ($roleInfo[1] === 'billing') {
                $rolePerms = array_filter(array_keys($allPermissions), function($id) use ($allPermissions) {
                    $slugs = ['transactions.view', 'transactions.export', 'invoices.view', 'invoices.manage', 'invoices.create', 'invoices.update', 'payment_links.view', 'customers.view', 'system.reports', 'admin.access'];
                    $slug = $allPermissions[$id];
                    return in_array($slug, $slugs);
                });
            } elseif ($roleInfo[1] === 'support') {
                $rolePerms = array_filter(array_keys($allPermissions), function($id) use ($allPermissions) {
                    $slugs = ['transactions.view', 'invoices.view', 'customers.view', 'admin.access'];
                    $slug = $allPermissions[$id];
                    return in_array($slug, $slugs);
                });
            }
            
            foreach ($rolePerms as $permId) {
                executeQuery($pdo, "INSERT INTO `op_role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)", [$roleId, $permId]);
            }
        }
    }
}

// 7. Seed System Settings (Global settings)
echo "  -> Seeding system settings...\n";
$settingsSeeds = [
    ['general', 'app_name', 'OwnPay', 'string'],
    ['general', 'timezone', 'Asia/Dhaka', 'string'],
    ['general', 'currency', 'BDT', 'string'],
    ['general', 'active_theme', 'own-pay', 'string'],
    ['general', 'version', '0.1.0', 'string'],
    ['branding', 'site_name', 'OwnPay Gateway', 'string'],
    ['branding', 'site_logo', '', 'string'],
    ['branding', 'site_favicon', '', 'string'],
    ['branding', 'primary_color', '#4f46e5', 'string'],
    ['branding', 'footer_text', '© 2026 OwnPay Project', 'string']
];
foreach ($settingsSeeds as $s) {
    executeQuery($pdo, "INSERT INTO `op_system_settings` (`group_name`, `key_name`, `value`, `type`, `merchant_id`) VALUES (?, ?, ?, ?, NULL)", $s);
}

// 8. Seed Merchant Users (Admin, Owner, Billing, Support)
echo "  -> Seeding admin & staff users...\n";
$users = [
    [1, 'superadmin', 'Rahim Ahmed', 'rahim', 'rahim@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01711223344', 1],
    [1, 'owner', 'Alex Admin', 'admin', 'admin@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01700000000', 0],
    [1, 'billing', 'Tahmid Rahman', 'tahmid', 'tahmid@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01911223344', 0],
    [1, 'support', 'Sabina Yeasmin', 'sabina', 'sabina@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01811223344', 0],
    [2, 'owner', 'Javed Karim', 'javed', 'javed@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01511223344', 0],
    [3, 'owner', 'Sandbox Dev', 'sandbox', 'sandbox@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01311223344', 0]
];

foreach ($users as $u) {
    $roleIdStmt = $pdo->prepare("SELECT id FROM `op_roles` WHERE `merchant_id` = ? AND `slug` = ? LIMIT 1");
    $roleIdStmt->execute([$u[0], $u[1]]);
    $roleId = $roleIdStmt->fetchColumn();
    
    if ($roleId) {
        executeQuery($pdo, "INSERT INTO `op_merchant_users` 
            (`merchant_id`, `role_id`, `name`, `username`, `email`, `password_hash`, `phone`, `is_superadmin`, `status`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')", [
                $u[0], $roleId, $u[2], $u[3], $u[4], $u[5], $u[6], $u[7]
            ]);
    }
}

// 9. Seed Custom Domains
echo "  -> Seeding white-label domains...\n";
executeQuery($pdo, "INSERT INTO `op_domains` (`merchant_id`, `domain`, `type`, `dns_verified`, `ssl_status`, `is_primary`, `status`) VALUES 
    (1, 'checkout.myshop.com', 'checkout', 1, 'active', 1, 'active'),
    (2, 'pay.digitalgoods.net', 'checkout', 1, 'active', 1, 'active')");

// 10. Seed Builtin Gateways
echo "  -> Seeding gateway definitions...\n";
$gateways = [
    ['stripe', 'Stripe Gateway', 'api', 'stripe.png', 1, 1],
    ['bkash-api', 'bKash API Checkout', 'api', 'bkash.png', 1, 2],
    ['sslcommerz', 'SSLCommerz', 'api', 'sslcommerz.png', 1, 3],
    ['nagad-api', 'Nagad API Gateway', 'api', 'nagad.png', 1, 4]
];
foreach ($gateways as $g) {
    executeQuery($pdo, "INSERT INTO `op_gateways` (`slug`, `name`, `type`, `logo_path`, `is_builtin`, `sort_order`, `status`) 
        VALUES (?, ?, ?, ?, ?, ?, 'active')", $g);
}

// 11. Seed Merchant Gateway Configs
$stripeGwId = $pdo->query("SELECT id FROM `op_gateways` WHERE `slug` = 'stripe'")->fetchColumn();
$bkashGwId = $pdo->query("SELECT id FROM `op_gateways` WHERE `slug` = 'bkash-api'")->fetchColumn();
if ($stripeGwId && $bkashGwId) {
    executeQuery($pdo, "INSERT INTO `op_gateway_configs` (`merchant_id`, `gateway_id`, `credentials_enc`, `settings`, `mode`, `status`) VALUES 
        (1, {$stripeGwId}, 'enc_dummy_stripe_credentials', '{\"publishable_key\":\"pk_test_123\",\"secret_key\":\"sk_test_123\"}', 'sandbox', 'active'),
        (1, {$bkashGwId}, 'enc_dummy_bkash_credentials', '{\"app_key\":\"bk_test_123\",\"app_secret\":\"bk_sec_123\",\"username\":\"bk_usr\",\"password\":\"bk_pw\"}', 'sandbox', 'active'),
        (2, {$stripeGwId}, 'enc_dummy_stripe_credentials', '{\"publishable_key\":\"pk_test_456\",\"secret_key\":\"sk_test_456\"}', 'sandbox', 'active')");
}

// 12. Seed Manual Gateways (bKash & Nagad Personal)
echo "  -> Seeding manual gateways...\n";
executeQuery($pdo, "INSERT INTO `op_manual_gateways` 
    (`merchant_id`, `slug`, `name`, `logo_path`, `instructions`, `sms_verification`, `sms_sender_pattern`, `sms_regex_template`, `currency`, `min_amount`, `max_amount`, `status`)
    VALUES 
    (1, 'bkash-personal', 'bKash Personal', 'uploads/bkash.png', '\"Send money to 01700000000. Use your Transaction ID below for instant match verification.\"', 1, 'bKash', '\"You have received Tk ([0-9,]+\\\\.[0-9]{2})\\\\. From ([0-9]+)\\\\. Ref.*TrxID (\\\\w+)\"', 'BDT', 10.00, 25000.00, 'active'),
    (1, 'nagad-personal', 'Nagad Personal', 'uploads/nagad.png', '\"Send money to 01900000000. Fill up the transaction hash code after sending.\"', 1, 'Nagad', '\"Nagad received BDT ([0-9,]+\\\\.[0-9]{2}) from ([0-9]+)\\\\. TxnID (\\\\w+)\"', 'BDT', 5.00, 20000.00, 'active'),
    (2, 'bkash-personal', 'bKash Personal', 'uploads/bkash.png', '\"Send money to 01800000000. Use your Transaction ID below for instant match verification.\"', 1, 'bKash', '\"You have received Tk ([0-9,]+\\\\.[0-9]{2})\\\\. From ([0-9]+)\\\\. Ref.*TrxID (\\\\w+)\"', 'BDT', 10.00, 25000.00, 'active')");

// 13. Seed Customers (with encrypted PII using FieldEncryptor)
echo "  -> Seeding customers with encrypted PII...\n";
$customersData = [
    [1, 'John Doe', 'john.doe@example.com', '01712345678'],
    [1, 'Kamal Sen', 'kamal.sen@example.com', '01911223344'],
    [1, 'Nabil Rahman', 'nabil@example.com', '01811223300'],
    [2, 'Abir Saha', 'abir.saha@example.com', '01811223344'],
    [2, 'Farhana Yesmin', 'farhana@example.com', '01711223399']
];

foreach ($customersData as $c) {
    $uuid = makeUuid();
    $nameEnc = $encryptor->encrypt($c[1]);
    $emailEnc = $encryptor->encrypt($c[2]);
    $emailHash = $encryptor->deterministicHash($c[2]);
    $phoneEnc = $encryptor->encrypt($c[3]);
    $phoneHash = $encryptor->deterministicHash($c[3]);
    
    executeQuery($pdo, "INSERT INTO `op_customers` (`merchant_id`, `uuid`, `name_enc`, `email_enc`, `email_hash`, `phone_enc`, `phone_hash`) 
        VALUES (?, ?, ?, ?, ?, ?, ?)", [
            $c[0], $uuid, $nameEnc, $emailEnc, $emailHash, $phoneEnc, $phoneHash
        ]);
}

// 14. Seed Payment Links
echo "  -> Seeding payment links...\n";
executeQuery($pdo, "INSERT INTO `op_payment_links` (`id`, `merchant_id`, `uuid`, `slug`, `title`, `description`, `amount`, `currency`, `is_amount_fixed`, `status`) VALUES 
    (1, 1, 'pl-uuid-0000000000000000001', 'setup-fee', 'Software Service Setup Fee', 'One-time platform setup fee', 10000.00, 'BDT', 1, 'active'),
    (2, 1, 'pl-uuid-0000000000000000002', 'donation', 'Support Donation', 'Support development of OwnPay open source', NULL, 'BDT', 0, 'active'),
    (3, 2, 'pl-uuid-0000000000000000003', 'pro-license', 'Pro Software License', 'Commercial license key activation', 50.00, 'USD', 1, 'active')");

// 15. Seed Invoices & Items
echo "  -> Seeding invoices...\n";
$c1 = $pdo->query("SELECT id FROM `op_customers` WHERE `email_hash` = '" . $encryptor->deterministicHash('john.doe@example.com') . "' LIMIT 1")->fetchColumn();
$c2 = $pdo->query("SELECT id FROM `op_customers` WHERE `email_hash` = '" . $encryptor->deterministicHash('kamal.sen@example.com') . "' LIMIT 1")->fetchColumn();
$c3 = $pdo->query("SELECT id FROM `op_customers` WHERE `email_hash` = '" . $encryptor->deterministicHash('abir.saha@example.com') . "' LIMIT 1")->fetchColumn();

// Invoice 1 (Paid)
if ($c1) {
    executeQuery($pdo, "INSERT INTO `op_invoices` (`id`, `merchant_id`, `uuid`, `token`, `invoice_number`, `customer_id`, `subtotal`, `tax`, `discount`, `total`, `currency`, `notes`, `due_date`, `status`, `paid_at`) VALUES 
        (1, 1, 'inv-uuid-000000000000001', 'inv-token-0000000000000001', 'INV-2026-001', {$c1}, 2500.00, 0.00, 0.00, 2500.00, 'BDT', 'Enterprise Development Setup', '2026-06-25', 'paid', '2026-06-12 11:32:00')");
    executeQuery($pdo, "INSERT INTO `op_invoice_items` (`invoice_id`, `description`, `quantity`, `unit_price`, `total`) VALUES (1, 'Enterprise Development Service', 1.00, 2500.00, 2500.00)");
}

// Invoice 2 (Sent)
if ($c2) {
    executeQuery($pdo, "INSERT INTO `op_invoices` (`id`, `merchant_id`, `uuid`, `token`, `invoice_number`, `customer_id`, `subtotal`, `tax`, `discount`, `total`, `currency`, `notes`, `due_date`, `status`) VALUES 
        (2, 1, 'inv-uuid-000000000000002', 'inv-token-0000000000000002', 'INV-2026-002', {$c2}, 1500.00, 0.00, 0.00, 1500.00, 'BDT', 'Monthly consulting fees', '2026-06-30', 'sent')");
    executeQuery($pdo, "INSERT INTO `op_invoice_items` (`invoice_id`, `description`, `quantity`, `unit_price`, `total`) VALUES (2, 'Consulting services per hour', 3.00, 500.00, 1500.00)");
}

// Invoice 3 (Paid - USD)
if ($c3) {
    executeQuery($pdo, "INSERT INTO `op_invoices` (`id`, `merchant_id`, `uuid`, `token`, `invoice_number`, `customer_id`, `subtotal`, `tax`, `discount`, `total`, `currency`, `notes`, `due_date`, `status`, `paid_at`) VALUES 
        (3, 2, 'inv-uuid-000000000000003', 'inv-token-0000000000000003', 'INV-2026-003', {$c3}, 120.00, 0.00, 0.00, 120.00, 'USD', 'E-commerce Plugin Licensing', '2026-06-15', 'paid', '2026-06-14 10:15:00')");
    executeQuery($pdo, "INSERT INTO `op_invoice_items` (`invoice_id`, `description`, `quantity`, `unit_price`, `total`) VALUES (3, 'Pro E-commerce software license key', 1.00, 120.00, 120.00)");
}

// Invoice 4 (Overdue - BDT)
if ($c1) {
    executeQuery($pdo, "INSERT INTO `op_invoices` (`id`, `merchant_id`, `uuid`, `token`, `invoice_number`, `customer_id`, `subtotal`, `tax`, `discount`, `total`, `currency`, `notes`, `due_date`, `status`) VALUES 
        (4, 1, 'inv-uuid-000000000000004', 'inv-token-0000000000000004', 'INV-2026-004', {$c1}, 500.00, 0.00, 0.00, 500.00, 'BDT', 'Stale bill', '2026-05-01', 'overdue')");
    executeQuery($pdo, "INSERT INTO `op_invoice_items` (`invoice_id`, `description`, `quantity`, `unit_price`, `total`) VALUES (4, 'Setup and onboarding guidance session', 1.00, 500.00, 500.00)");
}

// 16. Seed Payment Intents (Diverse statuses for dashboards/lists)
echo "  -> Seeding payment intents...\n";
$piData = [
    [1, 'pi-uuid-0000000000000000001', 'pi-token-0000000000000000001', $c1, 2500.00, 'BDT', 'completed', '2026-06-25 12:00:00'],
    [1, 'pi-uuid-0000000000000000002', 'pi-token-0000000000000000002', $c2, 1200.00, 'BDT', 'pending', '2026-06-28 12:00:00'],
    [1, 'pi-uuid-0000000000000000003', 'pi-token-0000000000000000003', $c1, 8000.00, 'BDT', 'processing', '2026-06-20 12:00:00'],
    [1, 'pi-uuid-0000000000000000004', 'pi-token-0000000000000000004', $c2, 1500.00, 'BDT', 'expired', '2026-05-15 12:00:00'],
    [2, 'pi-uuid-0000000000000000005', 'pi-token-0000000000000000005', $c3, 120.00, 'USD', 'completed', '2026-06-20 12:00:00'],
    [2, 'pi-uuid-0000000000000000006', 'pi-token-0000000000000000006', $c3, 50.00, 'USD', 'completed', '2026-06-20 12:00:00'],
    [2, 'pi-uuid-0000000000000000007', 'pi-token-0000000000000000007', $c3, 85.00, 'USD', 'completed', '2026-06-20 12:00:00'],
    [2, 'pi-uuid-0000000000000000008', 'pi-token-0000000000000000008', $c3, 10.00, 'USD', 'failed', '2026-06-10 12:00:00']
];

foreach ($piData as $pi) {
    executeQuery($pdo, "INSERT INTO `op_payment_intents` (`merchant_id`, `uuid`, `token`, `customer_id`, `amount`, `currency`, `status`, `expires_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)", $pi);
}

// 17. Seed Transactions
echo "  -> Seeding transactions...\n";
$piMap = $pdo->query("SELECT token, id FROM `op_payment_intents`")->fetchAll(\PDO::FETCH_KEY_PAIR);

// We define our transactions to insert.
// Completed transactions will automatically trigger ledger inserts in the next step.
$transactionsToSeed = [
    // Merchant 1 (BDT)
    [
        'id' => 1, 'merchant_id' => 1, 'uuid' => 'tx-uuid-0000000000000000001', 'trx_id' => 'OP_TRX_99283711', 
        'payment_intent_id' => $piMap['pi-token-0000000000000000001'] ?? null, 'customer_id' => $c1, 
        'gateway_slug' => 'bkash-personal', 'amount' => 2500.00, 'fee' => 50.00, 'net_amount' => 2450.00, 'currency' => 'BDT', 
        'sender_account' => '01712345678', 'reference' => 'Reference Inv-001', 'gateway_trx_id' => 'BKASH99283711', 
        'method' => 'manual', 'status' => 'completed', 'metadata' => json_encode(['invoice_id' => 1, 'payment_link_id' => 1]), 'completed_at' => '2026-06-12 11:32:00'
    ],
    [
        'id' => 2, 'merchant_id' => 1, 'uuid' => 'tx-uuid-0000000000000000002', 'trx_id' => 'OP_TRX_88291022', 
        'payment_intent_id' => $piMap['pi-token-0000000000000000002'] ?? null, 'customer_id' => $c2, 
        'gateway_slug' => 'nagad-personal', 'amount' => 1200.00, 'fee' => 24.00, 'net_amount' => 1176.00, 'currency' => 'BDT', 
        'sender_account' => '01911223344', 'reference' => 'Setup fee BDT', 'gateway_trx_id' => 'NAGAD88291022', 
        'method' => 'manual', 'status' => 'pending', 'metadata' => json_encode(['payment_link_id' => 1]), 'completed_at' => null
    ],
    [
        'id' => 3, 'merchant_id' => 1, 'uuid' => 'tx-uuid-0000000000000000003', 'trx_id' => 'OP_TRX_66371192', 
        'payment_intent_id' => null, 'customer_id' => $c2, 
        'gateway_slug' => 'bkash-personal', 'amount' => 5000.00, 'fee' => 100.00, 'net_amount' => 4900.00, 'currency' => 'BDT', 
        'sender_account' => '01911223344', 'reference' => 'Support package', 'gateway_trx_id' => 'BKASH66371192', 
        'method' => 'manual', 'status' => 'completed', 'metadata' => json_encode(['payment_link_id' => 2]), 'completed_at' => '2026-06-13 14:20:00'
    ],
    [
        'id' => 4, 'merchant_id' => 1, 'uuid' => 'tx-uuid-0000000000000000004', 'trx_id' => 'OP_TRX_55462811', 
        'payment_intent_id' => null, 'customer_id' => $c1, 
        'gateway_slug' => 'stripe', 'amount' => 1000.00, 'fee' => 29.00, 'net_amount' => 971.00, 'currency' => 'BDT', 
        'sender_account' => 'card_123', 'reference' => 'Invoice 4 stale', 'gateway_trx_id' => 'ch_stripe_5546', 
        'method' => 'api', 'status' => 'failed', 'metadata' => json_encode(['invoice_id' => 4]), 'completed_at' => null
    ],
    // Merchant 2 (USD - balanced ledger test)
    [
        'id' => 5, 'merchant_id' => 2, 'uuid' => 'tx-uuid-0000000000000000005', 'trx_id' => 'OP_TRX_USD_102', 
        'payment_intent_id' => $piMap['pi-token-0000000000000000005'] ?? null, 'customer_id' => $c3, 
        'gateway_slug' => 'stripe', 'amount' => 120.00, 'fee' => 3.80, 'net_amount' => 116.20, 'currency' => 'USD', 
        'sender_account' => 'card_9921', 'reference' => 'Pro Plugin key', 'gateway_trx_id' => 'ch_stripe_usd102', 
        'method' => 'api', 'status' => 'completed', 'metadata' => json_encode(['invoice_id' => 3, 'payment_link_id' => 3]), 'completed_at' => '2026-06-14 10:15:00'
    ],
    [
        'id' => 6, 'merchant_id' => 2, 'uuid' => 'tx-uuid-0000000000000000006', 'trx_id' => 'OP_TRX_USD_103', 
        'payment_intent_id' => $piMap['pi-token-0000000000000000006'] ?? null, 'customer_id' => $c3, 
        'gateway_slug' => 'stripe', 'amount' => 50.00, 'fee' => 1.80, 'net_amount' => 48.20, 'currency' => 'USD', 
        'sender_account' => 'card_8872', 'reference' => 'Link 3 Pro purchase', 'gateway_trx_id' => 'ch_stripe_usd103', 
        'method' => 'link', 'status' => 'completed', 'metadata' => json_encode(['payment_link_id' => 3]), 'completed_at' => '2026-06-14 11:30:00'
    ],
    [
        'id' => 7, 'merchant_id' => 2, 'uuid' => 'tx-uuid-0000000000000000007', 'trx_id' => 'OP_TRX_USD_104', 
        'payment_intent_id' => $piMap['pi-token-0000000000000000007'] ?? null, 'customer_id' => $c3, 
        'gateway_slug' => 'stripe', 'amount' => 85.00, 'fee' => 2.80, 'net_amount' => 82.20, 'currency' => 'USD', 
        'sender_account' => 'card_1122', 'reference' => 'Custom plugin tweak', 'gateway_trx_id' => 'ch_stripe_usd104', 
        'method' => 'api', 'status' => 'completed', 'metadata' => json_encode([]), 'completed_at' => '2026-06-15 09:12:00'
    ]
];

foreach ($transactionsToSeed as $t) {
    executeQuery($pdo, "INSERT INTO `op_transactions` 
        (`id`, `merchant_id`, `uuid`, `trx_id`, `payment_intent_id`, `customer_id`, `gateway_slug`, `amount`, `fee`, `net_amount`, `currency`, `sender_account`, `reference`, `gateway_trx_id`, `method`, `status`, `metadata`, `completed_at`)
        VALUES 
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $t['id'], $t['merchant_id'], $t['uuid'], $t['trx_id'], $t['payment_intent_id'], $t['customer_id'], 
            $t['gateway_slug'], $t['amount'], $t['fee'], $t['net_amount'], $t['currency'], $t['sender_account'], 
            $t['reference'], $t['gateway_trx_id'], $t['method'], $t['status'], $t['metadata'], $t['completed_at']
        ]);
}

// 18. Seed Refunds
echo "  -> Seeding refunds...\n";
// Refund transaction #1 (partial refund: BDT 500)
executeQuery($pdo, "INSERT INTO `op_refunds` (`id`, `merchant_id`, `transaction_id`, `uuid`, `amount`, `reason`, `status`, `processed_at`) 
    VALUES (1, 1, 1, 'ref-uuid-0000000000000000001', 500.00, 'Duplicate transaction charge', 'completed', '2026-06-13 10:15:00')");

// We update transaction #1 status to 'refunded' since it has a completed refund (or we can keep it as is, usually transaction status remains completed or refunded. Let's update status to refunded)
executeQuery($pdo, "UPDATE `op_transactions` SET `status` = 'refunded' WHERE `id` = 1");

// Refund transaction #3 (pending refund: BDT 1000)
executeQuery($pdo, "INSERT INTO `op_refunds` (`id`, `merchant_id`, `transaction_id`, `uuid`, `amount`, `reason`, `status`) 
    VALUES (2, 1, 3, 'ref-uuid-0000000000000000002', 1000.00, 'Customer dissatisfaction', 'pending')");

// Refund transaction #6 (completed USD refund: USD 10)
executeQuery($pdo, "INSERT INTO `op_refunds` (`id`, `merchant_id`, `transaction_id`, `uuid`, `amount`, `reason`, `status`, `processed_at`) 
    VALUES (3, 2, 6, 'ref-uuid-0000000000000000003', 10.00, 'Partial customer discount refund', 'completed', '2026-06-15 14:00:00')");

// 19. Seed Disputes
echo "  -> Seeding disputes...\n";
// Dispute on transaction #1
executeQuery($pdo, "INSERT INTO `op_disputes` (`id`, `merchant_id`, `transaction_id`, `reason`, `amount`, `status`, `created_at`) 
    VALUES (1, 1, 1, 'Customer claims card was stolen and unauthorized transaction.', 2500.00, 'under_review', '2026-06-14 09:30:00')");

// Dispute on transaction #7 (won USD dispute)
executeQuery($pdo, "INSERT INTO `op_disputes` (`id`, `merchant_id`, `transaction_id`, `reason`, `amount`, `status`, `resolved_at`, `created_at`) 
    VALUES (2, 2, 7, 'Product not matching specifications.', 85.00, 'won', '2026-06-16 11:20:00', '2026-06-15 15:45:00')");

// 20. Seed Ledger Accounts
echo "  -> Seeding ledger accounts...\n";
// Create ledger accounts for merchant_id = 1 (BDT)
executeQuery($pdo, "INSERT INTO `op_ledger_accounts` (`merchant_id`, `name`, `type`, `currency`, `balance`) VALUES 
    (1, 'CASH', 'asset', 'BDT', 0.00),
    (1, 'MERCHANT_PAYABLE', 'liability', 'BDT', 0.00),
    (1, 'PLATFORM_FEE_REVENUE', 'revenue', 'BDT', 0.00)");

// Create ledger accounts for merchant_id = 2 (USD)
executeQuery($pdo, "INSERT INTO `op_ledger_accounts` (`merchant_id`, `name`, `type`, `currency`, `balance`) VALUES 
    (2, 'CASH', 'asset', 'USD', 0.00),
    (2, 'MERCHANT_PAYABLE', 'liability', 'USD', 0.00),
    (2, 'PLATFORM_FEE_REVENUE', 'revenue', 'USD', 0.00)");

// 21. Helper functions to seed ledger entries and keep them balanced (GAAP compliant)
// We dynamically generate entries based on transaction status

// Seeding Completed Payments
echo "  -> Posting payment transactions to ledger...\n";
$completedTxns = $pdo->query("SELECT id, merchant_id, amount, fee, currency, trx_id, created_at FROM `op_transactions` WHERE status IN ('completed', 'refunded', 'disputed')")->fetchAll(\PDO::FETCH_ASSOC);

foreach ($completedTxns as $t) {
    $txnId = (int)$t['id'];
    $mid = (int)$t['merchant_id'];
    $amt = (float)$t['amount'];
    $fee = (float)$t['fee'];
    $cur = $t['currency'];
    $trxId = $t['trx_id'];
    $createdAt = $t['created_at'];

    postLedgerForTransaction($pdo, $txnId, $mid, $amt, $fee, $cur, $trxId, $createdAt);
}

// Seeding Completed Refunds
echo "  -> Posting completed refunds to ledger...\n";
$completedRefunds = $pdo->query("SELECT id, transaction_id, merchant_id, amount, created_at FROM `op_refunds` WHERE status = 'completed'")->fetchAll(\PDO::FETCH_ASSOC);

foreach ($completedRefunds as $r) {
    $refId = (int)$r['id'];
    $txnId = (int)$r['transaction_id'];
    $mid = (int)$r['merchant_id'];
    $refAmt = (float)$r['amount'];
    $createdAt = $r['created_at'];

    // Get currency from transaction
    $cur = $pdo->query("SELECT currency FROM `op_transactions` WHERE id = {$txnId} LIMIT 1")->fetchColumn() ?: 'BDT';

    postLedgerForRefund($pdo, $refId, $txnId, $mid, $refAmt, $cur, $createdAt);
}

// Dynamically compute and update the balances on all accounts
echo "  -> Updating ledger account balances...\n";
updateLedgerAccountBalances($pdo, 1);
updateLedgerAccountBalances($pdo, 2);

// Let's deliberately introduce a variance in Merchant 2's USD ledger so the user can test the 'unbalanced' state
// We adjust MERCHANT_PAYABLE balance manually by +10.00 USD
echo "  -> (Testing Setup) Creating deliberate USD variance for Merchant 2...\n";
$pdo->exec("UPDATE `op_ledger_accounts` SET `balance` = `balance` + 10.0000 WHERE `merchant_id` = 2 AND `name` = 'MERCHANT_PAYABLE' AND `currency` = 'USD'");


// 22. Seed Devices
echo "  -> Seeding paired mobile devices...\n";
executeQuery($pdo, "INSERT INTO `op_paired_devices` (`merchant_id`, `device_id`, `device_name`, `platform`, `jwt_fingerprint`, `aes_key_encrypted`, `status`) VALUES 
    (1, 'dev-uuid-0000000000000000001', 'Xiaomi Redmi Note 12', 'android', 'fingerprint123', 'aeskey123', 'active'),
    (1, 'dev-uuid-0000000000000000002', 'Samsung Galaxy A54', 'android', 'fingerprint456', 'aeskey456', 'active')");

// 23. Seed SMS Parsed
echo "  -> Seeding parsed SMS logs...\n";
$device1Uuid = 'dev-uuid-0000000000000000001';
executeQuery($pdo, "INSERT INTO `op_sms_parsed` (`merchant_id`, `device_id`, `sender`, `body`, `amount`, `trx_id`, `gateway_slug`, `match_status`, `received_at`) VALUES 
    (1, '{$device1Uuid}', 'bKash', 'You have received Tk 2,500.00 from 01712345678. Ref 123. TrxID BKASH99283711', 2500.00, 'BKASH99283711', 'bkash-personal', 'matched', '2026-06-12 11:30:00'),
    (1, '{$device1Uuid}', 'Nagad', 'Nagad Payment received Tk 1200.00 Ref Setup. TxnID NAGAD88291022', 1200.00, 'NAGAD88291022', 'nagad-personal', 'matched', '2026-06-12 12:00:00')");

// 24. Seed Audit Logs
echo "  -> Seeding audit logs...\n";
executeQuery($pdo, "INSERT INTO `op_audit_logs` (`merchant_id`, `user_id`, `action`, `entity_type`, `entity_id`, `ip_address`, `user_agent`, `signature`) VALUES 
    (1, 1, 'gateway.update', 'GatewayConfig', 1, '127.0.0.1', 'Mozilla/5.0', 'sig_dummy_1'),
    (1, 2, 'invoice.create', 'Invoice', 1, '127.0.0.1', 'Mozilla/5.0', 'sig_dummy_2'),
    (1, 1, 'merchant.update_settings', 'Merchant', 1, '127.0.0.1', 'Mozilla/5.0', 'sig_dummy_3')");

// 25. Seed Webhooks & Webhook Deliveries
echo "  -> Seeding webhooks & webhook delivery logs...\n";
executeQuery($pdo, "INSERT INTO `op_webhooks` (`id`, `merchant_id`, `url`, `secret`, `events`, `status`) 
    VALUES (1, 1, 'https://api.myshop.com/webhooks/ownpay', 'whsec_9928371192', '[\"payment.completed\", \"payment.failed\"]', 'active')");

executeQuery($pdo, "INSERT INTO `op_webhook_events` (`id`, `webhook_id`, `event_type`, `payload`, `status`, `attempts`) VALUES 
    (1, 1, 'payment.completed', '{\"trx_id\":\"OP_TRX_99283711\",\"amount\":2500.00}', 'delivered', 1),
    (2, 1, 'payment.failed', '{\"trx_id\":\"OP_TRX_55462811\",\"amount\":1000.00}', 'failed', 3)");

executeQuery($pdo, "INSERT INTO `op_webhook_delivery_logs` (`webhook_event_id`, `response_code`, `response_body`, `duration_ms`, `error`) VALUES 
    (1, 200, 'Webhook received successfully', 150, NULL),
    (2, 500, 'Internal Server Error on receiver endpoint', 320, 'Connection timed out')");

executeQuery($pdo, "INSERT INTO `op_webhook_deliveries` (`merchant_id`, `gateway`, `event`, `url`, `direction`, `status_code`, `response_time_ms`, `attempt`, `status`, `payload_hash`) VALUES 
    (1, 'system', 'payment.completed', 'https://api.myshop.com/webhooks/ownpay', 'outbound', 200, 152, 1, 'delivered', 'hash_dummy_9921'),
    (1, 'system', 'payment.failed', 'https://api.myshop.com/webhooks/ownpay', 'outbound', 500, 310, 3, 'failed', 'hash_dummy_8821')");

echo "\n\033[32m[SUCCESS] OwnPay local database seeded successfully! Ready for comprehensive manual & unit testing.\033[0m\n";

// Helper function to post ledger entries for completed transactions
function postLedgerForTransaction(\PDO $pdo, int $txnId, int $merchantId, float $amount, float $fee, string $currency, string $trxId, string $createdAt): void {
    $net = $amount - $fee;
    
    $getAccount = function(string $name, string $curr) use ($pdo, $merchantId): int {
        $stmt = $pdo->prepare("SELECT id FROM `op_ledger_accounts` WHERE `merchant_id` = ? AND `name` = ? AND `currency` = ? LIMIT 1");
        $stmt->execute([$merchantId, $name, $curr]);
        return (int)$stmt->fetchColumn();
    };

    $cashAcc = $getAccount('CASH', $currency);
    $payableAcc = $getAccount('MERCHANT_PAYABLE', $currency);
    $feeAcc = $getAccount('PLATFORM_FEE_REVENUE', $currency);

    if ($cashAcc && $payableAcc && $feeAcc) {
        $uuid = makeUuid();
        $desc = "Payment received (fee: " . number_format($fee, 2) . ") for txn " . $trxId;
        
        executeQuery($pdo, "INSERT INTO `op_ledger_transactions` (`merchant_id`, `uuid`, `description`, `reference_type`, `reference_id`, `created_at`) 
            VALUES (?, ?, ?, 'transaction', ?, ?)", [$merchantId, $uuid, $desc, $txnId, $createdAt]);
        
        $ltId = $pdo->lastInsertId();
        if ($ltId) {
            // Debit CASH (Asset +)
            executeQuery($pdo, "INSERT INTO `op_ledger_entries` (`ledger_transaction_id`, `account_id`, `type`, `amount`, `created_at`) VALUES (?, ?, 'debit', ?, ?)", [$ltId, $cashAcc, $amount, $createdAt]);
            // Credit MERCHANT_PAYABLE (Liability +)
            executeQuery($pdo, "INSERT INTO `op_ledger_entries` (`ledger_transaction_id`, `account_id`, `type`, `amount`, `created_at`) VALUES (?, ?, 'credit', ?, ?)", [$ltId, $payableAcc, $net, $createdAt]);
            // Credit PLATFORM_FEE_REVENUE (Revenue +)
            executeQuery($pdo, "INSERT INTO `op_ledger_entries` (`ledger_transaction_id`, `account_id`, `type`, `amount`, `created_at`) VALUES (?, ?, 'credit', ?, ?)", [$ltId, $feeAcc, $fee, $createdAt]);
        }
    }
}

// Helper function to post ledger entries for completed refunds
function postLedgerForRefund(\PDO $pdo, int $refundId, int $txnId, int $merchantId, float $refundAmount, string $currency, string $createdAt): void {
    $stmt = $pdo->prepare("SELECT amount, fee FROM `op_transactions` WHERE id = ? LIMIT 1");
    $stmt->execute([$txnId]);
    $txn = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$txn) return;
    
    $origGross = (float)$txn['amount'];
    $origFee = (float)$txn['fee'];
    
    if ($origGross > 0) {
        $ratio = $origFee / $origGross;
        $refundFee = $refundAmount * $ratio;
    } else {
        $refundFee = 0.0;
    }
    
    $refundNet = $refundAmount - $refundFee;
    
    $getAccount = function(string $name, string $curr) use ($pdo, $merchantId): int {
        $stmt = $pdo->prepare("SELECT id FROM `op_ledger_accounts` WHERE `merchant_id` = ? AND `name` = ? AND `currency` = ? LIMIT 1");
        $stmt->execute([$merchantId, $name, $curr]);
        return (int)$stmt->fetchColumn();
    };

    $cashAcc = $getAccount('CASH', $currency);
    $payableAcc = $getAccount('MERCHANT_PAYABLE', $currency);
    $feeAcc = $getAccount('PLATFORM_FEE_REVENUE', $currency);

    if ($cashAcc && $payableAcc && $feeAcc) {
        $uuid = makeUuid();
        $desc = "Refund issued for txn #" . $txnId;
        
        executeQuery($pdo, "INSERT INTO `op_ledger_transactions` (`merchant_id`, `uuid`, `description`, `reference_type`, `reference_id`, `created_at`) 
            VALUES (?, ?, ?, 'refund', ?, ?)", [$merchantId, $uuid, $desc, $refundId, $createdAt]);
        
        $ltId = $pdo->lastInsertId();
        if ($ltId) {
            // Credit CASH (Asset -)
            executeQuery($pdo, "INSERT INTO `op_ledger_entries` (`ledger_transaction_id`, `account_id`, `type`, `amount`, `created_at`) VALUES (?, ?, 'credit', ?, ?)", [$ltId, $cashAcc, $refundAmount, $createdAt]);
            // Debit MERCHANT_PAYABLE (Liability -)
            executeQuery($pdo, "INSERT INTO `op_ledger_entries` (`ledger_transaction_id`, `account_id`, `type`, `amount`, `created_at`) VALUES (?, ?, 'debit', ?, ?)", [$ltId, $payableAcc, $refundNet, $createdAt]);
            // Debit PLATFORM_FEE_REVENUE (Revenue -)
            executeQuery($pdo, "INSERT INTO `op_ledger_entries` (`ledger_transaction_id`, `account_id`, `type`, `amount`, `created_at`) VALUES (?, ?, 'debit', ?, ?)", [$ltId, $feeAcc, $refundFee, $createdAt]);
        }
    }
}

// Helper function to update account balance totals based on entry entries
function updateLedgerAccountBalances(\PDO $pdo, int $merchantId): void {
    $stmt = $pdo->prepare("SELECT id, type FROM `op_ledger_accounts` WHERE `merchant_id` = ?");
    $stmt->execute([$merchantId]);
    $accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    foreach ($accounts as $acc) {
        $accId = (int)$acc['id'];
        $type = $acc['type'];
        
        $stmtDeb = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM `op_ledger_entries` WHERE `account_id` = ? AND `type` = 'debit'");
        $stmtDeb->execute([$accId]);
        $debits = (float)$stmtDeb->fetchColumn();
        
        $stmtCred = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM `op_ledger_entries` WHERE `account_id` = ? AND `type` = 'credit'");
        $stmtCred->execute([$accId]);
        $credits = (float)$stmtCred->fetchColumn();
        
        if ($type === 'asset' || $type === 'expense') {
            $balance = $debits - $credits;
        } else {
            $balance = $credits - $debits;
        }
        
        executeQuery($pdo, "UPDATE `op_ledger_accounts` SET `balance` = ? WHERE `id` = ?", [$balance, $accId]);
    }
}
