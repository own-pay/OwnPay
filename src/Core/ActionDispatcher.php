<?php

declare(strict_types=1);

namespace OwnPay\Core;

use OwnPay\Http\RequestContext;

/**
 * Action Dispatcher — Routes POST actions to controllers.
 *
 * Replaces the massive procedural if/in_array chains in adapter.php
 * with a clean declarative action→controller map.
 */
final class ActionDispatcher
{
    /**
     * Action prefix → Controller class mapping.
     * Each key is matched via str_starts_with against the action name.
     */
    private const ACTION_MAP = [
        // Auth
        'login'                                  => \OwnPay\Controller\Admin\AuthController::class,
        '2fa-verify'                             => \OwnPay\Controller\Admin\AuthController::class,
        'forgot-password'                        => \OwnPay\Controller\Admin\AuthController::class,
        'set-default-brand'                      => \OwnPay\Controller\Admin\AuthController::class,
        'my-account-'                            => \OwnPay\Controller\Admin\AuthController::class,
        'activities-list'                        => \OwnPay\Controller\Admin\AuthController::class,

        // Settings
        'cron-job-command-generate'              => \OwnPay\Controller\Admin\SettingsController::class,
        'geneal-application-settings'            => \OwnPay\Controller\Admin\SettingsController::class,
        'general-setting'                        => \OwnPay\Controller\Admin\SettingsController::class,

        // Dashboard
        'dashboard-'                             => \OwnPay\Controller\Admin\DashboardController::class,
        'reports'                                => \OwnPay\Controller\Admin\DashboardController::class,

        // Customer
        'customer-'                              => \OwnPay\Controller\Admin\CustomerController::class,
        'customers-'                             => \OwnPay\Controller\Admin\CustomerController::class,

        // Invoice
        'invoice-'                               => \OwnPay\Controller\Admin\InvoiceController::class,

        // Payment Link
        'paymentLink-'                           => \OwnPay\Controller\Admin\PaymentLinkController::class,

        // Currency
        'currency-'                              => \OwnPay\Controller\Admin\CurrencyController::class,

        // FAQ
        'faq-'                                   => \OwnPay\Controller\Admin\FaqController::class,

        // API Keys
        'api-'                                   => \OwnPay\Controller\Admin\ApiKeyController::class,

        // Devices
        'device-'                                => \OwnPay\Controller\Admin\DeviceController::class,

        // Balance Verification
        'balance-verification-'                  => \OwnPay\Controller\Admin\BalanceVerificationController::class,

        // SMS Data
        'sms-data-'                              => \OwnPay\Controller\Admin\SmsDataController::class,

        // SMS Templates + Queue
        'sms-template-'                          => \OwnPay\Controller\Admin\SmsTemplateAdminController::class,
        'sms-queue-'                             => \OwnPay\Controller\Admin\SmsTemplateAdminController::class,

        // Themes
        'themes-'                                => \OwnPay\Controller\Admin\ThemeController::class,
        'theme-setting-'                         => \OwnPay\Controller\Admin\ThemeController::class,

        // System Update
        'system-settings-'                       => \OwnPay\Controller\Admin\SystemUpdateController::class,

        // Gateways
        'gateway-'                               => \OwnPay\Controller\Admin\GatewayController::class,
        'gateways-'                              => \OwnPay\Controller\Admin\GatewayController::class,

        // Plugins
        'plugins-'                               => \OwnPay\Controller\Admin\PluginController::class,
    ];

    /**
     * V2 action map (checkout / transaction verify)
     */
    private const V2_ACTION_MAP = [
        'invoice'              => \OwnPay\Controller\Checkout\CheckoutController::class,
        'payment-link'         => \OwnPay\Controller\Checkout\CheckoutController::class,
        'payment-link-default' => \OwnPay\Controller\Checkout\CheckoutController::class,
        'transaction-verify'   => \OwnPay\Controller\Admin\TransactionController::class,
    ];

