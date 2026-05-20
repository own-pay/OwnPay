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

echo "=== OP PLUGINS ===\n";
try {
    $plugins = $db->fetchAll("SELECT * FROM op_plugins");
    print_r($plugins);
} catch (\Throwable $e) {
    echo "Error querying op_plugins: " . $e->getMessage() . "\n";
}

echo "=== LOADED PLUGINS IN REGISTRY ===\n";
try {
    $registry = $container->get(\OwnPay\Plugin\PluginRegistry::class);
    print_r($registry->getLoaded());
} catch (\Throwable $e) {
    echo "Error getting registry: " . $e->getMessage() . "\n";
}

echo "=== REGISTERED ADAPTERS IN GATEWAY BRIDGE ===\n";
try {
    $bridge = $container->get(\OwnPay\Gateway\GatewayBridge::class);
    print_r($bridge->getRegisteredSlugs());
} catch (\Throwable $e) {
    echo "Error getting bridge: " . $e->getMessage() . "\n";
}
