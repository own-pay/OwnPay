<?php
declare(strict_types=1);

if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

if (date_default_timezone_get() !== 'UTC') {
    date_default_timezone_set('UTC');
}

$op_functions_loaded = true;

// ── Filesystem & Path Utilities ──────────────────────────────────────

/**
 * Safely resolve and validate a theme or module path to prevent path traversal.
 * Returns the validated realpath or false if the slug is invalid.
 */
function safeModulePath(string $slug, string $baseDir, string $suffix = '/class.php'): string|false
{
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
        return false;
    }

    $targetPath = realpath($baseDir . '/' . $slug . $suffix);
    $realBaseDir = realpath($baseDir);

    if ($targetPath === false || $realBaseDir === false) {
        return false;
    }

    if (strpos($targetPath, $realBaseDir . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }

    return $targetPath;
}

// ── URL & Domain Helpers ─────────────────────────────────────────────

function op_site_url(string $type = "Full"): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || ($_SERVER['SERVER_PORT'] ?? 0) == 443) ? "https://" : "http://";

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';

    $hostParts = explode('.', $host);
    $numParts = count($hostParts);
    $mainDomain = ($numParts >= 2)
        ? $hostParts[$numParts - 2] . '.' . $hostParts[$numParts - 1]
        : $host;

    return match (strtolower($type)) {
        'fulldomain' => $protocol . $host,
        'maindomain' => $mainDomain,
        default      => $protocol . $host . $requestUri,
    };
}

function getAdminPath(string $url): string
{
    $url = explode('?', $url)[0];
    $pos = strpos($url, 'admin/');
    if ($pos === false) {
        return '';
    }
    return trim(substr($url, $pos + strlen('admin/')), '/');
}

function getDomainValue(string $input): string|false
{
    $input = trim($input);

    if ($input === '') {
        return false;
    }

    if (!preg_match('#^https?://#i', $input)) {
        $input = 'http://' . $input;
    }

    $host = parse_url($input, PHP_URL_HOST);
    if (!$host) {
        return false;
    }

    $host = preg_replace('/^www\./i', '', $host);

    if (!preg_match('/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,}$/i', $host)) {
        return false;
    }

    return strtolower($host);
}

function addQueryParams(string $url, array $params = []): string
{
    $parsedUrl = parse_url($url);

    $existingParams = [];
    if (!empty($parsedUrl['query'])) {
        parse_str($parsedUrl['query'], $existingParams);
    }

    $finalParams = array_merge($existingParams, $params);
    $queryString = http_build_query($finalParams);

    $baseUrl =
        ($parsedUrl['scheme'] ?? '') . ($parsedUrl['scheme'] ? '://' : '') .
        ($parsedUrl['host'] ?? '') .
        ($parsedUrl['path'] ?? '');

    return $baseUrl . '?' . $queryString;
}

// ── HTTP Request Helpers ─────────────────────────────────────────────

function getAuthorizationHeader(): ?string
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['MHS-OwnPay-API-KEY'])) {
            return trim($headers['MHS-OwnPay-API-KEY']);
        }
    }

    foreach ($_SERVER as $key => $value) {
        if (stripos($key, 'HTTP_MHS_OwnPay_API_KEY') !== false) {
            return trim($value);
        }
    }

    return null;
}

function getUserDeviceInfo(): array
{
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    $deviceType = match (true) {
        (bool) preg_match('/mobile/i', $userAgent) => 'Mobile',
        (bool) preg_match('/tablet/i', $userAgent) => 'Tablet',
        default                                    => 'Desktop',
    };

    $os = match (true) {
        (bool) preg_match('/Windows/i', $userAgent)    => 'Windows',
        (bool) preg_match('/Mac/i', $userAgent)        => 'Mac OS',
        (bool) preg_match('/Linux/i', $userAgent)      => 'Linux',
        (bool) preg_match('/Android/i', $userAgent)    => 'Android',
        (bool) preg_match('/iPhone|iPad/i', $userAgent) => 'iOS',
        default                                        => 'Unknown OS',
    };

    $browser = match (true) {
        (bool) preg_match('/MSIE|Trident/i', $userAgent) => 'Internet Explorer',
        (bool) preg_match('/Firefox/i', $userAgent)      => 'Firefox',
        (bool) preg_match('/Chrome/i', $userAgent)       => 'Chrome',
        (bool) preg_match('/Safari/i', $userAgent)       => 'Safari',
        (bool) preg_match('/Opera|OPR/i', $userAgent)    => 'Opera',
        (bool) preg_match('/Edge/i', $userAgent)         => 'Edge',
        default                                          => 'Unknown Browser',
    };

    return [
        'ip_address' => $ipAddress,
        'device'     => $deviceType,
        'os'         => $os,
        'browser'    => $browser,
    ];
}

