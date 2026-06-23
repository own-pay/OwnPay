<?php
declare(strict_types=1);

/**
 * OwnPay Database CLI Seeder
 * Populates all database tables with 500+ logical, interconnected dummy records.
 */

// 1. Boot Application Kernel and Container
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
$kernel = new \OwnPay\Kernel();

// Boot the kernel and get the container
$reflection = new \ReflectionClass($kernel);
$bootMethod = $reflection->getMethod('boot');
$bootMethod->setAccessible(true);
$bootMethod->invoke($kernel);

$container = $kernel->getContainer();
$pdo = $container->get(\PDO::class);
$encryptor = $container->get(\OwnPay\Security\FieldEncryptor::class);
$ledgerService = $container->get(\OwnPay\Service\Payment\LedgerService::class);

echo "\033[36m[OwnPay Seeder] Seeding database with 500+ records...\033[0m\n";

// Disable foreign key checks for clean truncation
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

$tablesToTruncate = [
    'op_audit_logs',
    'op_login_attempts',
    'op_ledger_entries',
    'op_ledger_transactions',
    'op_ledger_accounts',
    'op_sms_parsed',
    'op_mobile_notifications',
    'op_device_pairing_tokens',
    'op_paired_devices',
    'op_webhook_delivery_logs',
    'op_webhook_events',
    'op_webhook_deliveries',
    'op_webhooks',
    'op_disputes',
    'op_refunds',
    'op_transactions',
    'op_idempotency_keys',
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
    'op_currencies',
    'op_exchange_rates',
    'op_system_settings',
    'op_role_permissions',
    'op_permissions',
    'op_brand_plugins',
    'op_plugin_migrations',
    'op_plugin_settings',
    'op_plugins',
    'op_rate_limits',
    'op_sessions',
    'op_queue_jobs',
    'op_job_queue',
    'op_cache',
    'op_languages',
    'op_comm_log',
    'op_update_history',
    'op_maintenance_locks',
    'op_migrations'
];

foreach ($tablesToTruncate as $table) {
    echo "  -> Truncating {$table}...\n";
    $pdo->exec("TRUNCATE TABLE `{$table}`");
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

// 2. Seed Permissions
echo "  -> Seeding default permissions...\n";
$permissionsSql = @file_get_contents(__DIR__ . '/roles.sql');
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

// 3. Seed Currencies
echo "  -> Seeding currencies...\n";
$currenciesSql = @file_get_contents(__DIR__ . '/currencies.sql');
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

// 4. Seed Exchange Rates
echo "  -> Seeding exchange rates...\n";
executeQuery($pdo, "INSERT IGNORE INTO `op_exchange_rates` (`base_currency`, `target_currency`, `rate`, `source`) VALUES 
    ('USD', 'BDT', 117.50, 'manual'),
    ('EUR', 'BDT', 126.20, 'manual'),
    ('BDT', 'USD', 0.0085, 'manual'),
    ('GBP', 'BDT', 148.90, 'manual')");

// 5. Seed System Settings
echo "  -> Seeding system settings...\n";
$settingsSql = @file_get_contents(__DIR__ . '/system_settings.sql');
if ($settingsSql) {
    $cleanSql = preg_replace('/--.*$/m', '', $settingsSql);
    $cleanSql = preg_replace('/#.*$/m', '', $cleanSql);
    $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt) && str_starts_with(strtoupper($stmt), 'INSERT')) {
            $pdo->exec($stmt);
        }
    }
}

// 6. Seed SMS Templates
echo "  -> Seeding SMS templates...\n";
$smsTemplatesSql = @file_get_contents(__DIR__ . '/sms_templates.sql');
if ($smsTemplatesSql) {
    $cleanSql = preg_replace('/--.*$/m', '', $smsTemplatesSql);
    $cleanSql = preg_replace('/#.*$/m', '', $cleanSql);
    $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt) && str_starts_with(strtoupper($stmt), 'INSERT')) {
            $pdo->exec($stmt);
        }
    }
}

// 7. Seed Merchants/Brands
echo "  -> Seeding merchants (brands)...\n";
$merchants = [
    [1, 'default-brand-uuid-00000000001', 'OwnPay Store', 'ownpay-store', 'store@ownpay.test', '01700000001', 'Asia/Dhaka', 'BDT', 'active'],
    [2, 'bata-brand-uuid-0000000000002', 'Bata Shop', 'bata-shop', 'info@bata.test', '01700000002', 'Asia/Dhaka', 'BDT', 'active'],
    [3, 'apex-brand-uuid-0000000000003', 'Apex Shop', 'apex-shop', 'info@apex.test', '01700000003', 'Asia/Dhaka', 'BDT', 'active']
];
foreach ($merchants as $m) {
    executeQuery($pdo, "INSERT INTO `op_merchants` (`id`, `uuid`, `name`, `slug`, `email`, `phone`, `timezone`, `default_currency`, `status`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", $m);
}

// 8. Seed Roles & Wire Permissions
echo "  -> Seeding roles & wiring permissions...\n";
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

