<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

// SEC-13: Harden session security with strict cookie flags
session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'cookie_samesite' => 'Lax',
    'use_only_cookies' => true,
    'use_strict_mode' => true,
]);

if (date_default_timezone_get() !== 'UTC') {
    date_default_timezone_set('UTC');
}

$phpVersion = PHP_VERSION;

$requirements = [
    [
        'name' => 'PHP Version',
        'required' => '8.1.x - 8.3.x',
        'current' => PHP_VERSION,
        'check' => version_compare(PHP_VERSION, '8.1.0', '>=') && version_compare(PHP_VERSION, '8.4.0', '<')
    ],
    [
        'name' => 'cURL',
        'required' => 'Enabled',
        'current' => function_exists('curl_init') ? 'Enabled' : 'Disabled',
        'check' => function_exists('curl_init')
    ],
    [
        'name' => 'cURL Multi',
        'required' => 'Enabled',
        'current' => function_exists('curl_multi_init') ? 'Enabled' : 'Disabled',
        'check' => function_exists('curl_multi_init')
    ],
    [
        'name' => 'PDO',
        'required' => 'Enabled',
        'current' => extension_loaded('pdo') && class_exists('PDO') ? 'Enabled' : 'Disabled',
        'check' => extension_loaded('pdo') && class_exists('PDO')
    ],
    [
        'name' => 'GD Library',
        'required' => 'Enabled',
        'current' => extension_loaded('gd') && function_exists('gd_info') ? 'Enabled' : 'Disabled',
        'check' => extension_loaded('gd') && function_exists('gd_info')
    ],
    [
        'name' => 'Fileinfo',
        'required' => 'Enabled',
        'current' => function_exists('finfo_open') ? 'Enabled' : 'Disabled',
        'check' => function_exists('finfo_open')
    ],
    [
        'name' => 'Imagick',
        'required' => 'Enabled',
        'current' => extension_loaded('imagick') ? 'Enabled' : 'Disabled',
        'check' => extension_loaded('imagick')
    ],
    [
        'name' => 'OpenSSL',
        'required' => 'Enabled',
        'current' => extension_loaded('openssl') ? 'Enabled' : 'Disabled',
        'check' => extension_loaded('openssl')
    ],
    [
        'name' => 'ZipArchive',
        'required' => 'Enabled',
        'current' => (extension_loaded('zip') && class_exists('ZipArchive')) ? 'Enabled' : 'Disabled',
        'check' => (extension_loaded('zip') && class_exists('ZipArchive'))
    ],
    [
        'name' => 'Mbstring',
        'required' => 'Enabled',
        'current' => extension_loaded('mbstring') ? 'Enabled' : 'Disabled',
        'check' => extension_loaded('mbstring')
    ],
    [
        'name' => 'Tokenizer',
        'required' => 'Enabled',
        'current' => extension_loaded('tokenizer') ? 'Enabled' : 'Disabled',
        'check' => extension_loaded('tokenizer')
    ],
    [
        'name' => 'JSON',
        'required' => 'Enabled',
        'current' => extension_loaded('json') ? 'Enabled' : 'Disabled',
        'check' => extension_loaded('json')
    ],
    [
        'name' => 'allow_url_fopen',
        'required' => 'Enabled',
        'current' => ini_get('allow_url_fopen') ? 'Enabled' : 'Disabled',
        'check' => ini_get('allow_url_fopen')
    ],
    [
        'name' => 'file_uploads',
        'required' => 'Enabled',
        'current' => ini_get('file_uploads') ? 'Enabled' : 'Disabled',
        'check' => ini_get('file_uploads')
    ],
    [
        'name' => 'bcmath',
        'required' => 'Enabled',
        'current' => extension_loaded('bcmath') ? 'Enabled' : 'Disabled',
        'check' => extension_loaded('bcmath')
    ],
    [
        'name' => 'Composer Dependencies',
        'required' => 'Installed',
        'current' => file_exists(__DIR__ . '/../../vendor/autoload.php') ? 'Installed' : 'Missing',
        'check' => file_exists(__DIR__ . '/../../vendor/autoload.php')
    ]
];

$requriemntnoneedchecked = true;

foreach ($requirements as $req) {
    if (!$req['check']) {
        $requriemntnoneedchecked = false;
    }
}

$path_payment = 'payment';
$path_invoice = 'invoice';
$path_payment_link = 'payment-link';
$path_admin = 'admin';
$path_cron = 'cron';
$path_homepageRedirect = '';

