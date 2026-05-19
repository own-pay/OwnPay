<?php
declare(strict_types=1);
/**
 * AUD-10 Migration: Add missing permissions to existing installations.
 * Run once: php storage/migrate_permissions.php
 */

require_once dirname(__DIR__) . '/public/index.php';
// If index.php boots kernel, we need direct DB access instead:

$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
$name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'ownpay';
$user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    // Try loading .env manually
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $_ENV[trim($k)] = trim($v);
            }
        }
        $pdo = new PDO(
            "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
            $_ENV['DB_USER'], $_ENV['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } else {
        die("Cannot connect to DB: " . $e->getMessage() . "\n");
    }
}

$newPermissions = [
    ['admin.access', 'Basic Admin Access', 'admin'],
    ['brands.view', 'View Brands', 'brands'],
    ['brands.manage', 'Manage Brands', 'brands'],
    ['transactions.manage', 'Manage Transactions', 'transactions'],
    ['invoices.manage', 'Manage Invoices', 'invoices'],
    ['payment_links.manage', 'Manage Payment Links', 'payment_links'],
    ['customers.manage', 'Manage Customers', 'customers'],
    ['staff.manage', 'Manage Staff', 'staff'],
    ['settings.manage', 'Manage Settings', 'settings'],
];

$stmt = $pdo->prepare("INSERT IGNORE INTO op_permissions (slug, name, group_name) VALUES (:slug, :name, :grp)");

$added = 0;
foreach ($newPermissions as [$slug, $name, $group]) {
    $stmt->execute(['slug' => $slug, 'name' => $name, 'grp' => $group]);
    if ($stmt->rowCount() > 0) {
        echo "  + Added: {$slug}\n";
        $added++;
    } else {
        echo "  = Exists: {$slug}\n";
    }
}

// Auto-assign admin.access to all existing roles
$adminAccessId = $pdo->query("SELECT id FROM op_permissions WHERE slug = 'admin.access' LIMIT 1")->fetchColumn();
if ($adminAccessId) {
    $roles = $pdo->query("SELECT id FROM op_roles")->fetchAll(PDO::FETCH_COLUMN);
    $assignStmt = $pdo->prepare("INSERT IGNORE INTO op_role_permissions (role_id, permission_id) VALUES (:rid, :pid)");
    foreach ($roles as $roleId) {
        $assignStmt->execute(['rid' => $roleId, 'pid' => $adminAccessId]);
    }
    echo "  → Assigned admin.access to " . count($roles) . " roles\n";
}

echo "\nDone. {$added} new permissions added.\n";