function getParam(array $params, string $key): ?string
{
    if (!isset($params[$key]) || !is_string($params[$key])) {
        return null;
    }

    $value = trim($params[$key]);
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $value)) {
        return null;
    }

    return \OwnPay\Service\InputSanitizer::html($value);
}

// ── Identity Generators (cryptographically secure) ───────────────────

function generateStrongPassword(int $length = 16): string
{
    $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lower = 'abcdefghijklmnopqrstuvwxyz';
    $digits = '0123456789';
    $symbols = '@#$%&!*^-_=+';
    $all = $upper . $lower . $digits . $symbols;

    $password = $upper[random_int(0, strlen($upper) - 1)];
    $password .= $lower[random_int(0, strlen($lower) - 1)];
    $password .= $digits[random_int(0, strlen($digits) - 1)];
    $password .= $symbols[random_int(0, strlen($symbols) - 1)];

    for ($i = 4; $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }

    $chars = str_split($password);
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    return implode('', $chars);
}

function generateItemID(int $length = 10, int $maxLength = 10): string
{
    $length = min($length, $maxLength);

    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= random_int(0, 9);
    }

    return $id;
}

function getNameChars(string $fullName, int $length = 2): string
{
    $fullName = trim($fullName);

    if ($fullName === '' || $length <= 0) {
        return '';
    }

    $parts = array_values(array_filter(explode(' ', $fullName)));

    if (count($parts) > 1) {
        return strtoupper(
            substr($parts[0], 0, 1) .
            substr(end($parts), 0, max(0, $length - 1))
        );
    }

    return strtoupper(substr($parts[0], 0, $length));
}

// ── i18n Helpers ─────────────────────────────────────────────────────

function resolveModuleLanguage(string $brandLanguage, array $supportedLanguages): string
{
    if (!empty($_SESSION['ui_language']) && isset($supportedLanguages[$_SESSION['ui_language']])) {
        return $_SESSION['ui_language'];
    }

    if (isset($supportedLanguages[$brandLanguage])) {
        return $brandLanguage;
    }

    return array_key_first($supportedLanguages);
}

function buildLangArray(array $langText, ?string $language = 'en'): array
{
    $lang = [];
    foreach ($langText as $key => $translations) {
        $lang[$key] = $translations[$language] ?? reset($translations);
    }
    return $lang;
}

// ══════════════════════════════════════════════════════════════════════
// THIN-WRAPPER DELEGATION LAYER
// All functions below delegate to modern services in src/Service/.
// ══════════════════════════════════════════════════════════════════════

// ── Input Sanitization → InputSanitizer ──────────────────────────────

function sanitize_html(mixed $value): mixed
{
    return \OwnPay\Service\InputSanitizer::html($value);
}

function clean_input(mixed $value): mixed
{
    return \OwnPay\Service\InputSanitizer::trim($value);
}

// ── Cookies & Session → AuthSessionService ───────────────────────────

function setsCookie(string $cookieName, string $cookieValue, int $days = 365): void
{
    \OwnPay\Service\AuthSessionService::setCookie($cookieName, $cookieValue, $days);
}

function getCookie(string $cookieName): ?string
{
    return \OwnPay\Service\AuthSessionService::getCookie($cookieName);
}

function logoutCookie(): void
{
    \OwnPay\Service\AuthSessionService::destroySession();
}

// ── PII-Safe Logging → LogSanitizer ──────────────────────────────────

function safe_log(string $message): void
{
    static $sanitizer = null;
    if ($sanitizer === null) {
        $sanitizer = new \OwnPay\Security\LogSanitizer();
    }
    error_log($sanitizer->sanitizeString($message));
}

// ── CRUD Operations → CrudService ────────────────────────────────────

function getData(string $tableName, string $condition = '', string $select = '* FROM', array $params = []): string
{
    $result = \OwnPay\Service\CrudService::select($tableName, $condition, $select, $params);
    return json_encode($result) ?: '{"status":false,"response":[]}';
}

function insertData(string $tableName, array $columns, array $values): bool
{
    return \OwnPay\Service\CrudService::insert($tableName, $columns, $values);
}

function updateData(string $tableName, array $columns, array $values, string $condition, array $whereParams = []): bool
{
    return \OwnPay\Service\CrudService::update($tableName, $columns, $values, $condition, $whereParams);
}

function deleteData(string $tableName, string $condition, array $whereParams = []): bool
{
    return \OwnPay\Service\CrudService::delete($tableName, $condition, $whereParams);
}

