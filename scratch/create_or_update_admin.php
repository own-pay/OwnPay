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
    
    echo "Connected to database: {$name}\n";

    // 1. Ensure at least one merchant (brand) exists
    $stmt = $pdo->query("SELECT id FROM op_merchants LIMIT 1");
    $merchant = $stmt->fetch();
    if (!$merchant) {
        $uuid = '00000000-0000-0000-0000-000000000001';
        $pdo->prepare("INSERT INTO op_merchants (id, uuid, name, slug, email, status) VALUES (1, ?, 'Own Pay', 'own-pay', 'admin@example.com', 'active')")
            ->execute([$uuid]);
        $merchantId = 1;
        echo "Created merchant: ID 1\n";
    } else {
        $merchantId = (int)$merchant['id'];
        echo "Using existing merchant ID: {$merchantId}\n";
    }

    // 2. Ensure at least one role exists
    $stmt = $pdo->prepare("SELECT id FROM op_roles WHERE merchant_id = ? LIMIT 1");
    $stmt->execute([$merchantId]);
    $role = $stmt->fetch();
    if (!$role) {
        $pdo->prepare("INSERT INTO op_roles (id, merchant_id, name, slug, description, is_system) VALUES (1, ?, 'Owner', 'owner', 'System owner', 1)")
            ->execute([$merchantId]);
        $roleId = 1;
        echo "Created owner role: ID 1\n";
    } else {
        $roleId = (int)$role['id'];
        echo "Using existing role ID: {$roleId}\n";
    }

    // 3. Hash the password using Authenticator or password_hash fallback
    if (class_exists(\OwnPay\Security\Authenticator::class)) {
        $hash = \OwnPay\Security\Authenticator::hashPassword('admin123');
    } else {
        $hash = password_hash('admin123', PASSWORD_ARGON2ID);
    }

    // 4. Create or update the superadmin user
    $stmt = $pdo->prepare("SELECT id FROM op_merchant_users WHERE username = 'admin' OR email = 'admin@example.com' LIMIT 1");
    $stmt->execute();
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        $userId = (int)$existingUser['id'];
        $update = $pdo->prepare("UPDATE op_merchant_users SET password_hash = :hash, role_id = :roleId, merchant_id = :merchantId, is_superadmin = 1, status = 'active' WHERE id = :userId");
        $update->execute([
            ':hash' => $hash,
            ':roleId' => $roleId,
            ':merchantId' => $merchantId,
            ':userId' => $userId
        ]);
        echo "Updated existing admin user ID {$userId} with password 'admin123'.\n";
    } else {
        $insert = $pdo->prepare("INSERT INTO op_merchant_users (merchant_id, role_id, name, username, email, password_hash, is_superadmin, status) VALUES (:merchantId, :roleId, 'Administrator', 'admin', 'admin@example.com', :hash, 1, 'active')");
        $insert->execute([
            ':merchantId' => $merchantId,
            ':roleId' => $roleId,
            ':hash' => $hash
        ]);
        echo "Created new superadmin user 'admin' / 'admin@example.com' with password 'admin123'.\n";
    }

    echo "SUCCESS!\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