if (!file_exists(__DIR__ . '/functions.php')) {
    http_response_code(404);
    if (file_exists(__DIR__ . '/../../errors/404.php')) {
        require __DIR__ . '/../../errors/404.php';
    } else {
        exit('Direct access not allowed');
    }
    exit();
}
if (!isset($op_functions_loaded)) {
    require __DIR__ . '/functions.php';
}
if (!isset($op_functions_loaded)) {
    http_response_code(404);
    if (file_exists(__DIR__ . '/../../errors/404.php')) {
        require __DIR__ . '/../../errors/404.php';
    } else {
        exit('Direct access not allowed');
    }
    exit();
}

if (file_exists(__DIR__ . '/../../op-config.php')) {
    require __DIR__ . '/../../op-config.php';

    if ($requriemntnoneedchecked == true) {
        $path_payment = ($value = get_env('geneal-application-settings-paymentPath')) && $value !== null && $value !== '' ? $value : 'payment';
        $path_invoice = ($value = get_env('geneal-application-settings-invoicePath')) && $value !== null && $value !== '' ? $value : 'invoice';
        $path_payment_link = ($value = get_env('geneal-application-settings-paymentLinkPath')) && $value !== null && $value !== '' ? $value : 'payment-link';
        $path_admin = ($value = get_env('geneal-application-settings-adminPath')) && $value !== null && $value !== '' ? $value : 'admin';
        $path_cron = ($value = get_env('geneal-application-settings-cronPath')) && $value !== null && $value !== '' ? $value : 'cron';
        $path_homepageRedirect = ($value = get_env('geneal-application-settings-homepageRedirect')) && $value !== null && $value !== '' ? $value : '';

        $params_addon = [':status' => 'active'];
        $response_addonLoader = json_decode(getData($db_prefix . 'addon', ' WHERE status = :status ORDER BY 1 DESC ', '* FROM', $params_addon), true);
        foreach ($response_addonLoader['response'] as $row) {
            $addonPath = __DIR__ . '/../modules/addons/' . $row['slug'] . '/';

            if (!is_dir($addonPath)) {
                continue;
            }

            if (file_exists($addonPath . 'class.php')) {
                require_once $addonPath . 'class.php';

                $slug = str_replace(['-', '_'], ' ', strtolower($row['slug']));
                $slug = str_replace(' ', '', ucwords($slug));

                $className = $slug . 'Addon';

                if (class_exists($className)) {
                    $options = [];

                    $params_addonOpt = [':addon_id' => $row['addon_id']];
                    $response_addonOptionLoader = json_decode(getData($db_prefix . 'addon_parameter', ' WHERE addon_id = :addon_id', '* FROM', $params_addonOpt), true);
                    foreach ($response_addonOptionLoader['response'] as $rowOption) {
                        $value = $rowOption['value'];
                        if (in_array($value[0] ?? '', ['[', '{'])) {
                            $decoded = json_decode($value, true);
                            if ($decoded !== null)
                                $value = $decoded;
                        }

                        $options[$rowOption['option_name']] = $value;
                    }

                    new $className($options);
                }
            }
        }
    }
} else {
    if (file_exists(__DIR__ . '/../../op-temp-config.php')) {
        require __DIR__ . '/../../op-temp-config.php';
    }
}



if (file_exists(__DIR__ . '/../../media/sdk/fpdf/fpdf.php')) {
    require __DIR__ . '/../../media/sdk/fpdf/fpdf.php';
} else {
    http_response_code(403);
    exit('SDK Missing');
}

$op_adapter_loaded = true;

$OwnPay_current_version = [
    'version_name' => 'v3.0.0-beta',
    'version_code' => '3.0.0',
    'version_hash' => '6b6f7c62e34e3680398387720dbd44a036d1a574860d5f90a3bd5d9b6280bea1
c9515853f1fbf61175dd3dbce6eb011e4cf29fc43949ed4b562f6421b88c8773
c0dc07a71b29a9da279310f2247affb16089334cc3da60fa0b4b4f06f78594cb
29668acba982d4706c0b8827b5cb9c85ecd24ba899f62116a5dcb7dea121d451
83bfac44e905e37a7ff65776b378988011d407fe308d57af204d1d88093ba733
3ef016b79259331703a1a3db6d1b886e38226d9d619673c81ab13d6ee53bdd99
46e4e9bd74065d7e87ad545cba46957bc5d695290c6b2a4786710de8785bbb48
46cc094590e12b359b3f8c429b75c7771164d64b4ee77c3783b304a6757f1dcb
aa021689e729dc2302b47e9bdc7d1a9f8b72f95f01530da35bf3b848b188d5b1
09a03d6d70021d1c0dd64cefd6e400b18d0e43d00d821b8f52e2e9370908779e',
    'version_channel' => 'beta'
];

