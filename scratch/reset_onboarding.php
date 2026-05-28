<?php
declare(strict_types=1);

require 'vendor/autoload.php';

$kernel = new \OwnPay\Kernel();
$ref = new \ReflectionClass($kernel);
$bootMethod = $ref->getMethod('boot');
$bootMethod->setAccessible(true);
$bootMethod->invoke($kernel);

$containerProp = $ref->getProperty('container');
$containerProp->setAccessible(true);
$container = $containerProp->getValue($kernel);

$settingsRepo = $container->get(OwnPay\Repository\SettingsRepository::class);
$settingsRepo->set('system', 'onboarding_completed', '1');
echo "Onboarding restored to 1.\n";
