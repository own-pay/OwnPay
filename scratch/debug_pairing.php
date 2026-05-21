<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OwnPay\Core\Database;
use OwnPay\Repository\DevicePairingTokenRepository;
use OwnPay\Repository\PairedDeviceRepository;
use OwnPay\Service\Device\DevicePairingService;
use OwnPay\Service\Auth\JwtService;
use OwnPay\Security\FieldEncryptor;

// Manually set environment variables to match phpunit.xml
$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_NAME'] = 'ownpay_test';
$_ENV['DB_USER'] = 'root';
$_ENV['DB_PASS'] = 'root';
$_ENV['DB_PREFIX'] = 'op_';
$_ENV['PII_ENCRYPTION_KEY'] = 'cd4c6edf857c4ad19cb41784e849adf79ec3fc20319c28e735bd3fbd801eca33';
$_ENV['JWT_SECRET'] = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
$_ENV['APP_ENV'] = 'testing';

Database::init('localhost', 'ownpay_test', 'root', 'root', 3306);
$db = Database::getInstance();
$db->pdo()->exec("DELETE FROM op_device_pairing_tokens");

$tokenRepo = (new DevicePairingTokenRepository($db))->forTenant(12);
$deviceRepo = (new PairedDeviceRepository($db))->forTenant(12);
$jwt = new JwtService();
$pairingService = new DevicePairingService(
    $deviceRepo,
    new FieldEncryptor('test-key-32-chars-long-placeholder'),
    $jwt,
    null
);

echo "PHP timezone: " . date_default_timezone_get() . "\n";
echo "PHP local time: " . date('Y-m-d H:i:s.u') . "\n";

$mysqlTime = $db->fetchOne("SELECT NOW(6) as now")['now'];
echo "MySQL NOW(6): " . $mysqlTime . "\n";

$otpResult = $pairingService->generatePairingOtp(1, 12);
$otp = $otpResult['otp'];
$otpHash = hash('sha256', $otp);

$tokenRow = $db->fetchOne("SELECT * FROM op_device_pairing_tokens WHERE otp_hash = :hash", ['hash' => $otpHash]);
echo "Token Row in DB:\n";
print_r($tokenRow);

$pairResult = $pairingService->pairDevice(
    $otp,
    'Integration Test Device',
    'fp',
    '1.0.0-test',
    'android'
);
print_r($pairResult);