$directory = (op_site_url('fulldomain') == 'http://localhost') ? 'OwnPay-panel/' : '';
$site_url = op_site_url('fulldomain') . '/' . $directory;

$OwnPay_favicon = $site_url . 'assets/images/favicon-light.png';
$OwnPay_logo_light = $site_url . 'assets/images/logo-light.png';
$OwnPay_logo_dark = $site_url . 'assets/images/logo-dark.png';

if (isset($_GET['logout'])) {
    logoutCookie();
    ?>
    <script nonce="<?= $csp_nonce ?? '' ?>">
        location.href = '<?php echo $site_url . 'login' ?>';
    </script>
    <?php
    exit();
}

// --- SOA Middleware Orchestration ---
// Only run session middleware when op-config.php has been loaded (i.e., $db_prefix is set).
// During fresh install, this block is skipped entirely.
if (isset($db_prefix) && $db_prefix !== '') {
    $_sessionMiddleware = new \OwnPay\Middleware\SessionMiddleware();
    $requestContext = $_sessionMiddleware->handle($db_prefix);

    // Export to globals for backwards compatibility with controllers
    $csrf_token = $requestContext->csrfToken;
    $global_user_login = $requestContext->isLoggedIn;
    $global_user_2fa = $_sessionMiddleware->is2fa;
    $global_two_fector_validate = false;
    $global_user_response = ['status' => true, 'response' => [$requestContext->user]];
    $global_response_brand = ['status' => true, 'response' => [$requestContext->brand]];
    $global_response_permission = $_sessionMiddleware->permissionResponse;
    $global_permissions = $requestContext->permissions;
    $global_cookie_response = $_sessionMiddleware->cookieResponse;
    $global_brand_currency_code = $_sessionMiddleware->currencyCode;
    $global_brand_currency_symbol = $_sessionMiddleware->currencySymbol;
    $global_brand_currency_rate = $_sessionMiddleware->currencyRate;
} else {
    // Installer mode: provide safe defaults so the boot sequence doesn't crash
    $csrf_token = bin2hex(random_bytes(32));
    $global_user_login = false;
    $global_user_2fa = false;
    $global_two_fector_validate = false;
    $global_user_response = ['status' => false, 'response' => []];
    $global_response_brand = ['status' => false, 'response' => []];
    $global_response_permission = [];
    $global_permissions = [];
    $global_cookie_response = [];
    $global_brand_currency_code = 'BDT';
    $global_brand_currency_symbol = '৳';
    $global_brand_currency_rate = '1.00000000';
}

// --- Plugin System Boot ---
// Load, register, and boot all active plugins before any routing
// Only boot plugins when the system is fully configured (not during install)
if (isset($db_prefix) && $db_prefix !== '') {
    \OwnPay\Plugin\PluginLoader::boot();
}

