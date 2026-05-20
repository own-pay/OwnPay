<?php
declare(strict_types=1);
require_once 'vendor/autoload.php';
$d = \Dotenv\Dotenv::createImmutable('.');
$d->safeLoad();
$c = new OwnPay\Container();
$b = require 'config/services.php';
$b($c);
$db = $c->get(OwnPay\Core\Database::class);

$r = $db->fetchOne("SELECT id, slug, amount, min_amount, max_amount, currency, status FROM op_payment_links WHERE slug='test-e674'");
echo "amount value: '" . ($r['amount'] ?? 'NULL') . "' type: " . gettype($r['amount'] ?? null) . "\n";
echo "min_amount: '" . ($r['min_amount'] ?? 'NULL') . "'\n";
echo "max_amount: '" . ($r['max_amount'] ?? 'NULL') . "'\n";

// Test bccomp behavior
$amount = (string) ($r['amount'] ?? '0');
echo "bccomp(\"{$amount}\", \"0\", 2) = " . bccomp($amount, '0', 2) . "\n";

// Check existing session-bound transactions
$txns = $db->fetchAll("SELECT id, trx_id, status, created_at FROM op_transactions WHERE metadata LIKE '%payment_link_id%' AND metadata LIKE '%\"payment_link_id\": 1%' ORDER BY created_at DESC LIMIT 5");
echo "\nExisting transactions for link_id=1:\n";
foreach ($txns as $t) {
    echo "  {$t['trx_id']} status={$t['status']} created={$t['created_at']}\n";
}
