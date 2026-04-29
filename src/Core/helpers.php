<?php

declare(strict_types=1);

/**
 * OwnPay Global Helper Functions
 *
 * Thin convenience wrappers around OwnPay service classes.
 * Loaded via Composer autoload-files for availability in templates.
 *
 * @package OwnPay\Core
 */

// ─── Input Sanitization ──────────────────────────────────────────────

if (!function_exists('sanitize_html')) {
    function sanitize_html(mixed $value): mixed
    {
        return \OwnPay\Service\System\InputSanitizer::html($value);
    }
}

if (!function_exists('clean_input')) {
    function clean_input(mixed $value): mixed
    {
        return \OwnPay\Service\System\InputSanitizer::trim($value);
    }
}

// ─── Environment ────────────────────────────────────────────────────

if (!function_exists('get_env')) {
    function get_env(string $option_name, string $brand_id = 'both'): string
    {
        return \OwnPay\Service\System\EnvironmentService::get($option_name, $brand_id);
    }
}

if (!function_exists('set_env')) {
    function set_env(string $option_name, string $value, string $brand_id = 'both'): string
    {
        return \OwnPay\Service\System\EnvironmentService::set($option_name, $value, $brand_id);
    }
}

// ─── URL & Routing ──────────────────────────────────────────────────

if (!function_exists('op_site_url')) {
    function op_site_url(string $type = 'Full'): string
    {
        return \OwnPay\Core\RouteHelper::siteUrl($type);
    }
}

if (!function_exists('getAdminPath')) {
    function getAdminPath(string $url): string
    {
        return \OwnPay\Core\RouteHelper::getAdminPath($url);
    }
}

if (!function_exists('getDomainValue')) {
    function getDomainValue(string $input): string|false
    {
        return \OwnPay\Core\RouteHelper::getDomainValue($input);
    }
}

if (!function_exists('addQueryParams')) {
    function addQueryParams(string $url, array $params = []): string
    {
        return \OwnPay\Core\RouteHelper::addQueryParams($url, $params);
    }
}

// ─── HTTP Request ───────────────────────────────────────────────────

if (!function_exists('getAuthorizationHeader')) {
    function getAuthorizationHeader(): ?string
    {
        return \OwnPay\Core\RequestHelper::getAuthorizationHeader();
    }
}

if (!function_exists('getUserDeviceInfo')) {
    function getUserDeviceInfo(): array
    {
        return \OwnPay\Core\RequestHelper::getUserDeviceInfo();
    }
}

// ─── Identity & Formatting ──────────────────────────────────────────

if (!function_exists('generateStrongPassword')) {
    function generateStrongPassword(int $length = 16): string
    {
        return \OwnPay\Core\FormattingHelper::generateStrongPassword($length);
    }
}

if (!function_exists('generateItemID')) {
    function generateItemID(int $length = 10, int $maxLength = 10): string
    {
        return \OwnPay\Core\FormattingHelper::generateItemID($length, $maxLength);
    }
}

if (!function_exists('getNameChars')) {
    function getNameChars(string $fullName, int $length = 2): string
    {
        return \OwnPay\Core\FormattingHelper::getNameChars($fullName, $length);
    }
}

if (!function_exists('resolveModuleLanguage')) {
    function resolveModuleLanguage(string $brandLanguage, array $supportedLanguages): string
    {
        return \OwnPay\Core\FormattingHelper::resolveModuleLanguage($brandLanguage, $supportedLanguages);
    }
}

if (!function_exists('buildLangArray')) {
    function buildLangArray(array $langText, ?string $language = 'en'): array
    {
        return \OwnPay\Core\FormattingHelper::buildLangArray($langText, $language);
    }
}

// ─── Date & Time ────────────────────────────────────────────────────

if (!function_exists('getCurrentDatetime')) {
    function getCurrentDatetime(string $format = 'Y-m-d H:i:s'): string
    {
        return \OwnPay\Service\System\DateTimeService::getCurrentDatetime($format);
    }
}

if (!function_exists('timeAgo')) {
    function timeAgo(string $datetime): string
    {
        return \OwnPay\Service\System\DateTimeService::timeAgo($datetime);
    }
}

if (!function_exists('convertUTCtoUserTZ')) {
    function convertUTCtoUserTZ(string $utc_time, string $user_tz = 'UTC', string $format = 'Y-m-d H:i:s'): string
    {
        return \OwnPay\Service\System\DateTimeService::convertUTCtoUserTZ($utc_time, $user_tz, $format);
    }
}

