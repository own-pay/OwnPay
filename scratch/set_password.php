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
    
    $hash = \OwnPay\Security\Authenticator::hashPassword('admin123');
    
    $stmt = $pdo->prepare("UPDATE op_merchant_users SET password_hash = :hash WHERE id = 1");
    $stmt->execute([':hash' => $hash]);
    
    echo "PASSWORD UPDATED SUCCESS! You can login with email admin@example.com and password admin123\n";
    
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
