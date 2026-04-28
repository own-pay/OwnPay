<?php
declare(strict_types=1);
bcscale(8);

define('OWNPAY_INIT', true);

if (date_default_timezone_get() !== 'UTC') {
    date_default_timezone_set('UTC');
}


/*
|--------------------------------------------------------------------------
| SOA Service Layer Bootstrap (Phase 2.0)
|--------------------------------------------------------------------------
| Loads the Composer autoloader and initializes the SOA Database singleton.
| This makes all OwnPay\Service\* and OwnPay\Repository\* classes
| available to the legacy runtime without breaking the existing flow.
|
| NOTE: op-config.php MUST be loaded first so that $db_host, $db_user,
| $db_pass, $db_name globals are available for Bootstrap::init().
*/
if (file_exists(__DIR__ . '/op-config.php')) {
    require_once __DIR__ . '/op-config.php';
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';

    try {
        \OwnPay\Bootstrap::init();
    } catch (\Throwable $e) {
        // SOA bootstrap failure must not crash the legacy app.
        // Log the error and continue with legacy-only mode.
        error_log('[OwnPay] SOA Bootstrap failed: ' . $e->getMessage());
    }
}

if (file_exists(__DIR__ . '/app/core/adapter.php')) {
    if (isset($op_adapter_loaded)) {

    } else {
        require __DIR__ . '/app/core/adapter.php';

        if (isset($op_adapter_loaded)) {

        } else {
            if (file_exists(__DIR__ . '/errors/404.php')) {
                http_response_code(404);
                require __DIR__ . '/errors/404.php';
                exit();
            } else {
                http_response_code(403);
                exit('Direct access not allowed');
            }
        }
    }
} else {
    if (file_exists(__DIR__ . '/errors/404.php')) {
        http_response_code(404);
        require __DIR__ . '/errors/404.php';
        exit();
    } else {
        http_response_code(403);
        exit('Direct access not allowed');
    }

}

/*
|--------------------------------------------------------------------------
| Basic Security Headers
|--------------------------------------------------------------------------
*/
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block'); // SEC-12 fix
header('Permissions-Policy: camera=(), microphone=(), geolocation=()'); // SEC-12 fix
// Generate a cryptographic nonce per request (kept for backwards compat with nonce="" attributes in existing script tags)
$csp_nonce = base64_encode(random_bytes(16));
// F17: report-uri + report-to for CSP violation visibility (was: silent block).
// Endpoint at /csp-report logs violations via Logger::security().
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$csp_nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdn.tailwindcss.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'self'; report-uri /csp-report;");
header("Report-To: " . json_encode([
    'group'     => 'csp-endpoint',
    'max_age'   => 10886400,
    'endpoints' => [['url' => '/csp-report']],
]));
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains'); // SEC-12 fix
}

/*
|--------------------------------------------------------------------------
| Get Requested Page
|--------------------------------------------------------------------------
*/
$page = $_GET['page'] ?? '';
$page = trim($page, '/');

/*
|--------------------------------------------------------------------------
| SECURITY: Prevent traversal & illegal chars
|--------------------------------------------------------------------------
*/
if (strpos($page, '..') !== false || !preg_match('/^[a-zA-Z0-9\/_-]+$/', $page)) {
    $page = 'homepageRedirect';
}

/*
|--------------------------------------------------------------------------
| Explode path for dynamic values
|--------------------------------------------------------------------------
*/
$segments = explode('/', $page);

/*
|--------------------------------------------------------------------------
| Example:
| /payment/2134124123
|--------------------------------------------------------------------------
*/
$route = $segments[0] ?? '';
$param1 = $segments[1] ?? null;

/*
|--------------------------------------------------------------------------
| Router
|--------------------------------------------------------------------------
*/

if (!file_exists(__DIR__ . '/.maintenance')) {
    if (file_exists(__DIR__ . '/op-config.php')) {
        if (isset($requriemntnoneedchecked) && $requriemntnoneedchecked === true) {
            \OwnPay\Core\Router::dispatch($route, $segments, get_defined_vars());
        } else {
            if (file_exists(__DIR__ . '/errors/requirement.php')) {
                require __DIR__ . '/errors/requirement.php';
            } else {
                if (file_exists(__DIR__ . '/errors/404.php')) {
                    http_response_code(404);
                    require __DIR__ . '/errors/404.php';
                } else {
                    http_response_code(403);
                    exit('Direct access not allowed');
                }
            }
        }
    } else {
        // Refuse to load the installer if it has already been run successfully.
        // The .installed marker is the WordPress-style siteurl flag — if present,
        // we serve the "already installed" page that lives inside install/index.php
        // (which detects the marker and renders the locked landing page).
        if (file_exists(__DIR__ . '/app/install/index.php')) {
            require __DIR__ . '/app/install/index.php';
        } else {
            if (file_exists(__DIR__ . '/errors/404.php')) {
                http_response_code(404);
                require __DIR__ . '/errors/404.php';
            } else {
                http_response_code(403);
                exit('Direct access not allowed');
            }
        }
    }
} else {
    if (file_exists(__DIR__ . '/errors/maintenance.php')) {
        require __DIR__ . '/errors/maintenance.php';
    } else {
        if (file_exists(__DIR__ . '/errors/404.php')) {
            http_response_code(404);
            require __DIR__ . '/errors/404.php';
        } else {
            http_response_code(403);
            exit('Direct access not allowed');
        }
    }
}