<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$container = new \OwnPay\Container();
$binder = require dirname(__DIR__, 2) . '/config/services.php';
$binder($container);

$db = $container->get(\OwnPay\Core\Database::class);

echo "=== PAYMENT LINK: slug=test-e674 ===\n";
$link = $db->fetchOne("SELECT * FROM op_payment_links WHERE slug = 'test-e674'");
if ($link) {
    echo "ID: {$link['id']}, Merchant: {$link['merchant_id']}, Amount: {$link['amount']}, Currency: {$link['currency']}, Status: {$link['status']}\n";
    echo "Max Uses: " . ($link['max_uses'] ?? 'N/A') . ", Use Count: " . ($link['use_count'] ?? 'N/A') . "\n";
    echo "Expires At: " . ($link['expires_at'] ?? 'N/A') . "\n";
} else {
    echo "NOT FOUND!\n";
}

echo "\n=== INVOICE: token starts with 0e4f55b3... ===\n";
$invoice = $db->fetchOne("SELECT * FROM op_invoices WHERE token = '0e4f55b3adac2ffc091fa9abd17e87f3dba836769422e7c0e9698fb35a796bf0'");
if ($invoice) {
    echo "ID: {$invoice['id']}, Merchant: {$invoice['merchant_id']}, Total: {$invoice['total']}, Status: {$invoice['status']}\n";
} else {
    echo "NOT FOUND!\n";
}

echo "\n=== RECENT PENDING TRANSACTIONS ===\n";
$txns = $db->fetchAll("SELECT id, trx_id, merchant_id, amount, currency, status, gateway_slug, method, metadata FROM op_transactions WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5");
foreach ($txns as $t) {
    echo "ID:{$t['id']} TXN:{$t['trx_id']} Amount:{$t['amount']} {$t['currency']} Status:{$t['status']} GW:{$t['gateway_slug']} Method:{$t['method']} Meta:{$t['metadata']}\n";
}

echo "\n=== ACTIVE PAYMENT LINKS ===\n";
$links = $db->fetchAll("SELECT id, slug, amount, currency, status, max_uses, use_count FROM op_payment_links WHERE status = 'active' LIMIT 10");
foreach ($links as $l) {
    echo "ID:{$l['id']} Slug:{$l['slug']} Amount:{$l['amount']} {$l['currency']} Status:{$l['status']} MaxUses:{$l['max_uses']} UseCount:{$l['use_count']}\n";
}

echo "\n=== ACTIVE INVOICES ===\n";
$invoices = $db->fetchAll("SELECT id, invoice_number, token, total, currency, status FROM op_invoices WHERE status IN ('sent', 'overdue') LIMIT 10");
foreach ($invoices as $inv) {
    echo "ID:{$inv['id']} No:{$inv['invoice_number']} Token:" . substr($inv['token'], 0, 20) . "... Total:{$inv['total']} {$inv['currency']} Status:{$inv['status']}\n";
}