// 9. Seed Users (Argon2id passwords)
echo "  -> Seeding admin & staff users...\n";
$users = [
    [1, 'superadmin', 'Rahim Ahmed', 'rahim', 'rahim@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01711223344', 1],
    [1, 'owner', 'Alex Admin', 'admin', 'admin@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01700000000', 0],
    [1, 'billing', 'Tahmid Rahman', 'tahmid', 'tahmid@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01911223344', 0],
    [1, 'support', 'Sabina Yeasmin', 'sabina', 'sabina@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01811223344', 0],
    [2, 'owner', 'Javed Karim', 'javed', 'javed@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01511223344', 0],
    [3, 'owner', 'Apex Dev', 'apexdev', 'apex@example.com', password_hash('admin123', PASSWORD_ARGON2ID), '01311223344', 0]
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

// 10. Seed Custom Domains
echo "  -> Seeding domains...\n";
executeQuery($pdo, "INSERT INTO `op_domains` (`merchant_id`, `domain`, `type`, `dns_verified`, `ssl_status`, `is_primary`, `status`) VALUES 
    (1, 'checkout.ownpay.test', 'checkout', 1, 'active', 1, 'active'),
    (2, 'checkout.bata.test', 'checkout', 1, 'active', 1, 'active'),
    (3, 'checkout.apex.test', 'checkout', 1, 'active', 1, 'active')");

// 11. Seed Builtin Gateways
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

// 12. Seed Gateway Configs
$stripeGwId = $pdo->query("SELECT id FROM `op_gateways` WHERE `slug` = 'stripe'")->fetchColumn();
$bkashGwId = $pdo->query("SELECT id FROM `op_gateways` WHERE `slug` = 'bkash-api'")->fetchColumn();
$sslGwId = $pdo->query("SELECT id FROM `op_gateways` WHERE `slug` = 'sslcommerz'")->fetchColumn();
$nagadGwId = $pdo->query("SELECT id FROM `op_gateways` WHERE `slug` = 'nagad-api'")->fetchColumn();

if ($stripeGwId && $bkashGwId && $sslGwId && $nagadGwId) {
    foreach ([1, 2, 3] as $mid) {
        executeQuery($pdo, "INSERT INTO `op_gateway_configs` (`merchant_id`, `gateway_id`, `credentials_enc`, `settings`, `mode`, `status`) VALUES 
            ({$mid}, {$stripeGwId}, 'enc_dummy_stripe_credentials', '{\"publishable_key\":\"pk_test_123\",\"secret_key\":\"sk_test_123\"}', 'sandbox', 'active'),
            ({$mid}, {$bkashGwId}, 'enc_dummy_bkash_credentials', '{\"app_key\":\"bk_test_123\",\"app_secret\":\"bk_sec_123\",\"username\":\"bk_usr\",\"password\":\"bk_pw\"}', 'sandbox', 'active'),
            ({$mid}, {$sslGwId}, 'enc_dummy_ssl_credentials', '{\"store_id\":\"ssl_test\",\"store_password\":\"ssl_pass\"}', 'sandbox', 'active'),
            ({$mid}, {$nagadGwId}, 'enc_dummy_nagad_credentials', '{\"merchant_id\":\"nagad_test\",\"public_key\":\"nagad_pub\",\"private_key\":\"nagad_priv\"}', 'sandbox', 'active')");
    }
}

// 13. Seed Manual Gateways
echo "  -> Seeding manual gateways...\n";
foreach ([1, 2, 3] as $mid) {
    executeQuery($pdo, "INSERT INTO `op_manual_gateways` 
        (`merchant_id`, `slug`, `name`, `logo_path`, `instructions`, `sms_verification`, `sms_sender_pattern`, `sms_regex_template`, `currency`, `min_amount`, `max_amount`, `status`)
        VALUES 
        (?, 'bkash-personal', 'bKash Personal', 'uploads/bkash.png', '\"Send money to 01700000000. Use your Transaction ID below for instant match verification.\"', 1, 'bKash', '\"You have received Tk ([0-9,]+\\\\.[0-9]{2})\\\\. From ([0-9]+)\\\\. Ref.*TrxID (\\\\w+)\"', 'BDT', 10.00, 25000.00, 'active'),
        (?, 'nagad-personal', 'Nagad Personal', 'uploads/nagad.png', '\"Send money to 01900000000. Fill up the transaction hash code after sending.\"', 1, 'Nagad', '\"Nagad received BDT ([0-9,]+\\\\.[0-9]{2}) from ([0-9]+)\\\\. TxnID (\\\\w+)\"', 'BDT', 5.00, 20000.00, 'active'),
        (?, 'rocket-personal', 'Rocket Personal', 'uploads/rocket.png', '\"Send money to 01800000000. Fill up the transaction hash code after sending.\"', 1, 'Rocket', '\"Rocket received BDT ([0-9,]+\\\\.[0-9]{2}) from ([0-9]+)\\\\. TxnID (\\\\w+)\"', 'BDT', 5.00, 20000.00, 'active')",
        [$mid, $mid, $mid]
    );
}

// 14. Seed Ledger Accounts
echo "  -> Seeding ledger accounts...\n";
foreach ([1, 2, 3] as $mid) {
    foreach (['BDT', 'USD'] as $cur) {
        executeQuery($pdo, "INSERT INTO `op_ledger_accounts` (`merchant_id`, `name`, `type`, `currency`, `balance`) VALUES 
            (?, 'CASH', 'asset', ?, 0.00),
            (?, 'MERCHANT_PAYABLE', 'liability', ?, 0.00),
            (?, 'PLATFORM_FEE_REVENUE', 'revenue', ?, 0.00)",
            [$mid, $cur, $mid, $cur, $mid, $cur]
        );
    }
}

// 15. Seed Languages (en, bn)
echo "  -> Seeding languages...\n";
$enTranslationsJson = file_get_contents(dirname(__DIR__, 2) . '/config/languages/en.json');
if ($enTranslationsJson) {
    executeQuery($pdo, "INSERT INTO `op_languages` (`code`, `name`, `status`, `is_default`, `translations`) VALUES ('en', 'English', 'active', 1, ?)", [$enTranslationsJson]);
    
    $bnTranslations = json_decode($enTranslationsJson, true);
    $bnTranslations['login'] = 'লগইন';
    $bnTranslations['email'] = 'ইমেইল';
    $bnTranslations['password'] = 'পাসওয়ার্ড';
    $bnTranslationsJson = json_encode($bnTranslations, JSON_UNESCAPED_UNICODE);
    executeQuery($pdo, "INSERT INTO `op_languages` (`code`, `name`, `status`, `is_default`, `translations`) VALUES ('bn', 'Bengali', 'active', 0, ?)", [$bnTranslationsJson]);
}

// 16. Seed Plugins definitions
echo "  -> Seeding plugin registry...\n";
$pluginsToSeed = [
    ['mail-gateway', 'Mail Gateway Addon', 'addon', '1.0.0', 'MailGatewayAddon.php', '{"email": true}', '{"name": "Mail Gateway"}'],
    ['sms-gateway', 'SMS Gateway Addon', 'addon', '1.0.0', 'SmsGatewayAddon.php', '{"sms": true}', '{"name": "SMS Gateway"}'],
    ['telegram-bot', 'Telegram Bot Addon', 'addon', '1.0.0', 'TelegramBotAddon.php', '{"telegram": true}', '{"name": "Telegram Bot"}'],
    ['own-pay', 'OwnPay Theme', 'theme', '1.0.0', 'OwnPayTheme.php', '{"theme": true}', '{"name": "OwnPay Theme"}'],
    ['stripe', 'Stripe Plugin', 'gateway', '1.0.0', 'StripeGateway.php', '{"payment": true}', '{"name": "Stripe Plugin"}'],
    ['bkash-api', 'bKash API Plugin', 'gateway', '1.0.0', 'BkashGateway.php', '{"payment": true}', '{"name": "bKash API Plugin"}']
];
foreach ($pluginsToSeed as $pl) {
    executeQuery($pdo, "INSERT INTO `op_plugins` (`slug`, `name`, `type`, `version`, `entrypoint`, `capabilities`, `manifest`, `status`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active')", $pl);
}

// Active plugins for merchants
foreach ([1, 2, 3] as $mid) {
    foreach (['mail-gateway', 'sms-gateway', 'telegram-bot', 'own-pay'] as $pslug) {
        executeQuery($pdo, "INSERT INTO `op_brand_plugins` (`merchant_id`, `plugin_slug`, `status`) VALUES (?, ?, 'active')", [$mid, $pslug]);
    }
}

// Seed plugin settings & migrations
foreach (['mail-gateway', 'sms-gateway', 'telegram-bot'] as $pslug) {
    executeQuery($pdo, "INSERT INTO `op_plugin_settings` (`plugin_slug`, `key_name`, `value`) VALUES (?, 'enabled', '1')", [$pslug]);
    executeQuery($pdo, "INSERT INTO `op_plugin_migrations` (`plugin_slug`, `migration`, `batch`) VALUES (?, '001_init.sql', 1)", [$pslug]);
}

// 17. Seed Payment Links & Fields
echo "  -> Seeding payment links...\n";
$paymentLinks = [
    [1, 1, 'pl-uuid-0000000000000000001', 'setup-fee', 'Software Setup Fee', 'One-time platform setup fee', 10000.00, 'BDT', 1],
    [2, 1, 'pl-uuid-0000000000000000002', 'donation', 'Support Donation', 'Support development of OwnPay open source', NULL, 'BDT', 0],
    [3, 2, 'pl-uuid-0000000000000000003', 'bata-promo', 'Bata Shoes Promo', 'Promotional discounted checkout', 1500.00, 'BDT', 1],
    [4, 3, 'pl-uuid-0000000000000000004', 'apex-sneaker', 'Apex Premium Sneaker', 'Apex limited edition sneaker', 4500.00, 'BDT', 1]
];
foreach ($paymentLinks as $pl) {
    executeQuery($pdo, "INSERT INTO `op_payment_links` (`id`, `merchant_id`, `uuid`, `slug`, `title`, `description`, `amount`, `currency`, `is_amount_fixed`, `status`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')", $pl);
}

// Fields for links
foreach ([1, 2, 3, 4] as $lid) {
    executeQuery($pdo, "INSERT INTO `op_payment_link_fields` (`payment_link_id`, `label`, `type`, `is_required`, `sort_order`) VALUES 
        (?, 'Additional Notes', 'text', 0, 1),
        (?, 'Size/Color Preference', 'text', 0, 2)", [$lid, $lid]);
}

// 18. Seed Fee Rules
echo "  -> Seeding fee rules...\n";
foreach ([1, 2, 3] as $mid) {
    executeQuery($pdo, "INSERT INTO `op_fee_rules` (`merchant_id`, `gateway_slug`, `type`, `value`, `min_fee`, `max_fee`, `currency`, `status`) VALUES 
        (?, 'stripe', 'percentage', 2.9000, 0.30, NULL, 'BDT', 'active'),
        (?, 'bkash-api', 'percentage', 1.8500, NULL, NULL, 'BDT', 'active'),
        (?, 'bkash-personal', 'percentage', 1.5000, NULL, NULL, 'BDT', 'active'),
        (?, 'nagad-personal', 'percentage', 1.4500, NULL, NULL, 'BDT', 'active')",
        [$mid, $mid, $mid, $mid]
    );
}

// 19. Seed 500+ Customers, Payment Intents, Transactions, Invoices, Ledger entries
echo "  -> Seeding 500+ primary records (Customers, Intents, Transactions, Ledger Entries, Invoices)...\n";

$firstNames = ['Rahim', 'Karim', 'Tahmid', 'Sabina', 'Javed', 'John', 'Alice', 'Bob', 'Charlie', 'David', 'Eva', 'Frank', 'Grace', 'Henry', 'Ivy', 'Jack', 'Kamal', 'Farhana', 'Nabil', 'Abir', 'Shakil', 'Hasan', 'Tariq', 'Mitu', 'Sajib'];
$lastNames = ['Ahmed', 'Rahman', 'Saha', 'Yesmin', 'Karim', 'Doe', 'Smith', 'Jones', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson'];
$domains = ['example.com', 'test.org', 'mail.net', 'company.com', 'gmail.com', 'yahoo.com', 'outlook.com'];

$customerIds = [];
$intentIds = [];
$transactionIds = [];

// Seed exactly 520 customers, intents, and transactions to exceed the 500 requirement
$totalCount = 520;
$merchantIds = [1, 2, 3];
$gatewaySlugs = ['stripe', 'bkash-api', 'bkash-personal', 'nagad-personal', 'rocket-personal'];
$statuses = ['completed', 'completed', 'completed', 'completed', 'completed', 'pending', 'failed', 'refunded', 'disputed', 'awaiting_verification', 'pending_review'];

for ($i = 1; $i <= $totalCount; $i++) {
    // Generate logical randomized data
    $merchantId = $merchantIds[$i % 3];
    $firstName = $firstNames[array_rand($firstNames)];
    $lastName = $lastNames[array_rand($lastNames)];
    $name = $firstName . ' ' . $lastName;
    $email = strtolower($firstName . '.' . $lastName . '.' . $i . '@' . $domains[array_rand($domains)]);
    $phone = '01' . mt_rand(3, 9) . mt_rand(10000000, 99999999);
    
    // Encrypt fields
    $nameEnc = $encryptor->encrypt($name);
    $emailEnc = $encryptor->encrypt($email);
    $emailHash = $encryptor->deterministicHash($email);
    $phoneEnc = $encryptor->encrypt($phone);
    $phoneHash = $encryptor->deterministicHash($phone);
    
    $uuid = makeUuid();
    
    // 19.1 Customers
    executeQuery($pdo, "INSERT INTO `op_customers` (`id`, `merchant_id`, `uuid`, `name_enc`, `email_enc`, `email_hash`, `phone_enc`, `phone_hash`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
            $i, $merchantId, $uuid, $nameEnc, $emailEnc, $emailHash, $phoneEnc, $phoneHash
        ]);
    
    // 19.2 Payment Intents
    $intentUuid = makeUuid();
    $intentToken = 'pi-token-' . sprintf('%05d', $i) . '-' . mt_rand(100000, 999999);
    $amount = (float) mt_rand(50, 15000);
    $currency = ($i % 12 === 0) ? 'USD' : 'BDT';
    $trxStatus = $statuses[array_rand($statuses)];
    if (in_array($trxStatus, ['completed', 'refunded', 'disputed'])) {
        $intentStatus = 'completed';
    } elseif ($trxStatus === 'failed') {
        $intentStatus = 'failed';
    } elseif ($trxStatus === 'expired') {
        $intentStatus = 'expired';
    } elseif ($trxStatus === 'cancelled') {
        $intentStatus = 'cancelled';
    } else {
        $intentStatus = 'pending';
    }
    
    $createdAt = date('Y-m-d H:i:s', time() - mt_rand(10, 300) * 3600); // Distributed over the last 12 days
    $expiresAt = date('Y-m-d H:i:s', strtotime($createdAt) + 3600);
    
    executeQuery($pdo, "INSERT INTO `op_payment_intents` (`id`, `merchant_id`, `uuid`, `token`, `customer_id`, `amount`, `currency`, `status`, `expires_at`, `created_at`, `updated_at`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $i, $merchantId, $intentUuid, $intentToken, $i, $amount, $currency, $intentStatus, $expiresAt, $createdAt, $createdAt
        ]);
    
    // 19.3 Transactions (only if status is completed, refunded, disputed, failed, awaiting_verification, pending_review)
    if ($trxStatus !== 'pending' && $trxStatus !== 'expired') {
        $trxUuid = makeUuid();
        $trxId = 'OP_TRX_' . sprintf('%08d', $i);
        $gatewaySlug = $gatewaySlugs[array_rand($gatewaySlugs)];
        
        // Compute fee rules
        $feeRate = 0.02; // Default 2%
        if ($gatewaySlug === 'stripe') $feeRate = 0.029;
        elseif ($gatewaySlug === 'bkash-api') $feeRate = 0.0185;
        elseif ($gatewaySlug === 'bkash-personal') $feeRate = 0.015;
        elseif ($gatewaySlug === 'nagad-personal') $feeRate = 0.0145;
        
        $fee = round($amount * $feeRate, 2);
        $net = $amount - $fee;
        
        $gatewayTrxId = strtoupper($gatewaySlug) . sprintf('%08d', $i);
        $providerTrxId = 'PROV_' . sprintf('%08d', $i);
        
        $method = 'api';
        if (str_contains($gatewaySlug, 'personal')) $method = 'manual';
        
        $metadata = json_encode(['payment_link_id' => ($i % 4) + 1, 'invoice_id' => $i]);
        $completedAt = ($trxStatus === 'completed' || $trxStatus === 'refunded' || $trxStatus === 'disputed') ? $createdAt : null;
        
        executeQuery($pdo, "INSERT INTO `op_transactions` 
            (`id`, `merchant_id`, `uuid`, `trx_id`, `payment_intent_id`, `customer_id`, `gateway_slug`, `amount`, `fee`, `net_amount`, `currency`, `sender_account`, `reference`, `gateway_trx_id`, `provider_trx_id`, `method`, `status`, `metadata`, `completed_at`, `created_at`, `updated_at`)
            VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $i, $merchantId, $trxUuid, $trxId, $i, $i, $gatewaySlug, $amount, $fee, $net, $currency, $phone, 'Ref ' . $i, $gatewayTrxId, $providerTrxId, $method, $trxStatus, $metadata, $completedAt, $createdAt, $createdAt
            ]);
            
        // 19.4 Seed Invoices & Invoice Items (approx 150 invoices)
        if ($i % 3 === 0) {
            $invoiceUuid = makeUuid();
            $invoiceToken = 'inv-token-' . sprintf('%05d', $i) . '-' . mt_rand(100000, 999999);
            $invoiceNumber = 'INV-' . date('Y', strtotime($createdAt)) . '-' . sprintf('%04d', $i);
            $invoiceStatus = ($trxStatus === 'completed' || $trxStatus === 'refunded') ? 'paid' : (($trxStatus === 'failed') ? 'overdue' : 'sent');
            $paidAt = ($invoiceStatus === 'paid') ? $createdAt : null;
            
            executeQuery($pdo, "INSERT INTO `op_invoices` (`id`, `merchant_id`, `uuid`, `token`, `invoice_number`, `customer_id`, `subtotal`, `tax`, `discount`, `total`, `currency`, `notes`, `due_date`, `status`, `paid_at`, `created_at`, `updated_at`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, 0.00, ?, ?, 'Seed Invoice notes', ?, ?, ?, ?, ?)", [
                    $i, $merchantId, $invoiceUuid, $invoiceToken, $invoiceNumber, $i, $amount, $amount, $currency, date('Y-m-d', strtotime($createdAt) + 15 * 86400), $invoiceStatus, $paidAt, $createdAt, $createdAt
                ]);
            
            executeQuery($pdo, "INSERT INTO `op_invoice_items` (`invoice_id`, `description`, `quantity`, `unit_price`, `total`, `sort_order`) VALUES 
                (?, 'Services rendered item A', 1.00, ?, ?, 1)", [$i, $amount, $amount]);
        }

        // 19.5 Post Ledger Entries for completed payments using LedgerService to guarantee GAAP balancing
        if ($completedAt !== null) {
            try {
                $ledgerService->recordPaymentReceived($merchantId, $i, (string)$amount, (string)$fee, $currency);
                
                // Align date of generated ledger transaction and entries
                $ltId = $pdo->lastInsertId();
                if ($ltId) {
                    executeQuery($pdo, "UPDATE `op_ledger_transactions` SET `created_at` = ? WHERE `id` = ?", [$completedAt, $ltId]);
                    executeQuery($pdo, "UPDATE `op_ledger_entries` SET `created_at` = ? WHERE `ledger_transaction_id` = ?", [$completedAt, $ltId]);
                }
            } catch (\Throwable $e) {
                echo "Ledger post failed for txn {$i}: " . $e->getMessage() . "\n";
            }
        }
    }
}

// 20. Seed Refunds (approx 60 refunds, some completed)
echo "  -> Seeding refunds...\n";
$completedTxns = $pdo->query("SELECT id, merchant_id, amount, currency, created_at FROM `op_transactions` WHERE status = 'completed' LIMIT 60")->fetchAll(\PDO::FETCH_ASSOC);
$refIndex = 1;
foreach ($completedTxns as $t) {
    $refundUuid = makeUuid();
    $refundAmount = round($t['amount'] * 0.5, 2); // Partial refund (50%)
    $refundStatus = ($refIndex % 5 === 0) ? 'pending' : 'completed';
    $processedAt = ($refundStatus === 'completed') ? $t['created_at'] : null;
    
    executeQuery($pdo, "INSERT INTO `op_refunds` (`id`, `merchant_id`, `transaction_id`, `uuid`, `amount`, `reason`, `status`, `processed_at`, `created_at`) 
        VALUES (?, ?, ?, ?, ?, 'Customer refund request', ?, ?, ?)", [
            $refIndex, $t['merchant_id'], $t['id'], $refundUuid, $refundAmount, $refundStatus, $processedAt, $t['created_at']
        ]);
        
    if ($refundStatus === 'completed') {
        try {
            $ledgerService->recordRefund((int)$t['merchant_id'], $refIndex, (int)$t['id'], (string)$refundAmount, $t['currency']);
            // Align dates
            $ltId = $pdo->lastInsertId();
            if ($ltId) {
                executeQuery($pdo, "UPDATE `op_ledger_transactions` SET `created_at` = ? WHERE `id` = ?", [$processedAt, $ltId]);
                executeQuery($pdo, "UPDATE `op_ledger_entries` SET `created_at` = ? WHERE `ledger_transaction_id` = ?", [$processedAt, $ltId]);
            }
            // Update transaction status to refunded
            executeQuery($pdo, "UPDATE `op_transactions` SET `status` = 'refunded' WHERE `id` = ?", [$t['id']]);
        } catch (\Throwable $e) {
            echo "Ledger refund post failed for refund {$refIndex}: " . $e->getMessage() . "\n";
        }
    }
    $refIndex++;
}

// Update account balance totals based on entry entries
foreach ([1, 2, 3] as $mid) {
    foreach (['BDT', 'USD'] as $cur) {
        $stmt = $pdo->prepare("SELECT id, type FROM `op_ledger_accounts` WHERE `merchant_id` = ? AND `currency` = ?");
        $stmt->execute([$mid, $cur]);
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
}

// 21. Seed Disputes (approx 25 disputes)
echo "  -> Seeding disputes...\n";
$completedTxnsForDispute = $pdo->query("SELECT id, merchant_id, amount, created_at FROM `op_transactions` WHERE status = 'completed' LIMIT 25")->fetchAll(\PDO::FETCH_ASSOC);
$dispIndex = 1;
$dispStatuses = ['open', 'under_review', 'won', 'lost', 'closed'];
foreach ($completedTxnsForDispute as $t) {
    $dispStatus = $dispStatuses[$dispIndex % 5];
    $resolvedAt = ($dispStatus === 'won' || $dispStatus === 'lost') ? date('Y-m-d H:i:s', strtotime($t['created_at']) + 86400 * 5) : null;
    
    executeQuery($pdo, "INSERT INTO `op_disputes` (`id`, `merchant_id`, `transaction_id`, `reason`, `amount`, `status`, `resolved_at`, `created_at`) 
        VALUES (?, ?, ?, 'Fraudulent charge claim', ?, ?, ?, ?)", [
            $dispIndex, $t['merchant_id'], $t['id'], $t['amount'], $dispStatus, $resolvedAt, $t['created_at']
        ]);
        
    executeQuery($pdo, "UPDATE `op_transactions` SET `status` = 'disputed' WHERE `id` = ?", [$t['id']]);
    $dispIndex++;
}

// 22. Seed Webhooks & Webhook Delivery Logs
echo "  -> Seeding webhooks & webhook logs...\n";
foreach ([1, 2, 3] as $mid) {
    executeQuery($pdo, "INSERT INTO `op_webhooks` (`id`, `merchant_id`, `url`, `secret`, `events`, `status`) VALUES 
        (?, ?, 'https://api.merchant-shop.test/webhooks/pay-updates', 'whsec_secret_key_123', '[\"payment.completed\", \"payment.failed\", \"refund.processed\"]', 'active')", [$mid, $mid]);
}

for ($w = 1; $w <= 150; $w++) {
    $whId = ($w % 3) + 1;
    $whStatus = ($w % 10 === 0) ? 'failed' : 'delivered';
    executeQuery($pdo, "INSERT INTO `op_webhook_events` (`id`, `webhook_id`, `event_type`, `payload`, `status`, `attempts`, `created_at`) VALUES 
        (?, ?, 'payment.completed', '{\"trx_id\":\"OP_TRX_000001\",\"amount\":150.00}', ?, 1, CURRENT_TIMESTAMP)", [$w, $whId, $whStatus]);
        
    $respCode = ($whStatus === 'delivered') ? 200 : 500;
    executeQuery($pdo, "INSERT INTO `op_webhook_delivery_logs` (`webhook_event_id`, `response_code`, `response_body`, `duration_ms`, `error`) VALUES 
        (?, ?, '{\"success\":true}', ?, ?)", [$w, $respCode, mt_rand(50, 400), ($respCode === 500 ? 'Internal Server Error' : null)]);
        
    $mid = ($w % 3) + 1;
    executeQuery($pdo, "INSERT INTO `op_webhook_deliveries` (`merchant_id`, `gateway`, `event`, `url`, `direction`, `status_code`, `response_time_ms`, `attempt`, `status`, `payload_hash`) VALUES 
        (?, 'system', 'payment.completed', 'https://api.merchant-shop.test/webhooks/pay-updates', 'outbound', ?, ?, 1, ?, ?)", [
            $mid, $respCode, mt_rand(50, 400), $whStatus, hash('sha256', (string)$w)
        ]);
}

// 23. Seed Idempotency Keys (100 records)
echo "  -> Seeding idempotency keys...\n";
for ($ik = 1; $ik <= 100; $ik++) {
    $mid = ($ik % 3) + 1;
    $key = 'idem-key-' . $ik . '-' . makeUuid();
    $hash = hash('sha256', $key);
    executeQuery($pdo, "INSERT INTO `op_idempotency_keys` (`merchant_id`, `idempotency_key`, `request_hash`, `response_code`, `response_body`, `expires_at`) VALUES 
        (?, ?, ?, 200, '{\"success\":true}', CURRENT_TIMESTAMP + INTERVAL 1 DAY)", [$mid, $key, $hash]);
}

// 24. Seed Device pairing tokens & Paired devices
echo "  -> Seeding devices & pairing tokens...\n";
foreach ([1, 2, 3] as $mid) {
    executeQuery($pdo, "INSERT INTO `op_device_pairing_tokens` (`merchant_id`, `created_by`, `otp_hash`, `expires_at`, `is_used`, `used_at`) VALUES 
        (?, 1, ?, CURRENT_TIMESTAMP + INTERVAL 1 HOUR, 1, CURRENT_TIMESTAMP)", [$mid, hash('sha256', '123456')]);
        
    executeQuery($pdo, "INSERT INTO `op_paired_devices` (`merchant_id`, `device_id`, `device_name`, `platform`, `jwt_fingerprint`, `aes_key_encrypted`, `status`) VALUES 
        (?, ?, 'Redmi Note 12', 'android', 'fingerprint_test', 'aes_key_test', 'active')", [$mid, 'dev-id-' . $mid]);
}

// 25. Seed Mobile Notifications (100+ notifications)
echo "  -> Seeding mobile notifications...\n";
for ($n = 1; $n <= 120; $n++) {
    $mid = ($n % 3) + 1;
    $devId = 'dev-id-' . $mid;
    executeQuery($pdo, "INSERT INTO `op_mobile_notifications` (`merchant_id`, `device_uuid`, `type`, `title`, `body`, `is_read`, `created_at`) VALUES 
        (?, ?, 'payment.received', 'Payment Received', 'You received a payment.', ?, CURRENT_TIMESTAMP)", [$mid, $devId, ($n % 3 === 0 ? 1 : 0)]);
}

// 26. Seed Parsed SMS Logs (150+ logs)
echo "  -> Seeding parsed SMS logs...\n";
for ($sms = 1; $sms <= 160; $sms++) {
    $mid = ($sms % 3) + 1;
    $devId = 'dev-id-' . $mid;
    $smsTrxId = 'SMS_TRX_' . $sms;
    $matchStatus = ($sms % 10 === 0) ? 'unmatched' : 'matched';
    $sender = ($sms % 2 === 0) ? 'bKash' : 'Nagad';
    $amount = (float)mt_rand(10, 5000);
    $body = "You have received Tk " . number_format($amount, 2) . ". From 01712345678. TrxID " . $smsTrxId;
    
    executeQuery($pdo, "INSERT INTO `op_sms_parsed` (`merchant_id`, `device_id`, `sender`, `body`, `amount`, `trx_id`, `gateway_slug`, `match_status`, `received_at`) VALUES 
        (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)", [
            $mid, $devId, $sender, $body, $amount, $smsTrxId, strtolower($sender) . '-personal', $matchStatus
        ]);
}

// 27. Seed Audit Logs (220+ logs)
echo "  -> Seeding audit logs...\n";
$auditActions = ['gateway.update', 'invoice.create', 'merchant.update_settings', 'payment_link.create', 'staff.invite', 'role.create', 'api_key.create'];
for ($au = 1; $au <= 220; $au++) {
    $mid = ($au % 3) + 1;
    $act = $auditActions[array_rand($auditActions)];
    executeQuery($pdo, "INSERT INTO `op_audit_logs` (`merchant_id`, `user_id`, `action`, `entity_type`, `entity_id`, `ip_address`, `user_agent`, `signature`) VALUES 
        (?, 1, ?, 'SystemConfig', ?, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', ?)", [$mid, $act, $au, hash('sha256', (string)$au)]);
}

// 28. Seed Login Attempts (120+ attempts)
echo "  -> Seeding login attempts...\n";
for ($la = 1; $la <= 120; $la++) {
    $email = ($la % 10 === 0) ? 'hacker@malicious.com' : 'admin@example.com';
    $success = ($la % 10 === 0) ? 0 : 1;
    $ip = '192.168.1.' . ($la % 254);
    executeQuery($pdo, "INSERT INTO `op_login_attempts` (`email`, `ip_address`, `user_agent`, `success`) VALUES 
        (?, ?, 'Mozilla/5.0', ?)", [$email, $ip, $success]);
}

// 29. Seed API Keys (API keys for the 3 merchants)
echo "  -> Seeding API Keys...\n";
foreach ([1, 2, 3] as $mid) {
    executeQuery($pdo, "INSERT INTO `op_api_keys` (`merchant_id`, `name`, `key_prefix`, `key_hash`, `scopes`, `status`) VALUES 
        (?, 'Default API Key', 'op_live_', ?, '[\"payments.create\", \"payments.read\", \"invoices.create\", \"admin.access\"]', 'active')",
        [$mid, hash('sha256', 'op_live_key_hash_' . $mid)]
    );
}

// 30. Seed Rate Limits
echo "  -> Seeding rate limits...\n";
executeQuery($pdo, "INSERT INTO `op_rate_limits` (`key_name`, `hits`, `window_start`, `expires_at`) VALUES 
    ('ip:127.0.0.1:api', 12, 1781537064, 1781537124),
    ('key:op_live_1:api', 5, 1781537064, 1781537124)");

// 31. Seed Sessions (active sessions for admin/staff)
echo "  -> Seeding active sessions...\n";
executeQuery($pdo, "INSERT INTO `op_sessions` (`id`, `user_id`, `ip_address`, `data`, `last_activity`) VALUES 
    ('sess_admin_token_1234567890', 1, '127.0.0.1', 'user_id|i:1;', ?)", [time()]);

// 32. Seed Queue Jobs (some queued background jobs)
echo "  -> Seeding queue jobs...\n";
executeQuery($pdo, "INSERT INTO `op_queue_jobs` (`queue`, `handler`, `payload`, `attempts`, `available_at`) VALUES 
    ('default', 'OwnPay\\\\Job\\\\WebhookDispatchJob', '{\"event_id\": 1}', 0, ?),
    ('default', 'OwnPay\\\\Job\\\\SmsParsedMatchJob', '{\"sms_id\": 2}', 0, ?)", [time() + 30, time() + 60]);

// 33. Seed Job Queue tracking table
echo "  -> Seeding job queue tracker...\n";
executeQuery($pdo, "INSERT INTO `op_job_queue` (`type`, `payload`, `status`, `attempts`, `priority`, `available_at`) VALUES 
    ('webhook.dispatch', '{\"event_id\": 1}', 'pending', 0, 10, CURRENT_TIMESTAMP),
    ('sms.match', '{\"sms_id\": 2}', 'completed', 1, 5, CURRENT_TIMESTAMP)");

// 34. Seed Cache
echo "  -> Seeding Cache...\n";
executeQuery($pdo, "INSERT INTO `op_cache` (`key_name`, `value`, `expires_at`) VALUES 
    ('system_branding_settings', '{\"primary_color\":\"#4f46e5\"}', ?)", [time() + 3600]);

// 35. Seed Comm Log (150+ communication logs)
echo "  -> Seeding communication logs...\n";
for ($c = 1; $c <= 150; $c++) {
    $mid = ($c % 3) + 1;
    $chan = ($c % 3 === 0) ? 'email' : (($c % 3 === 1) ? 'sms' : 'telegram');
    $recip = ($chan === 'email') ? 'user' . $c . '@example.com' : '01712345' . sprintf('%03d', $c);
    executeQuery($pdo, "INSERT INTO `op_comm_log` (`merchant_id`, `channel`, `recipient`, `subject`, `body`, `provider`, `status`, `sent_at`) VALUES 
        (?, ?, ?, 'Verification Alert', 'Your verification code is 123456', 'twilio', 'sent', CURRENT_TIMESTAMP)", [$mid, $chan, $recip]);
}

// 36. Seed Update History
echo "  -> Seeding update history...\n";
executeQuery($pdo, "INSERT INTO `op_update_history` (`from_version`, `to_version`, `status`, `backup_path`, `checksum`) VALUES 
    ('0.0.9', '0.1.0', 'completed', '/storage/backups/v0.0.9_backup.zip', 'checksum_hash_123456')");

// 37. Seed Maintenance Locks
echo "  -> Seeding maintenance locks...\n";
executeQuery($pdo, "INSERT INTO `op_maintenance_locks` (`reason`, `initiated_by`, `retry_after`) VALUES 
    ('Scheduled database optimization', 'admin', 600)");

// 38. Seed Migrations (sync completed migrations list)
echo "  -> Syncing migrations list...\n";
$migrations = [
    '001_schema_sync.sql',
    '002_brand_scoped_settings.sql',
    '003_create_brand_plugins_table.sql',
    '004_add_invoice_customer_fk.sql',
    '005_add_audit_log_signature.sql',
    '006_add_admin_scope_to_keys.sql',
    '007_create_languages_table.sql',
    '008_add_provider_trx_id.sql',
    '009_webhook_inbound_dedup.sql',
    '010_ledger_account_currency_unique.sql',
    '011_create_job_queue_table.sql'
];
foreach ($migrations as $m) {
    executeQuery($pdo, "INSERT INTO `op_migrations` (`migration`) VALUES (?)", [$m]);
}

echo "\n\033[32m[SUCCESS] OwnPay CLI database seeder ran successfully!\033[0m\n";
echo "Seeded:\n";
echo " - op_merchants: 3\n";
echo " - op_merchant_users: 6\n";
echo " - op_customers: " . $totalCount . "\n";
echo " - op_payment_intents: " . $totalCount . "\n";
echo " - op_transactions: " . $pdo->query("SELECT COUNT(*) FROM `op_transactions`")->fetchColumn() . "\n";
echo " - op_ledger_entries: " . $pdo->query("SELECT COUNT(*) FROM `op_ledger_entries`")->fetchColumn() . "\n";
echo " - op_invoices: " . $pdo->query("SELECT COUNT(*) FROM `op_invoices`")->fetchColumn() . "\n";
echo " - op_refunds: " . $pdo->query("SELECT COUNT(*) FROM `op_refunds`")->fetchColumn() . "\n";
echo " - op_disputes: " . $pdo->query("SELECT COUNT(*) FROM `op_disputes`")->fetchColumn() . "\n";
echo " - op_audit_logs: " . $pdo->query("SELECT COUNT(*) FROM `op_audit_logs`")->fetchColumn() . "\n";
echo " - op_sms_parsed: " . $pdo->query("SELECT COUNT(*) FROM `op_sms_parsed`")->fetchColumn() . "\n";
echo " - op_mobile_notifications: " . $pdo->query("SELECT COUNT(*) FROM `op_mobile_notifications`")->fetchColumn() . "\n";
echo " - op_comm_log: " . $pdo->query("SELECT COUNT(*) FROM `op_comm_log`")->fetchColumn() . "\n";
