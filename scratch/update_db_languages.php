<?php
declare(strict_types=1);

// Boot loader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env
if (class_exists(\Dotenv\Dotenv::class)) {
    $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
}

$migrationPath = dirname(__DIR__) . '/database/migrations/007_create_languages_table.sql';
if (!file_exists($migrationPath)) {
    die("Migration file 007 not found.\n");
}

$migrationContent = file_get_contents($migrationPath);
if ($migrationContent === false) {
    die("Failed to read migration file.\n");
}

// Extract JSON string from insert statement
// Matches everything inside VALUES ('en', 'English', 'active', 1, '{...}')
if (!preg_match('/VALUES\s*\(\s*\'en\'\s*,\s*\'English\'\s*,\s*\'active\'\s*,\s*1\s*,\s*\'(.*?)\'\s*\)/s', $migrationContent, $matches)) {
    die("Failed to parse JSON from migration file.\n");
}

$json = $matches[1];
$json = str_replace("''", "'", $json); // Unescape SQL single quotes if any

// Let's validate the extracted JSON
$decoded = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Extracted JSON is invalid: " . json_last_error_msg() . "\n");
}

// Update databases
$configs = [
    [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'name' => $_ENV['DB_NAME'] ?? 'ownpay',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? 'root',
    ],
    [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'ownpay_test',
        'user' => 'root',
        'pass' => 'root',
    ]
];

foreach ($configs as $cfg) {
    try {
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        
        $stmt = $pdo->prepare("UPDATE op_languages SET translations = :json WHERE code = 'en'");
        $stmt->execute(['json' => $json]);
        echo "Successfully updated translations in database: {$cfg['name']}\n";
    } catch (\Throwable $e) {
        echo "Error updating database {$cfg['name']}: " . $e->getMessage() . "\n";
    }
}
