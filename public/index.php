<?php
declare(strict_types=1);

/**
 * OwnPay - Single Front Controller.
 *
 * ALL requests route through this file via .htaccess rewrites.
 * This is the only PHP file in the public/ directory.
 */

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Autoload
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Composer autoload not found. Run: composer install',
    ]);
    exit(1);
}
require_once $autoload;

// Boot & Handle
$kernel = new \OwnPay\Kernel();
$kernel->handle();
