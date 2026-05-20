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

echo "=== OP GATEWAY CONFIGS DETAILS ===\n";
try {
    $configs = $db->fetchAll("SELECT * FROM op_gateway_configs");
    foreach ($configs as $cfg) {
        echo "ID: {$cfg['id']}, Merchant ID: {$cfg['merchant_id']}, Gateway ID: {$cfg['gateway_id']}, Mode: {$cfg['mode']}, Status: {$cfg['status']}\n";
        echo "Raw credentials_enc length: " . strlen($cfg['credentials_enc'] ?? '') . "\n";
        if (!empty($cfg['credentials_enc'])) {
            $encryptor = $container->get(\OwnPay\Security\FieldEncryptor::class);
            try {
                $dec = $encryptor->decrypt($cfg['credentials_enc']);
                echo "Decrypted credentials: " . $dec . "\n";
            } catch (\Throwable $e) {
                echo "Decrypt error: " . $e->getMessage() . "\n";
            }
        } else {
            echo "credentials_enc is EMPTY!\n";
        }
    }
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