    /**
     * Dispatch a POST action to the appropriate controller.
     *
     * @return bool True if an action was dispatched.
     */
    public static function dispatch(?RequestContext $requestContext = null): bool
    {
        // V1 actions (action)
        if (isset($_POST['action'])) {
            return self::handleAction(
                \OwnPay\Service\System\InputSanitizer::trim($_POST['action'] ?? ''),
                $_POST['op-token'] ?? '',
                $requestContext,
                self::ACTION_MAP
            );
        }

        // V2 actions (action-v2)
        if (isset($_POST['action-v2'])) {
            return self::handleAction(
                \OwnPay\Service\System\InputSanitizer::trim($_POST['action-v2'] ?? ''),
                $_POST['op-token'] ?? '',
                $requestContext,
                self::V2_ACTION_MAP
            );
        }

        // Companion API actions
        if (isset($_POST['action-companion'])) {
            $action = \OwnPay\Service\System\InputSanitizer::trim($_POST['action-companion'] ?? '');
            $token = $_POST['op-token'] ?? '';

            if ($action === '') {
                echo json_encode(['status' => 'false', 'title' => 'Error', 'message' => 'Your request could not be processed.']);
                exit();
            }

            if (!self::validateCsrf($token)) {
                exit;
            }

            \OwnPay\Controller\Api\CompanionApiController::handle($action, $requestContext);
            exit();
        }

        // SPA content loader
        if (isset($_POST['root'])) {
            return ContentLoader::handle($requestContext);
        }

        return false;
    }

    /**
     * Handle a standard action dispatch with CSRF + optional 2FA.
     */
    private static function handleAction(
        string $action,
        string $token,
        ?RequestContext $requestContext,
        array $actionMap,
    ): bool {
        if ($action === '') {
            echo json_encode(['status' => 'false', 'title' => 'Error', 'message' => 'Your request could not be processed.']);
            exit();
        }

        if (!self::validateCsrf($token)) {
            exit;
        }

        // 2FA verification
        if (isset($_POST['my-two-step-verify-code'])) {
            $tfaMiddleware = new \OwnPay\Middleware\TwoFactorMiddleware();
            $userResponse = $GLOBALS['global_user_response'] ?? ['response' => []];
            $tfaResult = $tfaMiddleware->verify(
                $userResponse['response'][0] ?? [],
                \OwnPay\Service\System\InputSanitizer::html($_POST['my-two-step-verify-code'] ?? '')
            );
            if ($tfaResult['verified']) {
                $GLOBALS['global_two_fector_validate'] = true;
            } else {
                $newCsrfToken = $_SESSION['csrf_token'] ?? '';
                echo json_encode(['status' => 'false', 'title' => 'Verification Failed', 'message' => $tfaResult['error'], 'csrf_token' => $newCsrfToken]);
                exit();
            }
        }

        // Find matching controller
        foreach ($actionMap as $prefix => $controllerClass) {
            if ($action === $prefix || str_starts_with($action, $prefix)) {
                $controllerClass::handle($action, $requestContext);
                exit;
            }
        }

        // No match — return false so caller can handle
        return false;
    }

    /**
     * Validate CSRF token and send error response if invalid.
     */
    private static function validateCsrf(string $token): bool
    {
        $csrfMiddleware = new \OwnPay\Middleware\CsrfMiddleware();
        $result = $csrfMiddleware->validate(\OwnPay\Service\System\InputSanitizer::trim($token));

        if (!$result['valid']) {
            $newCsrfToken = $result['newToken'] ?? $_SESSION['csrf_token'] ?? '';
            echo json_encode([
                'status'     => 'false',
                'title'      => 'Request Failed',
                'message'    => $result['error'],
                'csrf_token' => $newCsrfToken,
            ]);
            return false;
        }

        $GLOBALS['new_csrf_token'] = $result['newToken'] ?? $_SESSION['csrf_token'];
        return true;
    }
}