// ── Environment Settings → EnvironmentService ────────────────────────

function get_env(string $option_name, string $brand_id = 'both'): string
{
    return \OwnPay\Service\EnvironmentService::get($option_name, $brand_id);
}

function set_env(string $option_name, string $value, string $brand_id = 'both'): string
{
    return \OwnPay\Service\EnvironmentService::set($option_name, $value, $brand_id);
}

// ── Date & Time → DateTimeService ────────────────────────────────────

function timeAgo(string $datetime): string
{
    return \OwnPay\Service\DateTimeService::timeAgo($datetime);
}

function getCurrentDatetime(string $format = 'Y-m-d H:i:s'): string
{
    return \OwnPay\Service\DateTimeService::getCurrentDatetime($format);
}

function dateformat(string $date, string $format = 'd/m/Y'): bool
{
    return \OwnPay\Service\DateTimeService::dateformat($date, $format);
}

function convertUTCtoUserTZ(string $utc_time, string $user_tz = 'UTC', string $format = 'Y-m-d H:i:s'): string
{
    return \OwnPay\Service\DateTimeService::convertUTCtoUserTZ($utc_time, $user_tz, $format);
}

function isExpired(string $expires_at): bool
{
    return \OwnPay\Service\DateTimeService::isExpired($expires_at);
}

// ── Money & Currency → CurrencyService ───────────────────────────────

function moneyToInt(string $amount, int $decimals = 2): int
{
    return \OwnPay\Service\CurrencyService::moneyToInt($amount, $decimals);
}

function intToMoney(int $amount, int $decimals = 2): string
{
    return \OwnPay\Service\CurrencyService::intToMoney($amount, $decimals);
}

function money_sanitize(string|int|float|null $value): string
{
    return \OwnPay\Service\CurrencyService::money_sanitize($value);
}

function money_add(string|int|float $a, string|int|float $b, int $scale = 8): string
{
    return \OwnPay\Service\CurrencyService::money_add($a, $b, $scale);
}

function money_sub(string|int|float $a, string|int|float $b, int $scale = 8): string
{
    return \OwnPay\Service\CurrencyService::money_sub($a, $b, $scale);
}

function money_mul(string|int|float $a, string|int|float $b, int $scale = 8): string
{
    return \OwnPay\Service\CurrencyService::money_mul($a, $b, $scale);
}

function money_div(string|int|float $a, string|int|float $b, int $scale = 8): string
{
    return \OwnPay\Service\CurrencyService::money_div($a, $b, $scale);
}

function money_round(string|int|float $amount, int $decimals = 2): string
{
    return \OwnPay\Service\CurrencyService::money_round($amount, $decimals);
}

function verifyPaymentTolerance(string $checkout, string $paid, string $tolerance): bool
{
    return \OwnPay\Service\CurrencyService::verifyPaymentTolerance($checkout, $paid, $tolerance);
}

// ── Notifications → NotificationService ──────────────────────────────

function sendIPN(string $url, array $payload): int
{
    return \OwnPay\Service\NotificationService::sendIPN($url, $payload);
}

function sendIPNMulti(array $jobs): array
{
    return \OwnPay\Service\NotificationService::sendIPNMulti($jobs);
}

// ── MFS Operations → MfsService ──────────────────────────────────────

function senderWhitelist(?string $sender = null, ?string $providerKey = null, string $mode = 'provider', ?string $providerName = null): array|false
{
    return \OwnPay\Service\MfsService::senderWhitelist($sender, $providerKey, $mode, $providerName);
}

function MFSMessageVerified(string $mfs, string $message): array|false
{
    return \OwnPay\Service\MfsService::MFSMessageVerified($mfs, $message);
}

function reconcileByLongestChain(string $device_id, string $sender_key, string $type): void
{
    \OwnPay\Service\MfsService::reconcileByLongestChain($device_id, $sender_key, $type);
}

// ── Permissions → PermissionService ──────────────────────────────────

function permissionSchema(): array
{
    return \OwnPay\Service\PermissionService::permissionSchema();
}

function countPermissions(string $tabKey, array $tabData): int
{
    return \OwnPay\Service\PermissionService::countPermissions($tabKey, $tabData);
}

function hasPermission(array $permissions, string $module, string $action = 'view', string $adminType = 'staff'): bool
{
    return \OwnPay\Service\PermissionService::hasPermission($permissions, $module, $action, $adminType);
}

function canAccessPage(array $permissions, string $page, string $adminType = 'staff'): bool
{
    return \OwnPay\Service\PermissionService::canAccessPage($permissions, $page, $adminType);
}

// ── Filesystem → FilesystemService / ImageService ────────────────────

