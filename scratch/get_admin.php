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
    
    echo "CONNECTED TO DB Successfully!\n";
    
    // Fetch users
    $stmt = $pdo->query("SELECT id, name, email, role_id, is_superadmin, status, two_factor_enabled FROM op_merchant_users");
    $users = $stmt->fetchAll();
    echo "USERS:\n";
    print_r($users);
    
    // Fetch merchants (brands)
    $stmt = $pdo->query("SELECT id, name, slug, status FROM op_merchants");
    $merchants = $stmt->fetchAll();
    echo "MERCHANTS:\n";
    print_r($merchants);
    
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
