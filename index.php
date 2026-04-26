<?php
declare(strict_types=1);
bcscale(8);

define('OWNPAY_INIT', true);

if (date_default_timezone_get() !== 'UTC') {
    date_default_timezone_set('UTC');
}

if (file_exists(__DIR__ . '/app/core/functions.php')) {
    if (isset($op_functions_loaded)) {

    } else {
        require __DIR__ . '/app/core/functions.php';

        if (isset($op_functions_loaded)) {

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
            switch ($route) {
                case '404':
                    if (file_exists(__DIR__ . '/errors/404.php')) {
                        http_response_code(404);
                        require __DIR__ . '/errors/404.php';
                    } else {
                        http_response_code(403);
                        exit('Direct access not allowed');
                    }
                    break;

                case 'health':
                    header('Content-Type: application/json');
                    try {
                        $healthController = new \OwnPay\Http\Controller\HealthController();
                        $healthController->index();
                    } catch (\Throwable $e) {
                        http_response_code(503);
                        echo json_encode(['status' => 'error', 'message' => 'Health check failed.']);
                    }
                    exit;

                case 'csp-report':
                    // F17: receive Content-Security-Policy violation reports.
                    try {
                        (new \OwnPay\Http\Controller\CspReportController())->index();
                    } catch (\Throwable $e) {
                        // Always 204 even on internal failure — never leak signals to attackers.
                        http_response_code(204);
                    }
                    exit;

                case 'login':
                case 'forgot':
                case '2fa':
                    if (file_exists(__DIR__ . '/app/admin/' . $route . '.php')) {
                        require __DIR__ . '/app/admin/' . $route . '.php';
                    } else {
                        if (file_exists(__DIR__ . '/errors/404.php')) {
                            http_response_code(404);
                            require __DIR__ . '/errors/404.php';
                        } else {
                            http_response_code(403);
                            exit('Direct access not allowed');
                        }
                    }
                    break;

                case 'ipn':
                    \OwnPay\Controller\Frontend\IpnController::handle(compact('db_prefix', 'site_url', 'path_payment', 'path_invoice', 'path_payment_link', 'route', 'segments', 'param1', 'param2'));
                    break;

                case 'api':
                    \OwnPay\Controller\Frontend\ApiController::handle(compact('db_prefix', 'site_url', 'path_payment', 'path_invoice', 'path_payment_link', 'route', 'segments', 'param1', 'param2'));
                    break;

                case $path_payment:
                    \OwnPay\Controller\Frontend\LegacyCheckoutController::handle(compact('db_prefix', 'site_url', 'path_payment', 'path_invoice', 'path_payment_link', 'route', 'segments', 'param1', 'param2'));
                    break;

                case $path_invoice:
                    \OwnPay\Controller\Frontend\InvoiceCheckoutController::handle(compact('db_prefix', 'site_url', 'path_payment', 'path_invoice', 'path_payment_link', 'route', 'segments', 'param1', 'param2'));
                    break;

                case $path_payment_link:
                    \OwnPay\Controller\Frontend\PaymentLinkCheckoutController::handle(compact('db_prefix', 'site_url', 'path_payment', 'path_invoice', 'path_payment_link', 'route', 'segments', 'param1', 'param2'));
                    break;

                case $path_admin:
                    $_GET['page_name'] = $param1;

                    if (file_exists(__DIR__ . '/app/admin/index.php')) {
                        require __DIR__ . '/app/admin/index.php';
                    } else {
                        if (file_exists(__DIR__ . '/errors/404.php')) {
                            http_response_code(404);
                            require __DIR__ . '/errors/404.php';
                        } else {
                            http_response_code(403);
                            exit('Direct access not allowed');
                        }
                    }
                    break;

                case $path_cron:
                    if ($param1 == "") {
                        if (file_exists(__DIR__ . '/errors/404.php')) {
                            http_response_code(404);
                            require __DIR__ . '/errors/404.php';
                        } else {
                            http_response_code(403);
                            exit('Direct access not allowed');
                        }
                    } else {
                        // F2: timing-safe compare on cron secret (was: == on HTML-encoded value)
                        $cronSecret = (string) get_env('cron-job');
                        if ($cronSecret !== '' && hash_equals($cronSecret, (string) $param1)) {
                            $lockFile = __DIR__ . '/media/storage/cron.lock';
                            $maxLockTime = 60 * 10;

                            header('Content-Type: application/json');

                            echo json_encode(['status' => 'true', "message" => "Cron run executed."]);

                            if (file_exists($lockFile) && (time() - filemtime($lockFile)) < $maxLockTime) {
                                exit;
                            }

                            file_put_contents($lockFile, time());

                            set_env('last-cron-invocation', getCurrentDatetime('Y-m-d H:i:s'));

                            // All cron business logic now lives in src/Cron/*Job.php
                            // (Milestone 7: extracted ~320 lines of inline cron logic into
                            //  SystemUpdateJob, SmsVerificationJob, CurrencyUpdateJob,
                            //  BalanceVerificationJob, WebhookRetryJob — each advisory-locked
                            //  and logged by CronJobRunner.)
                            try {
                                $cronRunner = new \OwnPay\Cron\CronJobRunner();
                                foreach ([
                                    'system_update',
                                    'sms_verification',
                                    'currency_update',
                                    'balance_verification',
                                    'webhook_pending_retry',
                                    'rate_limit_cleanup',
                                    'key_expiry',
                                ] as $cronJob) {
                                    $cronRunner->run($cronJob);
                                }
                            } catch (\Throwable $e) {
                                error_log('[Cron] CronJobRunner error: ' . $e->getMessage());
                            }

                            unlink($lockFile);
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
                    break;

                case 'homepageRedirect':
                    if ($path_homepageRedirect == "") {
                        echo '<script nonce="' . $csp_nonce . '">location.href="login";</script>';
                    } else {
                        echo '<script nonce="' . $csp_nonce . '">location.href="https://' . $path_homepageRedirect . '";</script>';
                    }
                    break;
                default:
                    if (file_exists(__DIR__ . '/errors/404.php')) {
                        http_response_code(404);
                        require __DIR__ . '/errors/404.php';
                    } else {
                        http_response_code(403);
                        exit('Direct access not allowed');
                    }
                    break;
            }
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