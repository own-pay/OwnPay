<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| OwnPay v1.0 — Front Controller
|--------------------------------------------------------------------------
|
| Single entry point for ALL requests. Handles:
|   1. Composer autoloading & DB bootstrap
|   2. Security headers (CSP, HSTS, X-Frame, etc.)
|   3. Application kernel boot (session, plugins, config)
|   4. POST action dispatching (AJAX → Controller)
|   5. GET page routing (URL → View)
|
*/

define('OWNPAY_INIT', true);

bcscale(8);

if (date_default_timezone_get() !== 'UTC') {
    date_default_timezone_set('UTC');
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

/*
|--------------------------------------------------------------------------
| 1. Autoloader & Database Bootstrap
|--------------------------------------------------------------------------
*/
if (file_exists(__DIR__ . '/op-config.php')) {
    require_once __DIR__ . '/op-config.php';
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';

    try {
        \OwnPay\Bootstrap::init();
    } catch (\Throwable $e) {
        error_log('[OwnPay] Bootstrap failed: ' . $e->getMessage());
    }
} else {
    http_response_code(503);
    exit('Composer dependencies not installed. Run: composer install');
}

/*
|--------------------------------------------------------------------------
| 2. Session
|--------------------------------------------------------------------------
*/
session_start([
    'cookie_httponly'  => true,
    'cookie_secure'    => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'cookie_samesite'  => 'Lax',
    'use_only_cookies' => true,
    'use_strict_mode'  => true,
]);

/*
|--------------------------------------------------------------------------
| 3. Security Headers
|--------------------------------------------------------------------------
*/
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$csp_nonce = base64_encode(random_bytes(16));

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$csp_nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdn.tailwindcss.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'self'; report-uri /csp-report;");
header('Report-To: ' . json_encode([
    'group'     => 'csp-endpoint',
    'max_age'   => 10886400,
    'endpoints' => [['url' => '/csp-report']],
]));

if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

/*
|--------------------------------------------------------------------------
| 4. Maintenance Mode
|--------------------------------------------------------------------------
*/
if (file_exists(__DIR__ . '/.maintenance')) {
    http_response_code(503);
    if (file_exists(__DIR__ . '/errors/maintenance.php')) {
        require __DIR__ . '/errors/maintenance.php';
    } else {
        exit('Service temporarily unavailable.');
    }
    exit;
}

/*
|--------------------------------------------------------------------------
| 5. Installer Check (no op-config.php = fresh install)
|--------------------------------------------------------------------------
*/
if (!file_exists(__DIR__ . '/op-config.php')) {
    if (file_exists(__DIR__ . '/app/install/index.php')) {
        require __DIR__ . '/app/install/index.php';
    } else {
        http_response_code(503);
        exit('Application not configured. Installer not found.');
    }
    exit;
}

/*
|--------------------------------------------------------------------------
| 6. Kernel Boot (requirements, session, plugins, config)
|--------------------------------------------------------------------------
*/
$kernel = \OwnPay\Core\Kernel::boot($csp_nonce);

if (!$kernel['requirementsMet']) {
    if (file_exists(__DIR__ . '/errors/requirement.php')) {
        require __DIR__ . '/errors/requirement.php';
    } else {
        http_response_code(503);
        exit('System requirements not met.');
    }
    exit;
}

// Extract kernel state for routing
$routeConfig    = $kernel['routeConfig'];
$requestContext = $kernel['requestContext'];
$site_url       = $kernel['siteUrl'];

// Route path variables
$path_payment          = $routeConfig['payment'];
$path_invoice          = $routeConfig['invoice'];
$path_payment_link     = $routeConfig['paymentLink'];
$path_admin            = $routeConfig['admin'];
$path_cron             = $routeConfig['cron'];
$path_homepageRedirect = $routeConfig['homepageRedirect'];

/*
|--------------------------------------------------------------------------
| 7. Handle Logout
|--------------------------------------------------------------------------
*/
\OwnPay\Core\Kernel::handleLogout($site_url, $csp_nonce);

/*
|--------------------------------------------------------------------------
| 8. POST Action Dispatch (AJAX)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \OwnPay\Core\ActionDispatcher::dispatch($requestContext);
}

/*
|--------------------------------------------------------------------------
| 9. GET Page Router
|--------------------------------------------------------------------------
*/
$page = $_GET['page'] ?? '';
$page = trim($page, '/');

// Prevent path traversal
if ($page !== '' && (str_contains($page, '..') || !preg_match('/^[a-zA-Z0-9\/_-]+$/', $page))) {
    $page = 'homepageRedirect';
}

$segments = explode('/', $page);
$route    = $segments[0] ?? '';
$param1   = $segments[1] ?? null;
$param2   = $segments[2] ?? null;

switch ($route) {
    // ─── Error Pages ────────────────────────────────────────────
    case '404':
        http_response_code(404);
        require __DIR__ . '/errors/404.php';
        break;

    // ─── Health Check (JSON) ────────────────────────────────────
    case 'health':
        header('Content-Type: application/json');
        try {
            (new \OwnPay\Http\Controller\HealthController())->index();
        } catch (\Throwable) {
            http_response_code(503);
            echo json_encode(['status' => 'error', 'message' => 'Health check failed.']);
        }
        exit;

    // ─── CSP Report Endpoint ────────────────────────────────────
    case 'csp-report':
        try {
            (new \OwnPay\Http\Controller\CspReportController())->index();
        } catch (\Throwable) {
            http_response_code(204);
        }
        exit;

    // ─── Authentication Pages ───────────────────────────────────
    case 'login':
        require __DIR__ . '/app/admin/login.php';
        break;
    case 'forgot':
        require __DIR__ . '/app/admin/forgot.php';
        break;
    case '2fa':
        require __DIR__ . '/app/admin/2fa.php';
        break;

    // ─── IPN Callback ───────────────────────────────────────────
    case 'ipn':
        \OwnPay\Controller\Frontend\IpnController::handle(compact(
            'db_prefix', 'site_url', 'path_payment', 'path_invoice',
            'path_payment_link', 'route', 'segments', 'param1', 'param2'
        ));
        break;

    // ─── Public API ─────────────────────────────────────────────
    case 'api':
        \OwnPay\Controller\Frontend\ApiController::handle(compact(
            'db_prefix', 'site_url', 'path_payment', 'path_invoice',
            'path_payment_link', 'route', 'segments', 'param1', 'param2'
        ));
        break;

    // ─── Checkout Routes (dynamic paths from config) ────────────
    case $path_payment:
        \OwnPay\Controller\Frontend\PaymentCheckoutController::handle(compact(
            'db_prefix', 'site_url', 'path_payment', 'path_invoice',
            'path_payment_link', 'route', 'segments', 'param1', 'param2'
        ));
        break;

    case $path_invoice:
        \OwnPay\Controller\Frontend\InvoiceCheckoutController::handle(compact(
            'db_prefix', 'site_url', 'path_payment', 'path_invoice',
            'path_payment_link', 'route', 'segments', 'param1', 'param2'
        ));
        break;

    case $path_payment_link:
        \OwnPay\Controller\Frontend\PaymentLinkCheckoutController::handle(compact(
            'db_prefix', 'site_url', 'path_payment', 'path_invoice',
            'path_payment_link', 'route', 'segments', 'param1', 'param2'
        ));
        break;

    // ─── Admin Dashboard ────────────────────────────────────────
    case $path_admin:
        $_GET['page_name'] = $param1;
        if (file_exists(__DIR__ . '/app/admin/index.php')) {
            require __DIR__ . '/app/admin/index.php';
        } else {
            http_response_code(404);
            require __DIR__ . '/errors/404.php';
        }
        break;

    // ─── Cron Runner ────────────────────────────────────────────
    case $path_cron:
        if (empty($param1)) {
            http_response_code(404);
            require __DIR__ . '/errors/404.php';
            break;
        }
        $cronSecret = (string) \OwnPay\Service\System\EnvironmentService::get('cron-job');
        if ($cronSecret !== '' && hash_equals($cronSecret, (string) $param1)) {
            $lockFile    = __DIR__ . '/media/storage/cron.lock';
            $maxLockTime = 600; // 10 minutes

            header('Content-Type: application/json');
            echo json_encode(['status' => 'true', 'message' => 'Cron run executed.']);

            if (file_exists($lockFile) && (time() - filemtime($lockFile)) < $maxLockTime) {
                exit;
            }

            file_put_contents($lockFile, time());
            \OwnPay\Service\System\EnvironmentService::set(
                'last-cron-invocation',
                \OwnPay\Service\System\DateTimeService::getCurrentDatetime('Y-m-d H:i:s')
            );

            try {
                $cronRunner = new \OwnPay\Cron\CronJobRunner();
                foreach ([
                    'system_update', 'sms_verification', 'currency_update',
                    'balance_verification', 'webhook_pending_retry',
                    'rate_limit_cleanup', 'key_expiry',
                ] as $cronJob) {
                    $cronRunner->run($cronJob);
                }
            } catch (\Throwable $e) {
                error_log('[Cron] CronJobRunner error: ' . $e->getMessage());
            }

            @unlink($lockFile);
        } else {
            http_response_code(404);
            require __DIR__ . '/errors/404.php';
        }
        break;

    // ─── Homepage / Default Redirect ────────────────────────────
    case '':
    case 'homepageRedirect':
        if (empty($path_homepageRedirect)) {
            echo '<script nonce="' . $csp_nonce . '">location.href="login";</script>';
        } else {
            echo '<script nonce="' . $csp_nonce . '">location.href="https://' . htmlspecialchars($path_homepageRedirect, ENT_QUOTES) . '";</script>';
        }
        break;

    // ─── 404 Catch-All ──────────────────────────────────────────
    default:
        http_response_code(404);
        if (file_exists(__DIR__ . '/errors/404.php')) {
            require __DIR__ . '/errors/404.php';
        } else {
            exit('Page not found.');
        }
        break;
}