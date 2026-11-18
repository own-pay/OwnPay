<?php

declare(strict_types=1);

/**
 * One-shot bootstrap for E2E harnesses. Produces an ownpay instance that is already
 * "installed" — schema imported, seeds applied, demo merchant + superadmin created,
 * .env secrets generated, storage marker written — so CI can drive it headlessly without
 * going through the interactive wizard.
 *
 * Usage from the repo root:
 *   php tools/bootstrap-e2e.php [--password=<pw>] [--email=<addr>] [--username=<name>]
 *
 * Reads DB connection from env vars (DB_HOST/DB_PORT/DB_USER/DB_PASS/DB_NAME/DB_PREFIX).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['password::', 'email::', 'username::']);
$password = isset($options['password']) && is_string($options['password']) ? $options['password'] : 'Smashing!key-2026';
$email    = isset($options['email']) && is_string($options['email']) ? $options['email'] : 'e2e-admin@example.com';
$username = isset($options['username']) && is_string($options['username']) ? $options['username'] : 'e2eadmin';

$host   = getenv('DB_HOST') ?: '127.0.0.1';
$port   = (int) (getenv('DB_PORT') ?: 3306);
$user   = getenv('DB_USER') ?: 'root';
$pass   = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'ownpay';
$prefix = getenv('DB_PREFIX') ?: 'op_';

$rootDir = dirname(__DIR__);

// 1. Wipe stale installed marker so we do a clean install every time.
@unlink($rootDir . '/storage/.installed');
@unlink($rootDir . '/storage/.env.temp');

// 2. Generate .env with fresh secrets.
$env = file_get_contents($rootDir . '/.env.example');
if ($env === false) {
    fwrite(STDERR, "Missing .env.example\n");
    exit(2);
}
$env = preg_replace_callback('/^APP_KEY=(.*?)(#.*)?$/m', static fn() => 'APP_KEY=' . bin2hex(random_bytes(32)), $env) ?? $env;
$env = preg_replace_callback('/^ENCRYPTION_KEY=(.*?)(#.*)?$/m', static fn() => 'ENCRYPTION_KEY=' . bin2hex(random_bytes(32)), $env) ?? $env;
$env = preg_replace_callback('/^HMAC_KEY=(.*?)(#.*)?$/m', static fn() => 'HMAC_KEY=' . bin2hex(random_bytes(32)), $env) ?? $env;
$env = preg_replace_callback('/^JWT_SECRET=(.*?)(#.*)?$/m', static fn() => 'JWT_SECRET=' . bin2hex(random_bytes(32)), $env) ?? $env;
$env = preg_replace_callback('/^AUDIT_HMAC_SECRET=(.*?)(#.*)?$/m', static fn() => 'AUDIT_HMAC_SECRET=' . bin2hex(random_bytes(32)), $env) ?? $env;
$env = str_replace('APP_ENV=production', 'APP_ENV=development', $env);
$env = str_replace('APP_DEBUG=false', 'APP_DEBUG=true', $env);
file_put_contents($rootDir . '/.env', $env);
chmod($rootDir . '/.env', 0640);
fwrite(STDOUT, ".env written\n");

// 3. Connect to server and recreate the database.
try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_LOCAL_INFILE => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: {$e->getMessage()}\n");
    exit(3);
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$dbName}`");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($existing as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
fwrite(STDOUT, "database reset (" . count($existing) . " tables dropped)\n");

// 4. Import schema with explicit error reporting.
$schemaPath = $rootDir . '/database/schema.sql';
if (!is_file($schemaPath)) {
    fwrite(STDERR, "Missing schema.sql at {$schemaPath}\n");
    exit(4);
}
$sql = file_get_contents($schemaPath);
if ($sql === false) {
    fwrite(STDERR, "Cannot read schema.sql\n");
    exit(4);
}
$failures = 0;
foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
    $trimmed = trim($stmt);
    $trimmed = preg_replace('/^--.*\\n/m', '', $trimmed) ?? '';
    $trimmed = preg_replace('~/\\*.*?\\*/~s', '', $trimmed) ?? '';
    $trimmed = trim($trimmed);
    if ($trimmed === '') {
        continue;
    }
    try {
        $pdo->exec($trimmed . ';');
    } catch (PDOException $e) {
        $failures++;
        fwrite(STDERR, "SCHEMA FAIL: " . substr($trimmed, 0, 120) . " ... " . $e->getMessage() . "\n");
    }
}
fwrite(STDOUT, "schema imported ({$failures} failures)\n");
if ($failures > 3) {
    fwrite(STDERR, "Too many schema failures — aborting\n");
    exit(5);
}

// 5. Seeds.
foreach (glob($rootDir . '/database/seeds/*.sql') ?: [] as $seed) {
    $sql = file_get_contents($seed);
    if ($sql === false) { continue; }
    try {
        $pdo->exec($sql);
        fwrite(STDOUT, "seeded: " . basename($seed) . "\n");
    } catch (PDOException $e) {
        fwrite(STDERR, "SEED FAIL {$seed}: {$e->getMessage()}\n");
    }
}

// 6. Merchant + superadmin.
$uuid = sprintf('%08x-%04x-40%02x-%04x-%04x%04x%04x',
    random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xff), random_int(0, 0x3fff) | 0x8000, random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));

try {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
    $pdo->prepare(
        "INSERT INTO {$prefix}merchants (uuid, name, slug, email, timezone, default_currency, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'Asia/Dhaka', 'BDT', 'active', ?, ?)"
    )->execute([$uuid, 'Test Merchant', 'test-merchant-boot-' . bin2hex(random_bytes(3)), $email, $now, $now]);
    $merchantId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO {$prefix}roles (merchant_id, name, slug, description, is_system, created_at)
         VALUES (?, 'Super Admin', 'super-admin', 'Installer-bootstrapped super admin', 1, ?)"
    )->execute([$merchantId, $now]);
    $roleId = (int) $pdo->lastInsertId();

    $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
    $pdo->prepare(
        "INSERT INTO {$prefix}merchant_users
            (merchant_id, role_id, name, username, email, password_hash, is_superadmin, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, 'active', ?, ?)"
    )->execute([$merchantId, $roleId, $username, $username, $email, $passwordHash, $now, $now]);

    fwrite(STDOUT, "merchant_id={$merchantId} role_id={$roleId} username={$username}\n");
} catch (PDOException $e) {
    fwrite(STDERR, "Failed to insert merchant/role/user: {$e->getMessage()}\n");
    exit(6);
}

// 7. Marker.
file_put_contents($rootDir . '/storage/.installed',
    "Installed: " . (new DateTimeImmutable())->format(DateTimeInterface::RFC3339) . "\n" .
    "Version: 0.3.0-e2e\n");
chmod($rootDir . '/storage/.installed', 0640);
fwrite(STDOUT, "marker written\n");

fwrite(STDOUT, "bootstrap done\n");