if (!function_exists('isExpired')) {
    function isExpired(string $expires_at): bool
    {
        return \OwnPay\Service\System\DateTimeService::isExpired($expires_at);
    }
}

if (!function_exists('dateformat')) {
    function dateformat(string $date, string $format = 'd/m/Y'): bool
    {
        return \OwnPay\Service\System\DateTimeService::dateformat($date, $format);
    }
}

// ─── Money (BC Math) ────────────────────────────────────────────────

if (!function_exists('money_sanitize')) {
    function money_sanitize(string|int|float|null $value): string
    {
        return \OwnPay\Service\Payment\CurrencyService::sanitize($value);
    }
}

if (!function_exists('money_add')) {
    function money_add(string|int|float $a, string|int|float $b, int $scale = 8): string
    {
        return \OwnPay\Service\Payment\CurrencyService::add($a, $b, $scale);
    }
}

if (!function_exists('money_sub')) {
    function money_sub(string|int|float $a, string|int|float $b, int $scale = 8): string
    {
        return \OwnPay\Service\Payment\CurrencyService::sub($a, $b, $scale);
    }
}

if (!function_exists('money_mul')) {
    function money_mul(string|int|float $a, string|int|float $b, int $scale = 8): string
    {
        return \OwnPay\Service\Payment\CurrencyService::mul($a, $b, $scale);
    }
}

if (!function_exists('money_div')) {
    function money_div(string|int|float $a, string|int|float $b, int $scale = 8): string
    {
        return \OwnPay\Service\Payment\CurrencyService::div($a, $b, $scale);
    }
}

if (!function_exists('money_round')) {
    function money_round(string|int|float $amount, int $decimals = 2): string
    {
        return \OwnPay\Service\Payment\CurrencyService::round($amount, $decimals);
    }
}

if (!function_exists('moneyToInt')) {
    function moneyToInt(string $amount, int $decimals = 2): int
    {
        return \OwnPay\Service\Payment\CurrencyService::toInt($amount, $decimals);
    }
}

if (!function_exists('intToMoney')) {
    function intToMoney(int $amount, int $decimals = 2): string
    {
        return \OwnPay\Service\Payment\CurrencyService::fromInt($amount, $decimals);
    }
}

if (!function_exists('verifyPaymentTolerance')) {
    function verifyPaymentTolerance(string $checkout, string $paid, string $tolerance): bool
    {
        return \OwnPay\Service\Payment\CurrencyService::verifyTolerance($checkout, $paid, $tolerance);
    }
}

// ─── Data Access ────────────────────────────────────────────────────

if (!function_exists('getData')) {
    function getData(string $tableName, string $condition = '', string $select = '* FROM', array $params = []): string
    {
        return \OwnPay\Service\System\CrudService::selectLegacy($tableName, $condition, $select, $params);
    }
}

if (!function_exists('insertData')) {
    function insertData(string $tableName, array $columns, array $values): bool
    {
        return \OwnPay\Service\System\CrudService::insertLegacy($tableName, $columns, $values);
    }
}

if (!function_exists('updateData')) {
    function updateData(string $tableName, array $columns, array $values, string $condition, array $whereParams = []): bool
    {
        return \OwnPay\Service\System\CrudService::updateLegacy($tableName, $columns, $values, $condition, $whereParams);
    }
}

if (!function_exists('deleteData')) {
    function deleteData(string $tableName, string $condition, array $whereParams = []): bool
    {
        return \OwnPay\Service\System\CrudService::deleteLegacy($tableName, $condition, $whereParams);
    }
}

// ─── Cookies & Session ──────────────────────────────────────────────

if (!function_exists('setsCookie')) {
    function setsCookie(string $cookieName, string $cookieValue, int $days = 365): void
    {
        \OwnPay\Service\Auth\AuthSessionService::setCookie($cookieName, $cookieValue, $days);
    }
}

if (!function_exists('getCookie')) {
    function getCookie(string $cookieName): ?string
    {
        return \OwnPay\Service\Auth\AuthSessionService::getCookie($cookieName);
    }
}

if (!function_exists('logoutCookie')) {
    function logoutCookie(): void
    {
        \OwnPay\Service\Auth\AuthSessionService::destroySession();
    }
}

// ─── Logging ────────────────────────────────────────────────────────

if (!function_exists('safe_log')) {
    function safe_log(string $message): void
    {
        \OwnPay\Service\System\Logger::app()->info($message);
    }
}

// ─── Permissions ────────────────────────────────────────────────────

if (!function_exists('permissionSchema')) {
    function permissionSchema(): array
    {
        return \OwnPay\Service\Auth\PermissionService::schema();
    }
}

