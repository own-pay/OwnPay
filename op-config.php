<?php
/**
 * Own Pay — Configuration
 *
 * Loads database credentials from .env file via vlucas/phpdotenv.
 * Falls back to hardcoded defaults if .env is missing (dev convenience only).
 */

// Load Composer autoloader (needed for phpdotenv)
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// Load .env if it exists
$envPath = __DIR__ . '/.env';
if (file_exists($envPath) && class_exists('Dotenv\Dotenv')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

// Database configuration — sourced from .env, with fallback defaults
$db_host = $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'localhost';
$db_user = $_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? 'root';
$db_name = $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? 'ownpay';
$db_prefix = $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';
?>