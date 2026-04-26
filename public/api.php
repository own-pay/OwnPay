<?php

/**
 * Own Pay — REST API v1 Entry Point
 *
 * Request Pipeline:
 *   CORS → BearerAuth → IP Allowlist → Rate Limiter → [Request Signature] → Router → Controller → JSON
 *
 * How to serve:
 *   - PHP built-in:  php -S localhost:8080 public/api.php
 *   - Apache:        RewriteRule ^/v1/(.*)$ /public/api.php [L,QSA]
 *   - Nginx:         try_files $uri /public/api.php?$query_string;
 *
 * All endpoints require: Authorization: Bearer <TOKEN>
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────

require_once __DIR__ . '/../vendor/autoload.php';

use OwnPay\Core\Database;
use OwnPay\Http\Router;
use OwnPay\Http\ErrorHandler;
use OwnPay\Http\Controller\PaymentController;
use OwnPay\Http\Controller\TransactionController;
use OwnPay\Http\Controller\RefundController;
use OwnPay\Http\Controller\CustomerController;
use OwnPay\Http\Controller\ApiKeyController;
use OwnPay\Http\Controller\WebhookController;
use OwnPay\Http\Controller\HealthController;
use OwnPay\Middleware\CorsMiddleware;
use OwnPay\Middleware\BearerAuthMiddleware;
use OwnPay\Middleware\IpAllowlistMiddleware;
use OwnPay\Middleware\RateLimiterMiddleware;
use OwnPay\Middleware\RequestSignatureMiddleware;

// Timezone & precision
if (date_default_timezone_get() !== 'UTC') {
    date_default_timezone_set('UTC');
}
bcscale(8);

// ── Error Handling ───────────────────────────────────────────────────

ErrorHandler::register();

// ── Security Headers ─────────────────────────────────────────────────

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── 1. CORS ──────────────────────────────────────────────────────────

$allowedOrigins = [];
$originsEnv = getenv('CORS_ALLOWED_ORIGINS');
if ($originsEnv) {
    $allowedOrigins = array_map('trim', explode(',', $originsEnv));
}
$cors = new CorsMiddleware($allowedOrigins);
$cors->handle(); // Exits on OPTIONS preflight

// ── Database ─────────────────────────────────────────────────────────

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'ownpay';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbPort = (int) (getenv('DB_PORT') ?: 3306);

Database::init($dbHost, $dbName, $dbUser, $dbPass, $dbPort);

// ── 2. Bearer Authentication ─────────────────────────────────────────

$auth = new BearerAuthMiddleware();
$merchant = $auth->guard(); // Exits with 401/403 on failure

// ── 3. IP Allowlist ──────────────────────────────────────────────────

$ipMiddleware = new IpAllowlistMiddleware();
$allowedIps = $merchant['allowed_ips'] ?? null;
if (is_string($allowedIps)) {
    $allowedIps = json_decode($allowedIps, true);
}
$ipMiddleware->enforce($allowedIps); // Exits with 403 on block

// ── 4. Rate Limiting ─────────────────────────────────────────────────

$rateLimiter = new RateLimiterMiddleware(
    whitelist: ['op_admin_'] // Admin keys bypass rate limiting
);
$rateLimiter->enforce(
    $merchant['key_id'],
    $merchant['key_prefix'] ?? '',
    $_SERVER['REQUEST_METHOD']
); // Exits with 429 on breach

// ── 5. Request Signature (optional, for write ops) ───────────────────

$method = $_SERVER['REQUEST_METHOD'];
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
    $sigMiddleware = new RequestSignatureMiddleware();
    if ($sigMiddleware->hasSignatureHeaders()) {
        $signingSecret = $merchant['signing_secret'] ?? '';
        if (!empty($signingSecret)) {
            $rawBody = file_get_contents('php://input') ?: '';
            $sigMiddleware->enforce($rawBody, $signingSecret);
        }
    }
}

// ── Routes ───────────────────────────────────────────────────────────

$router = new Router();

// --- Payments ---
$router->post('/v1/payments', [PaymentController::class, 'create']);
$router->get('/v1/payments/{id}', [PaymentController::class, 'show']);

// --- Transactions ---
$router->get('/v1/transactions', [TransactionController::class, 'index']);
$router->get('/v1/transactions/{id}', [TransactionController::class, 'show']);

// --- Refunds ---
$router->post('/v1/refunds', [RefundController::class, 'create']);
$router->get('/v1/refunds/{id}', [RefundController::class, 'show']);

// --- Customers ---
$router->get('/v1/customers', [CustomerController::class, 'index']);
$router->get('/v1/customers/{id}', [CustomerController::class, 'show']);

// --- API Keys ---
$router->post('/v1/api-keys', [ApiKeyController::class, 'create']);
$router->get('/v1/api-keys', [ApiKeyController::class, 'index']);
$router->delete('/v1/api-keys/{id}', [ApiKeyController::class, 'destroy']);

// --- Webhooks ---
$router->post('/v1/webhooks', [WebhookController::class, 'create']);
$router->get('/v1/webhooks', [WebhookController::class, 'index']);
$router->delete('/v1/webhooks/{id}', [WebhookController::class, 'destroy']);

// --- Health ---
$router->get('/v1/health', [HealthController::class, 'index']);
$router->get('/v1/health/reconciliation', [HealthController::class, 'reconciliation']);

// ── Dispatch ─────────────────────────────────────────────────────────

$router->dispatch();
