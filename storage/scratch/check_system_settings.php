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

echo "=== PLUGIN SETTINGS IN DB ===\n";
try {
    $rows = $db->fetchAll("SELECT * FROM op_system_settings WHERE group_name LIKE 'plugin.%'");
    foreach ($rows as $row) {
        echo "ID: {$row['id']}, Group: {$row['group_name']}, Key: {$row['key_name']}, Merchant ID: {$row['merchant_id']}\n";
        // Decrypt value if it seems encrypted or just print value
        $val = $row['value'];
        if (strlen($val) > 40 && str_contains($val, ':')) {
            try {
                $encryptor = $container->get(\OwnPay\Security\FieldEncryptor::class);
                echo "Decrypted value: " . $encryptor->decrypt($val) . "\n";
            } catch (\Throwable $ex) {
                echo "Value (raw): " . $val . "\n";
            }
        } else {
            echo "Value: " . $val . "\n";
        }
    }
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
