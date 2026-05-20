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

echo "=== OP GATEWAYS ===\n";
try {
    $gateways = $db->fetchAll("SELECT * FROM op_gateways");
    print_r($gateways);
} catch (\Throwable $e) {
    echo "Error querying op_gateways: " . $e->getMessage() . "\n";
}

echo "\n=== ACTIVE BRANDS ===\n";
try {
    $merchants = $db->fetchAll("SELECT * FROM op_merchants");
    print_r($merchants);
} catch (\Throwable $e) {
    echo "Error querying op_merchants: " . $e->getMessage() . "\n";
}

echo "\n=== OP GATEWAY CONFIGS ===\n";
try {
    $configs = $db->fetchAll("SELECT * FROM op_gateway_configs");
    foreach ($configs as $cfg) {
        echo "ID: {$cfg['id']}, Merchant ID: {$cfg['merchant_id']}, Gateway ID: {$cfg['gateway_id']}, Mode: {$cfg['mode']}, Status: {$cfg['status']}\n";
        // Decrypt credentials if possible
        $encryptor = $container->get(\OwnPay\Security\FieldEncryptor::class);
        if (!empty($cfg['credentials_enc'])) {
            try {
                $decrypted = $encryptor->decrypt($cfg['credentials_enc']);
                echo "Decrypted credentials: " . $decrypted . "\n";
            } catch (\Throwable $ex) {
                echo "Failed to decrypt credentials: " . $ex->getMessage() . "\n";
            }
        }
    }
} catch (\Throwable $e) {
    echo "Error querying op_gateway_configs: " . $e->getMessage() . "\n";
}

echo "\n=== OP MANUAL GATEWAYS ===\n";
try {
    $manuals = $db->fetchAll("SELECT * FROM op_manual_gateways");
    print_r($manuals);
} catch (\Throwable $e) {
    echo "Error querying op_manual_gateways: " . $e->getMessage() . "\n";
}