if (isset($_POST['action'])) {
    $action = clean_input($_POST['action'] ?? '');
    // Support modern 'op-' parameters with fallback to legacy 'pp-'
    $op_app_token = clean_input($_POST['op-token'] ?? $_POST['pp-token'] ?? '');

    if ($action == "") {
        echo json_encode(['status' => "false", 'title' => 'Oops! Something went wrong', 'message' => 'Your request could not be processed. Please try again.']);
    } else {
        // --- CSRF / HMAC validation via CsrfMiddleware ---
        $_csrfMiddleware = new \OwnPay\Middleware\CsrfMiddleware();
        $_csrfResult = $_csrfMiddleware->validate($op_app_token);
        if (!$_csrfResult['valid']) {
            $new_csrf_token = $_csrfResult['newToken'] ?? $_SESSION['csrf_token'] ?? '';
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => $_csrfResult['error'], 'csrf_token' => $new_csrf_token]);
            exit;
        }
        $new_csrf_token = $_csrfResult['newToken'] ?? $_SESSION['csrf_token'];

        // --- 2FA verification via TwoFactorMiddleware ---
        if (isset($_POST['my-two-step-verify-code'])) {
            $_tfaMiddleware = new \OwnPay\Middleware\TwoFactorMiddleware();
            $_tfaResult = $_tfaMiddleware->verify(
                $global_user_response['response'][0] ?? [],
                sanitize_html($_POST['my-two-step-verify-code'] ?? '')
            );
            if ($_tfaResult['verified']) {
                $global_two_fector_validate = true;
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Verification Failed', 'message' => $_tfaResult['error'], 'csrf_token' => $new_csrf_token]);
                exit();
            }
        }

        if (in_array($action, ["login", "2fa-verify", "forgot-password", "set-default-brand", "my-account-profile-information", "my-account-account-browser-sessions", "my-account-account-two-factor-authentication", "activities-list"])) {
            \OwnPay\Controller\AuthController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["cron-job-command-generate", "geneal-application-settings", "general-setting"])) {
            \OwnPay\Controller\SettingsController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["dashboard-transaction-statistics", "dashboard-gateway-statistics", "reports"])) {
            \OwnPay\Controller\DashboardController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["customer-list", "customers-create", "customers-bulk-action", "customers-delete", "customers-info-byID", "customers-edit"])) {
            \OwnPay\Controller\CustomerController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["invoice-list", "invoice-create", "invoice-edit", "invoice-manageStatus", "invoice-bulk-action", "invoice-delete"])) {
            \OwnPay\Controller\InvoiceController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["paymentLink-list", "paymentLink-bulk-action", "paymentLink-delete", "paymentLink-create", "paymentLink-edit", "paymentLink-defaultLinkCurrency"])) {
            \OwnPay\Controller\PaymentLinkController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["currency-list", "currency-edit", "currency-info-byID", "currency-bulkImport", "currency-rateSync", "currency-bulk-rateSync"])) {
            \OwnPay\Controller\CurrencyController::handle($action, $requestContext);
            exit;
        }

        // Note: 'geneal-application-settings' is handled by SettingsController above (line ~499)


        if (in_array($action, ["faq-list", "faq-create", "faq-info-byID", "faq-edit", "faq-bulk-action", "faq-delete"])) {
            \OwnPay\Controller\FaqController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["api-create", "api-list", "api-info-byID", "api-bulk-action", "api-delete", "api-edit"])) {
            \OwnPay\Controller\ApiKeyController::handle($action, $requestContext);
            exit;
        }


        // Note: 'general-setting' is handled by SettingsController above (line ~499)


        if (in_array($action, ["device-list", "device-delete", "device-bulk-action", "device-connect-info", "device-pair-generate", "device-paired-list", "device-revoke"])) {
            \OwnPay\Controller\DeviceController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["balance-verification-list", "balance-verification-bulk-action", "balance-verification-delete", "balance-verification-create", "balance-verification-iupdate", "balance-verification-info-byID", "balance-verification-update"])) {
            \OwnPay\Controller\BalanceVerificationController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["sms-data-list", "sms-data-delete", "sms-data-bulk-action", "sms-data-create", "sms-data-info-byID", "sms-data-edit"])) {
            \OwnPay\Controller\SmsDataController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["sms-template-list", "sms-template-create", "sms-template-info-byID", "sms-template-edit", "sms-template-delete", "sms-template-test-regex", "sms-queue-list", "sms-queue-reprocess", "sms-queue-resolve"])) {
            \OwnPay\Controller\SmsTemplateAdminController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["themes-new-active", "theme-setting-update"])) {
            \OwnPay\Controller\ThemeController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["system-settings-update-setting", "system-settings-update-check", "system-settings-update-download", "system-settings-update-install", "system-settings-import"])) {
            \OwnPay\Controller\SystemUpdateController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["gateway-create", "gateways-list", "gateways-delete", "gateway-install", "gateway-uninstall"])) {
            \OwnPay\Controller\GatewayController::handle($action, $requestContext);
            exit;
        }

        if (in_array($action, ["plugins-list", "plugins-install", "plugins-activate", "plugins-deactivate", "plugins-delete", "plugins-settings-get", "plugins-settings-save", "plugins-scan"])) {
            \OwnPay\Controller\PluginController::handle($action, $requestContext);
            exit;
        }

    }

    exit();
}