function generateRandomFilename(string $extension): string
{
    return \OwnPay\Service\ImageService::generateRandomFilename($extension);
}

function uploadImage(array $file, int $max_file_size): string
{
    return \OwnPay\Service\ImageService::upload($file, $max_file_size);
}

function deleteImage(string $file): string
{
    return \OwnPay\Service\ImageService::delete($file);
}

function deleteFolder(string $dir): void
{
    \OwnPay\Service\FilesystemService::deleteFolder($dir);
}

function copyFolder(string $src, string $dst): void
{
    \OwnPay\Service\FilesystemService::copyFolder($src, $dst);
}

function zipFolder(string $source, string $zipFile): bool
{
    return \OwnPay\Service\FilesystemService::zipFolder($source, $zipFile);
}

function runSql(string $file): bool
{
    return \OwnPay\Service\FilesystemService::runSql($file);
}

function backupDatabasePDO(string $backupPath): bool
{
    return \OwnPay\Service\FilesystemService::backupDatabasePDO($backupPath);
}

function extractUpdate(string $zipFile, string $destination): bool
{
    return \OwnPay\Service\FilesystemService::extractUpdate($zipFile, $destination);
}

// ── Gateway API → GatewayApiService / TransactionService ─────────────

function op_set_lang(string $lang): void
{
    \OwnPay\Service\GatewayApiService::op_set_lang($lang);
}

function op_site_address(): string
{
    return \OwnPay\Service\GatewayApiService::op_site_address();
}

function op_callback_url(): string
{
    return \OwnPay\Service\GatewayApiService::op_callback_url();
}

function op_ipn_url(string $gatewayid): string
{
    return \OwnPay\Service\GatewayApiService::op_ipn_url($gatewayid);
}

function op_check_transaction(string $ppid = ''): bool
{
    return \OwnPay\Service\GatewayApiService::op_check_transaction($ppid);
}

function op_check_transaction_id(string $trxid = ''): bool
{
    return \OwnPay\Service\GatewayApiService::op_check_transaction_id($trxid);
}

function op_set_transaction_status(string $transactionid, string $status = '', string $gateway_id = '', string $trxid = '', array $source_info = []): bool
{
    return \OwnPay\Service\TransactionService::op_set_transaction_status($transactionid, $status, $gateway_id, $trxid, $source_info);
}

function op_checkout_address(string $paymentid = ''): string
{
    return \OwnPay\Service\GatewayApiService::op_checkout_address($paymentid);
}

function op_hexToRgba(string $hex, float|int $opacity = 1): string
{
    return \OwnPay\Service\GatewayApiService::op_hexToRgba($hex, $opacity);
}

function op_assets(string $position = ''): void
{
    \OwnPay\Service\GatewayApiService::op_assets($position);
}

// ── PDF → PdfService ─────────────────────────────────────────────────

function op_downloadReceiptPDF(array $data = []): void
{
    \OwnPay\Service\PdfService::op_downloadReceiptPDF($data);
}

function sectionTitle(object $pdf, string $title): void
{
    \OwnPay\Service\PdfService::sectionTitle($pdf, $title);
}

function infoRow(object $pdf, string $label, string $value): void
{
    \OwnPay\Service\PdfService::infoRow($pdf, $label, $value);
}

// ── Gateway Renderer → GatewayRendererService ────────────────────────

function op_gateways(string $tab = '', array $data = []): array
{
    return \OwnPay\Service\GatewayRendererService::op_gateways($tab, $data);
}

function op_gateway_info(string $gateway_id = '', array $data = []): array
{
    return \OwnPay\Service\GatewayRendererService::op_gateway_info($gateway_id, $data);
}

function op_gateway_render(string $gateway_id = '', array $data = []): mixed
{
    return \OwnPay\Service\GatewayRendererService::op_gateway_render($gateway_id, $data);
}

function op_renderFormFields(string $type = '', array $data = []): void
{
    \OwnPay\Service\GatewayRendererService::op_renderFormFields($type, $data);
}

// ── Hook System → EventManager (sole hook bus) ───────────────────────

function add_action(string $hook, callable $callback, int $priority = 10): void
{
    \OwnPay\Event\EventManager::getInstance()->addAction($hook, $callback, $priority);
}

function do_action(string $hook, mixed ...$args): void
{
    \OwnPay\Event\EventManager::getInstance()->doAction($hook, ...$args);
}

function add_filter(string $hook, callable $callback, int $priority = 10): void
{
    \OwnPay\Event\EventManager::getInstance()->addFilter($hook, $callback, $priority);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return \OwnPay\Event\EventManager::getInstance()->applyFilters($hook, $value, ...$args);
}
