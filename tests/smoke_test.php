<?php
/**
 * Own Pay - Automated Smoke Suite (Local CLI)
 * 
 * Verifies critical application configuration and architecture exists 
 * before allowing traffic into a newly deployed environment.
 * Run via: php tests/smoke_test.php
 */

define('CLI_MODE', php_sapi_name() === 'cli');

if (!CLI_MODE) {
    die("Error: This script must be run from the command line.");
}

$errors = [];
$warnings = [];

echo "========================================\n";
echo " Own Pay - Automated Smoke Suite \n";
echo "========================================\n\n";

// 1. Check PHP Version Requirement (8.1+ recommended, 8.3 target)
$phpVersion = phpversion();
if (version_compare($phpVersion, '8.1.0', '<')) {
    $errors[] = "PHP Version too low. Detected: {$phpVersion}. Required: 8.1+";
} else {
    echo "[\033[32mPASS\033[0m] PHP Version: {$phpVersion}\n";
}

// 2. Verify Config Exists
$configPath = __DIR__ . '/../op-config.php';
$legacyConfigPath = __DIR__ . '/../pp-config.php';

if (file_exists($configPath)) {
    echo "[\033[32mPASS\033[0m] Configuration file found (op-config.php)\n";
    require_once $configPath;

    // Check critical vars
    $requiredVars = ['db_host', 'db_user', 'db_name'];
    foreach ($requiredVars as $varName) {
        if (!isset($$varName) || empty($$varName)) {
            $errors[] = "Missing critical config variable: {$varName}";
        }
    }
} else if (file_exists($legacyConfigPath)) {
    $warnings[] = "Using legacy pp-config.php. Please migrate to op-config.php.";
    echo "[\033[33mWARN\033[0m] Legacy configuration file found.\n";
    require_once $legacyConfigPath;
} else {
    $errors[] = "No configuration file found! Installation incomplete.";
}

// 3. Verify Database Connection (if config loaded)
if (isset($db_host) && isset($db_user) && isset($db_pass) && isset($db_name)) {
    try {
        $dsn = "mysql:host=" . $db_host . ";dbname=" . $db_name . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3 // Quick timeout for smoke tests
        ]);
        echo "[\033[32mPASS\033[0m] Database connection successful.\n";

        // Simple query test
        $pdo->query("SELECT 1")->fetch();
    } catch (PDOException $e) {
        $errors[] = "Database connection failed: " . $e->getMessage();
    }
}

// 4. Check SOA Architecture Integrity
$criticalCoreFiles = [
    '/../app/core/adapter.php',
    '/../app/core/functions.php',
    '/../index.php',
    '/../src/Gateway/GatewayAdapterInterface.php', // SOA verification
];

foreach ($criticalCoreFiles as $file) {
    if (!file_exists(__DIR__ . $file)) {
        $errors[] = "Critical file missing: " . basename($file);
    } else {
        echo "[\033[32mPASS\033[0m] Core File exists: " . basename($file) . "\n";
    }
}

// 5. Vendor autoloader
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    $warnings[] = "Composer autoloader (vendor/autoload.php) missing. Run 'composer install'.";
    echo "[\033[33mWARN\033[0m] Composer dependencies missing.\n";
} else {
    echo "[\033[32mPASS\033[0m] Composer dependencies found.\n";
}

echo "\n========================================\n";
if (count($errors) > 0) {
    echo "\033[31m[FAIL]\033[0m Smoke test failed with " . count($errors) . " errors.\n";
    foreach ($errors as $i => $err) {
        echo "  " . current(explode("\n", $err)) . "\n";
    }
    exit(1);
} else {
    echo "\033[32m[SUCCESS]\033[0m All critical smoke tests passed. System is ready.\n";
    if (count($warnings) > 0) {
        echo "\nAlert: " . count($warnings) . " warnings detected:\n";
        foreach ($warnings as $warn) {
            echo "  - $warn\n";
        }
    }
    exit(0);
}
