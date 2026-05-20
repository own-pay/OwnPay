<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Bootstrap DOTENV
$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

// Bootstrap DI container
$container = new \OwnPay\Container();
$binder = require dirname(__DIR__, 2) . '/config/services.php';
$binder($container);

$db = $container->get(\OwnPay\Core\Database::class);

echo "=== RECENT TRANSACTIONS ===\n";
try {
    $txns = $db->fetchAll("SELECT * FROM op_transactions ORDER BY id DESC LIMIT 5");
    print_r($txns);
} catch (\Throwable $e) {
    echo "Error querying op_transactions: " . $e->getMessage() . "\n";
}
