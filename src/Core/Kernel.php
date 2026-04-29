<?php

declare(strict_types=1);

namespace OwnPay\Core;

/**
 * Application Kernel — Central bootstrap orchestrator.
 *
 * Replaces the legacy procedural adapter.php with a clean OOP entry point.
 * Handles requirements checking, route configuration, session initialization,
 * plugin boot, and action dispatching.
 */
final class Kernel
{
    private static bool $booted = false;

    /**
     * Boot the application.
     *
     * @return array{
     *     requirementsMet: bool,
     *     routeConfig: array,
     *     requestContext: ?\OwnPay\Http\RequestContext,
     *     csrfToken: string,
     *     siteUrl: string,
     *     cspNonce: string
     * }
     */
    public static function boot(string $cspNonce = ''): array
    {
        if (self::$booted) {
            return self::getState();
        }

        // 1. Check system requirements
        $requirementsMet = RequirementsChecker::check();

        // 2. Load route configuration from environment
        $routeConfig = RouteConfig::load();

        // 3. Initialize session + middleware (only when DB is available)
        $requestContext = null;
        $csrfToken = bin2hex(random_bytes(32));
        $sessionData = [];

        global $db_prefix;

        if (isset($db_prefix) && $db_prefix !== '') {
            $sessionMiddleware = new \OwnPay\Middleware\SessionMiddleware();
            $requestContext = $sessionMiddleware->handle($db_prefix);

            $csrfToken = $requestContext->csrfToken;
            $sessionData = [
                'userLogin'          => $requestContext->isLoggedIn,
                'user2fa'            => $sessionMiddleware->is2fa,
                'twoFactorValidate'  => false,
                'userResponse'       => ['status' => true, 'response' => [$requestContext->user]],
                'brandResponse'      => ['status' => true, 'response' => [$requestContext->brand]],
                'permissionResponse' => $sessionMiddleware->permissionResponse,
                'permissions'        => $requestContext->permissions,
                'cookieResponse'     => $sessionMiddleware->cookieResponse,
                'currencyCode'       => $sessionMiddleware->currencyCode,
                'currencySymbol'     => $sessionMiddleware->currencySymbol,
                'currencyRate'       => $sessionMiddleware->currencyRate,
            ];
        } else {
            $sessionData = [
                'userLogin'          => false,
                'user2fa'            => false,
                'twoFactorValidate'  => false,
                'userResponse'       => ['status' => false, 'response' => []],
                'brandResponse'      => ['status' => false, 'response' => []],
                'permissionResponse' => [],
                'permissions'        => [],
                'cookieResponse'     => [],
                'currencyCode'       => 'BDT',
                'currencySymbol'     => '৳',
                'currencyRate'       => '1.00000000',
            ];
        }

        // Export to globals for view-layer compatibility
        self::exportGlobals($sessionData, $csrfToken);

        // 4. Boot plugin system (only when DB is available)
        if (isset($db_prefix) && $db_prefix !== '') {
            \OwnPay\Plugin\PluginLoader::boot();
        }

        // 5. Build site URL
        $directory = (RouteHelper::siteUrl('fulldomain') === 'http://localhost') ? 'OwnPay-panel/' : '';
        $siteUrl = RouteHelper::siteUrl('fulldomain') . '/' . $directory;

        // Export branding globals
        $GLOBALS['site_url'] = $siteUrl;
        $GLOBALS['OwnPay_favicon'] = $siteUrl . 'assets/images/favicon-light.png';
        $GLOBALS['OwnPay_logo_light'] = $siteUrl . 'assets/images/logo-light.png';
        $GLOBALS['OwnPay_logo_dark'] = $siteUrl . 'assets/images/logo-dark.png';

        self::$booted = true;

        return [
            'requirementsMet' => $requirementsMet,
            'routeConfig'     => $routeConfig,
            'requestContext'   => $requestContext,
            'csrfToken'        => $csrfToken,
            'siteUrl'          => $siteUrl,
            'cspNonce'         => $cspNonce,
        ];
    }

    /**
     * Handle logout if requested.
     */
    public static function handleLogout(string $siteUrl, string $cspNonce): bool
    {
        if (isset($_GET['logout'])) {
            \OwnPay\Service\Auth\AuthSessionService::destroySession();
            echo '<script nonce="' . $cspNonce . '">location.href="' . $siteUrl . 'login";</script>';
            exit();
        }
        return false;
    }

    /**
     * Export session data to global variables for view-layer access.
     */
    private static function exportGlobals(array $sessionData, string $csrfToken): void
    {
        $GLOBALS['csrf_token']                  = $csrfToken;
        $GLOBALS['global_user_login']           = $sessionData['userLogin'];
        $GLOBALS['global_user_2fa']             = $sessionData['user2fa'];
        $GLOBALS['global_two_fector_validate']  = $sessionData['twoFactorValidate'];
        $GLOBALS['global_user_response']        = $sessionData['userResponse'];
        $GLOBALS['global_response_brand']       = $sessionData['brandResponse'];
        $GLOBALS['global_response_permission']  = $sessionData['permissionResponse'];
        $GLOBALS['global_permissions']           = $sessionData['permissions'];
        $GLOBALS['global_cookie_response']      = $sessionData['cookieResponse'];
        $GLOBALS['global_brand_currency_code']  = $sessionData['currencyCode'];
        $GLOBALS['global_brand_currency_symbol'] = $sessionData['currencySymbol'];
        $GLOBALS['global_brand_currency_rate']  = $sessionData['currencyRate'];

        $GLOBALS['OwnPay_current_version'] = [
            'version_name'    => 'v1.0.0',
            'version_code'    => '1.0.0',
            'version_channel' => 'stable',
        ];
    }

    private static function getState(): array
    {
        return [
            'requirementsMet' => true,
            'routeConfig'     => RouteConfig::load(),
            'requestContext'   => null,
            'csrfToken'        => $GLOBALS['csrf_token'] ?? '',
            'siteUrl'          => $GLOBALS['site_url'] ?? '',
            'cspNonce'         => '',
        ];
    }
}
