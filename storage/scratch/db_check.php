<?php
require 'vendor/autoload.php';

$kernel = new \OwnPay\Kernel();
// We use reflection or call boot() since boot() is private. Let's see if we can call handle() or boot() via reflection.
$ref = new \ReflectionClass($kernel);
$boot = $ref->getMethod('boot');
$boot->setAccessible(true);
$boot->invoke($kernel);

$c = $kernel->getContainer();
$db = $c->get(\OwnPay\Repository\SettingsRepository::class)->getDatabase();

echo "MERCHANTS:\n";
print_r($db->fetchAll('SELECT id, name, slug, is_platform FROM op_merchants'));

echo "\nPAIRING TOKENS:\n";
print_r($db->fetchAll('SELECT * FROM op_device_pairing_tokens ORDER BY id DESC LIMIT 5'));