if (!function_exists('countPermissions')) {
    function countPermissions(string $tabKey, array $tabData): int
    {
        return \OwnPay\Service\Auth\PermissionService::countPermissions($tabKey, $tabData);
    }
}

if (!function_exists('hasPermission')) {
    function hasPermission(array $permissions, string $module, string $action = 'view', string $adminType = 'staff'): bool
    {
        return \OwnPay\Service\Auth\PermissionService::hasPermission($permissions, $module, $action, $adminType);
    }
}

if (!function_exists('canAccessPage')) {
    function canAccessPage(array $permissions, string $page, string $adminType = 'staff'): bool
    {
        return \OwnPay\Service\Auth\PermissionService::canAccessPage($permissions, $page, $adminType);
    }
}

// ─── File Operations ────────────────────────────────────────────────

if (!function_exists('generateRandomFilename')) {
    function generateRandomFilename(string $extension): string
    {
        return \OwnPay\Service\System\FilesystemService::generateRandomFilename($extension);
    }
}

if (!function_exists('uploadImage')) {
    function uploadImage(array $file, int $max_file_size): string
    {
        return \OwnPay\Service\System\ImageService::upload($file, $max_file_size);
    }
}

if (!function_exists('deleteImage')) {
    function deleteImage(string $file): string
    {
        return \OwnPay\Service\System\ImageService::delete($file);
    }
}

if (!function_exists('deleteFolder')) {
    function deleteFolder(string $dir): void
    {
        \OwnPay\Service\System\FilesystemService::deleteFolder($dir);
    }
}

if (!function_exists('copyFolder')) {
    function copyFolder(string $src, string $dst): void
    {
        \OwnPay\Service\System\FilesystemService::copyFolder($src, $dst);
    }
}

if (!function_exists('zipFolder')) {
    function zipFolder(string $source, string $zipFile): bool
    {
        return \OwnPay\Service\System\FilesystemService::zipFolder($source, $zipFile);
    }
}

// ─── Gateway Functions ──────────────────────────────────────────────

if (!function_exists('op_gateways')) {
    function op_gateways(string $tab = '', array $data = []): array
    {
        return \OwnPay\Service\Payment\GatewayRendererService::getGateways($tab, $data);
    }
}

if (!function_exists('op_gateway_info')) {
    function op_gateway_info(string $gateway_id = '', array $data = []): array
    {
        return \OwnPay\Service\Payment\GatewayRendererService::getGatewayInfo($gateway_id, $data);
    }
}

if (!function_exists('op_gateway_render')) {
    function op_gateway_render(string $gateway_id = '', array $data = []): mixed
    {
        return \OwnPay\Service\Payment\GatewayRendererService::render($gateway_id, $data);
    }
}

if (!function_exists('op_renderFormFields')) {
    function op_renderFormFields(string $type = '', array $data = []): void
    {
        \OwnPay\Service\Payment\GatewayRendererService::renderFormFields($type, $data);
    }
}

// ─── Checkout Helpers ───────────────────────────────────────────────

if (!function_exists('op_site_address')) {
    function op_site_address(): string
    {
        return \OwnPay\Core\RouteHelper::siteUrl('fulldomain');
    }
}

if (!function_exists('op_callback_url')) {
    function op_callback_url(): string
    {
        return \OwnPay\Core\RouteHelper::siteUrl('fulldomain') . '/' . (get_env('geneal-application-settings-paymentPath') ?: 'payment');
    }
}

if (!function_exists('op_ipn_url')) {
    function op_ipn_url(string $gatewayid): string
    {
        return \OwnPay\Core\RouteHelper::siteUrl('fulldomain') . '/ipn/' . $gatewayid;
    }
}

// ─── Transaction ────────────────────────────────────────────────────

if (!function_exists('op_check_transaction')) {
    function op_check_transaction(string $ppid = ''): bool
    {
        return \OwnPay\Service\Payment\TransactionService::checkTransaction($ppid);
    }
}

if (!function_exists('op_check_transaction_id')) {
    function op_check_transaction_id(string $trxid = ''): bool
    {
        return \OwnPay\Service\Payment\TransactionService::checkTransactionId($trxid);
    }
}

if (!function_exists('op_set_transaction_status')) {
    function op_set_transaction_status(string $transactionid, string $status = '', string $gateway_id = '', string $trxid = '', array $source_info = []): bool
    {
        return \OwnPay\Service\Payment\TransactionService::setTransactionStatus($transactionid, $status, $gateway_id, $trxid, $source_info);
    }
}

