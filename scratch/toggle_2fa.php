<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$rootDir = dirname(__DIR__);
if (class_exists(\Dotenv\Dotenv::class)) {
    $dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
    $dotenv->safeLoad();
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$name = $_ENV['DB_NAME'] ?? 'ownpay';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? 'root';
$port = (int) ($_ENV['DB_PORT'] ?? 3306);

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    $enable = isset($argv[1]) && $argv[1] === 'enable';
    $val = $enable ? 1 : 0;
    
    $stmt = $pdo->prepare("UPDATE op_merchant_users SET two_factor_enabled = :val WHERE id = 1");
    $stmt->execute([':val' => $val]);
    
    echo "2FA status for Admin set to: " . ($enable ? "ENABLED" : "DISABLED") . "\n";
    
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