if (isset($_POST['action-v2'])) {
    $action = clean_input($_POST['action-v2'] ?? '');
    $op_app_token = clean_input($_POST['op-token'] ?? $_POST['pp-token'] ?? '');

    if ($action == "") {
        echo json_encode(['status' => "false", 'title' => 'Oops! Something went wrong', 'message' => 'Your request could not be processed. Please try again.']);
    } else {
        // --- CSRF / HMAC validation via CsrfMiddleware ---
        $_csrfMiddleware = new \OwnPay\Middleware\CsrfMiddleware();
        $_csrfResult = $_csrfMiddleware->validate($op_app_token);
        if (!$_csrfResult['valid']) {
            $new_csrf_token = $_csrfResult['newToken'] ?? $_SESSION['csrf_token'] ?? '';
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => $_csrfResult['error'], 'csrf_token' => $new_csrf_token]);
            exit;
        }
        $new_csrf_token = $_csrfResult['newToken'] ?? $_SESSION['csrf_token'];

        if (in_array($action, ["invoice", "payment-link", "payment-link-default"])) {
            \OwnPay\Controller\CheckoutController::handle($action, $requestContext);
            exit;
        }

        if ($action == "transaction-verify") {
            \OwnPay\Controller\TransactionController::handle($action, $requestContext);
            exit;
        }
    }
    exit();
}
if (isset($_POST['action-companion'])) {
    $action = clean_input($_POST['action-companion'] ?? '');
    $op_app_token = clean_input($_POST['op-token'] ?? $_POST['pp-token'] ?? '');

    if ($action == "") {
        echo json_encode(['status' => "false", 'title' => 'Oops! Something went wrong', 'message' => 'Your request could not be processed. Please try again.']);
    } else {
        // --- CSRF / HMAC validation via CsrfMiddleware ---
        $_csrfMiddleware = new \OwnPay\Middleware\CsrfMiddleware();
        $_csrfResult = $_csrfMiddleware->validate($op_app_token);
        if (!$_csrfResult['valid']) {
            $new_csrf_token = $_csrfResult['newToken'] ?? $_SESSION['csrf_token'] ?? '';
            echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => $_csrfResult['error'], 'csrf_token' => $new_csrf_token]);
            exit;
        }
        $new_csrf_token = $_csrfResult['newToken'] ?? $_SESSION['csrf_token'];

        \OwnPay\Controller\CompanionApiController::handle($action, $requestContext);
    }
    exit();
}
if (isset($_POST['root'])) {
    if ($global_user_login == true) {
        // F3: strict identifier whitelist (was: regex allowing slashes; not used as path).
        // The 'root' value is only used as an existence sentinel for this AJAX endpoint —
        // never concatenated into a filesystem path or SQL query. Defense in depth.
        $root = \OwnPay\Service\InputSanitizer::trim($_POST['root'] ?? '');
        if ($root === '' || strlen($root) > 64 || !preg_match('/^[a-zA-Z0-9_\-\/]+$/', $root) || str_contains($root, '..')) {
            echo json_encode(['status' => "false", 'message' => 'Invalid request.']);
            exit;
        }

        $initPendingTrscount = 0;
        try {
            $pdo = \OwnPay\Core\Database::getInstance()->getPdo();
            $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `{$db_prefix}transaction` WHERE brand_id = :bid AND status = 'pending'");
            $stmt->execute([':bid' => $global_response_brand['response'][0]['brand_id']]);
            $initPendingTrscount = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        } catch (PDOException $e) {
            error_log('Pending count error: ' . $e->getMessage());
        }
        ?>
        <script nonce="<?= $csp_nonce ?? '' ?>">
            function initPendingTrs() {
                <?php
                if ($initPendingTrscount == 0) {
                    ?>
                    var pendBadge = document.querySelector(".nav-item-transaction .op-badge-danger"); if (pendBadge) pendBadge.style.display = 'none';
                    <?php
                } else {
                    ?>
                    var pendBadge = document.querySelector(".nav-item-transaction .op-badge-danger"); if (pendBadge) pendBadge.innerHTML = '<?= $initPendingTrscount ?>';
                    <?php
                }
                ?>
            }
            initPendingTrs();
        </script>
        <?php
        $base = __DIR__ . '/../admin/dashboard/';

        if (file_exists($base . $root . '.php')) {
            include($base . $root . '.php');
        } else if (file_exists($base . $root . '/index.php')) {
            include($base . $root . '/index.php');
        } else {
            echo '<div class="flex flex-col items-center justify-center py-32"><div class="w-20 h-20 mb-4 rounded-full bg-gray-50 dark:bg-gray-800 flex items-center justify-center"><svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v4a1 1 0 0 0 1 1h3"/><path d="M7 7v10"/><path d="M10 8v8a1 1 0 0 0 1 1h2a1 1 0 0 0 1 -1v-8a1 1 0 0 0 -1 -1h-2a1 1 0 0 0 -1 1z"/><path d="M17 7v4a1 1 0 0 0 1 1h3"/><path d="M21 7v10"/></svg></div><h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Page Not Found</h3><p class="text-sm text-gray-500 dark:text-gray-400">The page you are looking for does not exist or has been moved.</p></div>';
            exit;
        }
    } else {
        echo json_encode(['status' => 'false', 'message' => 'Invalid request']);
    }
    exit;

}