if (!function_exists('op_checkout_address')) {
    function op_checkout_address(string $paymentid = ''): string
    {
        return \OwnPay\Core\RouteHelper::siteUrl('fulldomain') . '/' . (get_env('geneal-application-settings-paymentPath') ?: 'payment') . '/' . $paymentid;
    }
}

// ─── UI Helpers ─────────────────────────────────────────────────────

if (!function_exists('op_hexToRgba')) {
    function op_hexToRgba(string $hex, float|int $opacity = 1): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgba({$r}, {$g}, {$b}, {$opacity})";
    }
}

if (!function_exists('op_set_lang')) {
    function op_set_lang(string $lang): void
    {
        $_SESSION['op_lang'] = $lang;
    }
}

// ─── IPN ────────────────────────────────────────────────────────────

if (!function_exists('sendIPN')) {
    function sendIPN(string $url, array $payload): int
    {
        return \OwnPay\Service\Payment\WebhookService::send($url, $payload);
    }
}

if (!function_exists('sendIPNMulti')) {
    function sendIPNMulti(array $jobs): array
    {
        return \OwnPay\Service\Payment\WebhookService::sendMulti($jobs);
    }
}

// ─── SMS ────────────────────────────────────────────────────────────

if (!function_exists('senderWhitelist')) {
    function senderWhitelist(?string $sender = null, ?string $providerKey = null, string $mode = 'provider', ?string $providerName = null): array|false
    {
        return \OwnPay\Service\Payment\MfsService::senderWhitelist($sender, $providerKey, $mode, $providerName);
    }
}

if (!function_exists('MFSMessageVerified')) {
    function MFSMessageVerified(string $mfs, string $message): array|false
    {
        return \OwnPay\Service\Payment\MfsService::messageVerified($mfs, $message);
    }
}

if (!function_exists('reconcileByLongestChain')) {
    function reconcileByLongestChain(string $device_id, string $sender_key, string $type): void
    {
        \OwnPay\Service\Payment\ReconciliationService::reconcileByLongestChain($device_id, $sender_key, $type);
    }
}

// ─── Module Security ────────────────────────────────────────────────

if (!function_exists('safeModulePath')) {
    function safeModulePath(string $slug, string $baseDir, string $suffix = '/class.php'): string|false
    {
        return \OwnPay\Service\System\FilesystemService::safeModulePath($slug, $baseDir, $suffix);
    }
}

if (!function_exists('getParam')) {
    function getParam(array $params, string $key): ?string
    {
        return isset($params[$key]) && $params[$key] !== '' ? (string) $params[$key] : null;
    }
}

// ─── Database & System ──────────────────────────────────────────────

if (!function_exists('runSql')) {
    function runSql(string $file): bool
    {
        return \OwnPay\Service\System\FilesystemService::runSql($file);
    }
}

if (!function_exists('backupDatabasePDO')) {
    function backupDatabasePDO(string $backupPath): bool
    {
        return \OwnPay\Service\System\FilesystemService::backupDatabase($backupPath);
    }
}

if (!function_exists('extractUpdate')) {
    function extractUpdate(string $zipFile, string $destination): bool
    {
        return \OwnPay\Service\System\UpdaterService::extractUpdate($zipFile, $destination);
    }
}

// ─── PDF ────────────────────────────────────────────────────────────

if (!function_exists('op_downloadReceiptPDF')) {
    function op_downloadReceiptPDF(array $data = []): void
    {
        \OwnPay\Service\System\PdfService::downloadReceipt($data);
    }
}

if (!function_exists('sectionTitle')) {
    function sectionTitle(object $pdf, string $title): void
    {
        \OwnPay\Service\System\PdfService::sectionTitle($pdf, $title);
    }
}

if (!function_exists('infoRow')) {
    function infoRow(object $pdf, string $label, string $value): void
    {
        \OwnPay\Service\System\PdfService::infoRow($pdf, $label, $value);
    }
}

// ─── Assets ─────────────────────────────────────────────────────────

if (!function_exists('op_assets')) {
    function op_assets(string $position = ''): void
    {
        \OwnPay\Event\EventManager::getInstance()->doAction('op.assets.' . $position);
    }
}

// ─── EventManager Wrappers ──────────────────────────────────────────

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        \OwnPay\Event\EventManager::getInstance()->addAction($hook, $callback, $priority);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        \OwnPay\Event\EventManager::getInstance()->doAction($hook, ...$args);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10): void
    {
        \OwnPay\Event\EventManager::getInstance()->addFilter($hook, $callback, $priority);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return \OwnPay\Event\EventManager::getInstance()->applyFilters($hook, $value, ...$args);
    }
}
