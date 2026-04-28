<?php

declare(strict_types=1);

namespace OwnPay\Core;

class Router
{
    /**
     * Dispatch the incoming request to the appropriate controller or legacy view.
     */
    public static function dispatch(string $route, array $segments, array $globals): void
    {
        $param1 = $segments[1] ?? null;
        $param2 = $segments[2] ?? null;

        extract($globals, EXTR_SKIP);

        switch ($route) {
            case '404':
                self::renderError(404);
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
                try {
                    (new \OwnPay\Http\Controller\CspReportController())->index();
                } catch (\Throwable $e) {
                    http_response_code(204);
                }
                exit;

            case 'login':
            case 'forgot':
            case '2fa':
                if ($route === 'login') {
                    require __DIR__ . '/../../app/admin/login.php';
                } elseif ($route === 'forgot') {
                    require __DIR__ . '/../../app/admin/forgot.php';
                } elseif ($route === '2fa') {
                    require __DIR__ . '/../../app/admin/2fa.php';
                } else {
                    self::renderError(404);
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

                if (file_exists(__DIR__ . '/../../app/admin/index.php')) {
                    require __DIR__ . '/../../app/admin/index.php';
                } else {
                    self::renderError(404);
                }
                break;

            case $path_cron:
                if ($param1 == "") {
                    self::renderError(404);
                } else {
                    $cronSecret = (string) get_env('cron-job');
                    if ($cronSecret !== '' && hash_equals($cronSecret, (string) $param1)) {
                        $lockFile = __DIR__ . '/../../media/storage/cron.lock';
                        $maxLockTime = 60 * 10;

                        header('Content-Type: application/json');

                        echo json_encode(['status' => 'true', "message" => "Cron run executed."]);

                        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < $maxLockTime) {
                            exit;
                        }

                        file_put_contents($lockFile, time());

                        set_env('last-cron-invocation', \OwnPay\Service\DateTimeService::getCurrentDatetime('Y-m-d H:i:s'));

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
                        self::renderError(404);
                    }
                }
                break;

            case 'homepageRedirect':
                if (empty($path_homepageRedirect)) {
                    echo '<script nonce="' . $csp_nonce . '">location.href="login";</script>';
                } else {
                    echo '<script nonce="' . $csp_nonce . '">location.href="https://' . $path_homepageRedirect . '";</script>';
                }
                break;

            default:
                self::renderError(404);
                break;
        }
    }

    private static function renderError(int $code): void
    {
        if (file_exists(__DIR__ . '/../../errors/' . $code . '.php')) {
            http_response_code($code);
            require __DIR__ . '/../../errors/' . $code . '.php';
        } else {
            http_response_code(403);
            exit('Direct access not allowed');
        }
    }
}
