<?php
declare(strict_types=1);

if (!defined('ANIRBANPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

if (date_default_timezone_get() !== 'UTC') {
    date_default_timezone_set('UTC');
}

$ap_functions_loaded = true;

function ap_site_url($type = "Full")
{
    // Detect protocol
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";

    // Full host with subdomain
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Request URI (path after domain)
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';

    // Extract main domain
    $hostParts = explode('.', $host);
    $numParts = count($hostParts);

    if ($numParts >= 2) {
        // Handles domains like example.com or sub.example.com
        $mainDomain = $hostParts[$numParts - 2] . '.' . $hostParts[$numParts - 1];
    } else {
        $mainDomain = $host; // fallback
    }

    switch (strtolower($type)) {
        case "fulldomain":
            return $protocol . $host; // subdomain + main domain
        case "maindomain":
            return $mainDomain; // main domain only
        case "full":
        default:
            return $protocol . $host . $requestUri; // full URL
    }
}

function getAdminPath($url)
{
    // Remove query string
    $url = explode('?', $url)[0];

    // Find position of admin/
    $pos = strpos($url, 'admin/');
    if ($pos === false)
        return ''; // admin/ not found

    // Get everything after admin/
    $path = substr($url, $pos + strlen('admin/'));

    // Remove leading/trailing slashes
    $path = trim($path, '/');

    return $path;
}

function getAuthorizationHeader()
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['MHS-AnirbanPay-API-KEY'])) {
            return trim($headers['MHS-AnirbanPay-API-KEY']);
        }
    }

    foreach ($_SERVER as $key => $value) {
        if (stripos($key, 'HTTP_MHS_AnirbanPay_API_KEY') !== false) {
            return trim($value);
        }
    }

    return null;
}

function connectDatabase()
{
    global $db_host, $db_user, $db_pass, $db_name;

    try {
        // Build DSN
        $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";

        // Create PDO instance
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,      // Throw exceptions on error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Fetch associative arrays
            PDO::ATTR_EMULATE_PREPARES => false               // Use native prepared statements
        ]);

        return $pdo;
    } catch (PDOException $e) {
        error_log('[AnirbanPay] Database connection failed: ' . $e->getMessage());
        die('A database connection error occurred. Please try again later.');
    }
}

function timeAgo($datetime)
{
    return \AnirbanPay\Service\DateTimeService::timeAgo($datetime);
}

function getCurrentDatetime($format = 'Y-m-d H:i:s')
{
    return \AnirbanPay\Service\DateTimeService::getCurrentDatetime($format);
}

function getUserDeviceInfo()
{
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    if (preg_match('/mobile/i', $userAgent)) {
        $deviceType = "Mobile";
    } elseif (preg_match('/tablet/i', $userAgent)) {
        $deviceType = "Tablet";
    } else {
        $deviceType = "Desktop";
    }

    if (preg_match('/Windows/i', $userAgent)) {
        $os = "Windows";
    } elseif (preg_match('/Mac/i', $userAgent)) {
        $os = "Mac OS";
    } elseif (preg_match('/Linux/i', $userAgent)) {
        $os = "Linux";
    } elseif (preg_match('/Android/i', $userAgent)) {
        $os = "Android";
    } elseif (preg_match('/iPhone|iPad/i', $userAgent)) {
        $os = "iOS";
    } else {
        $os = "Unknown OS";
    }

    if (preg_match('/MSIE|Trident/i', $userAgent)) {
        $browser = "Internet Explorer";
    } elseif (preg_match('/Firefox/i', $userAgent)) {
        $browser = "Firefox";
    } elseif (preg_match('/Chrome/i', $userAgent)) {
        $browser = "Chrome";
    } elseif (preg_match('/Safari/i', $userAgent)) {
        $browser = "Safari";
    } elseif (preg_match('/Opera|OPR/i', $userAgent)) {
        $browser = "Opera";
    } elseif (preg_match('/Edge/i', $userAgent)) {
        $browser = "Edge";
    } else {
        $browser = "Unknown Browser";
    }

    return [
        'ip_address' => $ipAddress,
        'device' => $deviceType,
        'os' => $os,
        'browser' => $browser
    ];
}

// Set a cookie securely (supports all panels)
function setsCookie($cookieName, $cookieValue, $days = 365)
{
    $expiryTime = time() + ($days * 24 * 60 * 60);

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;

    setcookie($cookieName, $cookieValue, [
        'expires' => $expiryTime,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Get the value of a cookie
function getCookie($cookieName)
{
    return $_COOKIE[$cookieName] ?? null;
}

// Logout: clear all cookies and destroy session
function logoutCookie()
{
    // Expire all cookies
    foreach ($_COOKIE as $name => $value) {
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    // Clear session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_unset();
    session_destroy();
}

/**
 * Secures string input against Stored XSS and basic sanitization.
 * Note: For SQL queries, always use PDO Parameterized Prepared Statements ($params array).
 */
/**
 * Sanitize a value for safe HTML output (XSS prevention).
 * Use this when displaying user input in HTML templates.
 */
function sanitize_html($value)
{
    if (is_array($value)) {
        return array_map('sanitize_html', $value);
    }
    if (is_string($value)) {
        return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $value;
}

/**
 * Clean input for use with parameterized queries.
 * Only trims whitespace — PDO handles escaping.
 */
function clean_input($value)
{
    if (is_array($value)) {
        return array_map('clean_input', $value);
    }
    if (is_string($value)) {
        return trim($value);
    }
    return $value;
}

/**
 * @deprecated Use sanitize_html() for HTML output or clean_input() for DB queries.
 * Kept for backwards compatibility during transition.
 */
function escape_string($value)
{
    return sanitize_html($value);
}

/**
 * PII-safe logging — sanitizes sensitive data before writing to error_log.
 */
function safe_log(string $message): void
{
    static $sanitizer = null;
    if ($sanitizer === null) {
        $sanitizer = new \AnirbanPay\Security\LogSanitizer();
    }
    error_log($sanitizer->sanitizeString($message));
}

function getData(string $tableName, string $condition = '', string $select = '* FROM', array $params = []): string
{
    $pdo = connectDatabase();

    $sql = "SELECT $select `$tableName` $condition";

    try {
        $stmt = $pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $pdoType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $pdoType);
        }

        $stmt->execute();

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Backwards compatibility: convert NULL to '--' for existing code that checks for '--'
        // TODO: Remove this block after all '--' checks are migrated to null-aware checks
        foreach ($data as &$row) {
            foreach ($row as $col => $val) {
                if (is_null($val)) {
                    $row[$col] = '--';
                }
            }
        }

        if ($data) {
            return json_encode(['status' => true, 'response' => $data]);
        } else {
            return json_encode(['status' => false, 'response' => []]);
        }

    } catch (PDOException $e) {
        error_log("getData PDO Error: " . $e->getMessage());
        return json_encode(['status' => false, 'response' => []]);
    }
}

function insertData($tableName, $columns, $values)
{
    $pdo = connectDatabase();

    try {
        $stmtColumns = $pdo->prepare("SHOW COLUMNS FROM `$tableName`");
        $stmtColumns->execute();
        $tableCols = $stmtColumns->fetchAll(PDO::FETCH_ASSOC);

        $finalColumns = [];
        $finalValues = [];
        $placeholders = [];

        $userData = array_combine($columns, $values);

        foreach ($tableCols as $col) {
            $colName = $col['Field'];

            if (strpos(strtolower($col['Extra']), 'auto_increment') !== false && !isset($userData[$colName])) {
                continue;
            }

            $finalColumns[] = $colName;
            $placeholders[] = ":val_$colName";

            if (isset($userData[$colName])) {
                $finalValues[$colName] = $userData[$colName];
            } else {
                if ($col['Default'] !== null) {
                    $finalValues[$colName] = $col['Default'];
                } else {
                    $finalValues[$colName] = null;
                }
            }
        }

        $sql = "INSERT INTO `$tableName` (" . implode(", ", $finalColumns) . ") VALUES (" . implode(", ", $placeholders) . ")";
        $stmt = $pdo->prepare($sql);

        foreach ($finalValues as $colName => $val) {
            $stmt->bindValue(":val_$colName", $val);
        }

        return $stmt->execute();

    } catch (PDOException $e) {
        error_log("Insert failed: " . $e->getMessage());
        return false;
    }
}

function updateData($tableName, $columns, $values, $condition, $whereParams = [])
{
    $pdo = connectDatabase();

    $setClauses = [];
    foreach ($columns as $index => $col) {
        $setClauses[] = "`$col` = :val$index";
    }
    $setString = implode(", ", $setClauses);

    $sql = "UPDATE `$tableName` SET $setString WHERE $condition";

    try {
        $stmt = $pdo->prepare($sql);

        foreach ($values as $index => $value) {
            if ($value === "" || is_null($value)) {
                $stmt->bindValue(":val$index", null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(":val$index", $value);
            }
        }

        // Bind WHERE parameters safely (SEC-01 fix)
        foreach ($whereParams as $key => $val) {
            $pdoType = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $val, $pdoType);
        }

        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("updateData PDO Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Phase 2.0 — Optimistic-lock–aware transaction update.
 *
 * Appends `AND version = :current_version` to the WHERE clause and
 * auto-increments `version` in the SET clause. Returns the number of
 * affected rows (0 = stale data / concurrent modification).
 *
 * @param string $tableName     Fully qualified table name (e.g. $db_prefix . 'transaction')
 * @param array  $columns       Columns to update
 * @param array  $values        Corresponding values
 * @param string $condition     WHERE clause WITHOUT the version guard (e.g. "ref = :ref")
 * @param array  $whereParams   Named WHERE parameters
 * @param int    $currentVersion The version read when the row was fetched
 * @return int   Number of affected rows (0 means the row was modified by another process)
 */
function optimisticTransactionUpdate(
    string $tableName,
    array $columns,
    array $values,
    string $condition,
    array $whereParams,
    int $currentVersion
): int {
    $pdo = connectDatabase();

    $setClauses = [];
    foreach ($columns as $index => $col) {
        $setClauses[] = "`$col` = :val$index";
    }
    // Auto-increment version
    $setClauses[] = "`version` = `version` + 1";
    $setString = implode(", ", $setClauses);

    // Append version guard to WHERE
    $sql = "UPDATE `$tableName` SET $setString WHERE $condition AND `version` = :_olv_version";

    try {
        $stmt = $pdo->prepare($sql);

        foreach ($values as $index => $value) {
            if ($value === "" || is_null($value)) {
                $value = "--";
            }
            $stmt->bindValue(":val$index", $value);
        }

        foreach ($whereParams as $key => $val) {
            $pdoType = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $val, $pdoType);
        }

        $stmt->bindValue(':_olv_version', $currentVersion, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("optimisticTransactionUpdate PDO Error: " . $e->getMessage());
        return 0;
    }
}

function deleteData($tableName, $condition, $whereParams = [])
{
    $pdo = connectDatabase(); // PDO connection

    $sql = "DELETE FROM `$tableName` WHERE $condition";

    try {
        $stmt = $pdo->prepare($sql);

        // Bind WHERE parameters safely (SEC-01 fix)
        foreach ($whereParams as $key => $val) {
            $pdoType = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $val, $pdoType);
        }

        return $stmt->execute(); // returns true/false
    } catch (PDOException $e) {
        error_log("deleteData PDO Error: " . $e->getMessage());
        return false;
    }
}

function limit_checker($tableName, $db_prefix)
{
    $pdo = connectDatabase();
    try {
        if ($tableName === 'transactions') {
            $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM `{$db_prefix}transaction` WHERE status = 'completed'");
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM `{$db_prefix}domain`");
        }
        return ((int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0)) + 1;
    } catch (PDOException $e) {
        error_log('limit_checker error: ' . $e->getMessage());
        return 1;
    }
}

function generateStrongPassword($length = 16)
{
    // SEC-10 fix: Use cryptographically secure random_int instead of str_shuffle/mt_rand
    $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lower = 'abcdefghijklmnopqrstuvwxyz';
    $digits = '0123456789';
    $symbols = '@#$%&!*^-_=+';
    $all = $upper . $lower . $digits . $symbols;

    // Guarantee at least one of each class
    $password = $upper[random_int(0, strlen($upper) - 1)];
    $password .= $lower[random_int(0, strlen($lower) - 1)];
    $password .= $digits[random_int(0, strlen($digits) - 1)];
    $password .= $symbols[random_int(0, strlen($symbols) - 1)];

    for ($i = 4; $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }

    // Fisher-Yates shuffle with secure randomness
    $chars = str_split($password);
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    return implode('', $chars);
}

function generateItemID($length = 10, $maxLength = 10)
{
    // Ensure length does not exceed max
    $length = ($length > $maxLength) ? $maxLength : $length;

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

    // Split name by spaces (remove extra spaces)
    $parts = array_values(array_filter(explode(' ', $fullName)));

    // If multiple words, use first + last
    if (count($parts) > 1) {
        $first = $parts[0];
        $last = end($parts);

        $result = strtoupper(
            substr($first, 0, 1) .
            substr($last, 0, max(0, $length - 1))
        );
    } else {
        // Single name
        $result = strtoupper(substr($parts[0], 0, $length));
    }

    return $result;
}

function moneyToInt(string $amount, int $decimals = 2): int
{
    return \AnirbanPay\Service\CurrencyService::moneyToInt($amount, $decimals);
}

function intToMoney(int $amount, int $decimals = 2): string
{
    return \AnirbanPay\Service\CurrencyService::intToMoney($amount, $decimals);
}

function money_sanitize(string|int|float|null $value): string
{
    return \AnirbanPay\Service\CurrencyService::money_sanitize($value);
}

function money_add($a, $b, int $scale = 8): string
{
    return \AnirbanPay\Service\CurrencyService::money_add($a, $b, $scale);
}

function money_sub($a, $b, int $scale = 8): string
{
    return \AnirbanPay\Service\CurrencyService::money_sub($a, $b, $scale);
}

function money_mul($a, $b, int $scale = 8): string
{
    return \AnirbanPay\Service\CurrencyService::money_mul($a, $b, $scale);
}

function money_div($a, $b, int $scale = 8): string
{
    return \AnirbanPay\Service\CurrencyService::money_div($a, $b, $scale);
}

function money_round($amount, int $decimals = 2): string
{
    return \AnirbanPay\Service\CurrencyService::money_round($amount, $decimals);
}

function verifyPaymentTolerance(string $checkout, string $paid, string $tolerance): bool
{
    return \AnirbanPay\Service\CurrencyService::verifyPaymentTolerance($checkout, $paid, $tolerance);
}

function dateformat($date, $format = 'd/m/Y')
{
    return \AnirbanPay\Service\DateTimeService::dateformat($date, $format);
}

function convertUTCtoUserTZ($utc_time, $user_tz = 'UTC', $format = 'Y-m-d H:i:s')
{
    return \AnirbanPay\Service\DateTimeService::convertUTCtoUserTZ($utc_time, $user_tz, $format);
}

function isExpired($expires_at)
{
    return \AnirbanPay\Service\DateTimeService::isExpired($expires_at);
}

function getParam(array $params, string $key): ?string
{
    if (!isset($params[$key]) || !is_string($params[$key])) {
        return null;
    }

    $value = trim($params[$key]);
    if ($value === '')
        return null;

    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $value)) {
        return null;
    }

    return escape_string($value);
}

function getDomainValue($input)
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

function sendIPN(string $url, array $payload)
{
    return \AnirbanPay\Service\NotificationService::sendIPN($url, $payload);
}

function sendIPNMulti(array $jobs)
{
    return \AnirbanPay\Service\NotificationService::sendIPNMulti($jobs);
}

function senderWhitelist(?string $sender = null, ?string $providerKey = null, string $mode = 'provider', ?string $providerName = null)
{
    return \AnirbanPay\Service\MfsService::senderWhitelist($sender, $providerKey, $mode, $providerName);
}


function MFSMessageVerified(string $mfs, string $message)
{
    return \AnirbanPay\Service\MfsService::MFSMessageVerified($mfs, $message);
}

function reconcileByLongestChain($device_id, $sender_key, $type)
{
    return \AnirbanPay\Service\MfsService::reconcileByLongestChain($device_id, $sender_key, $type);
}

function permissionSchema()
{
    return \AnirbanPay\Service\PermissionService::permissionSchema();
}

function countPermissions($tabKey, $tabData)
{
    return \AnirbanPay\Service\PermissionService::countPermissions($tabKey, $tabData);
}
function hasPermission($permissions, $module, $action = 'view', $adminType = 'staff')
{
    return \AnirbanPay\Service\PermissionService::hasPermission($permissions, $module, $action, $adminType);
}

function canAccessPage($permissions, $page, $adminType = 'staff')
{
    return \AnirbanPay\Service\PermissionService::canAccessPage($permissions, $page, $adminType);
}

function get_env($option_name, $brand_id = 'both')
{
    global $db_prefix;

    $option_name = escape_string($option_name);
    $brand_id = escape_string($brand_id);

    $params = [':brand_id' => $brand_id, ':option_name' => $option_name];

    $response_env = json_decode(getData($db_prefix . 'env', 'WHERE brand_id = :brand_id AND option_name = :option_name', '* FROM', $params), true);
    if ($response_env['status'] == true) {
        $value = $response_env['response'][0]['value'];

        if ($value == '--') {
            $value = '';
        }
    } else {
        $columns = ['brand_id', 'option_name', 'value', 'created_date', 'updated_date'];
        $values = [$brand_id, $option_name, '--', getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

        insertData($db_prefix . 'env', $columns, $values);

        $value = '';
    }

    return $value;
}

function set_env($option_name, $value, $brand_id = 'both')
{
    global $db_prefix;

    $option_name = escape_string($option_name);
    $value = escape_string($value);
    $brand_id = escape_string($brand_id);

    $params = [':brand_id' => $brand_id, ':option_name' => $option_name];

    $response_env = json_decode(getData($db_prefix . 'env', 'WHERE brand_id = :brand_id AND option_name = :option_name', '* FROM', $params), true);
    if ($response_env['status'] == true) {
        $columns = ['brand_id', 'value', 'updated_date'];
        $values = [$brand_id, $value, getCurrentDatetime('Y-m-d H:i:s')];
        $condition = "id = :where_id";
        $whereParams = [':where_id' => $response_env['response'][0]['id']];

        updateData($db_prefix . 'env', $columns, $values, $condition, $whereParams);
    } else {
        $columns = ['brand_id', 'option_name', 'value', 'created_date', 'updated_date'];
        $values = [$brand_id, $option_name, $value, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

        insertData($db_prefix . 'env', $columns, $values);
    }

    return $value;
}

function generateRandomFilename($extension)
{
    return \AnirbanPay\Service\ImageService::generateRandomFilename($extension);
}

function uploadImage($file, $max_file_size)
{
    return \AnirbanPay\Service\ImageService::upload($file, $max_file_size);
}

function deleteImage($file)
{
    return \AnirbanPay\Service\ImageService::delete($file);
}


function deleteFolder($dir)
{
    return \AnirbanPay\Service\FilesystemService::deleteFolder($dir);
}

function copyFolder($src, $dst)
{
    return \AnirbanPay\Service\FilesystemService::copyFolder($src, $dst);
}

function zipFolder($source, $zipFile)
{
    return \AnirbanPay\Service\FilesystemService::zipFolder($source, $zipFile);
}

function runSql($file)
{
    return \AnirbanPay\Service\FilesystemService::runSql($file);
}

function backupDatabasePDO($backupPath)
{
    return \AnirbanPay\Service\FilesystemService::backupDatabasePDO($backupPath);
}

function extractUpdate($zipFile, $destination)
{
    return \AnirbanPay\Service\FilesystemService::extractUpdate($zipFile, $destination);
}

function addQueryParams($url, $params = [])
{
    // Parse existing URL
    $parsedUrl = parse_url($url);

    // Get existing query params (if any)
    $existingParams = [];
    if (!empty($parsedUrl['query'])) {
        parse_str($parsedUrl['query'], $existingParams);
    }

    // Merge new params
    $finalParams = array_merge($existingParams, $params);

    // Rebuild query string
    $queryString = http_build_query($finalParams);

    // Rebuild full URL
    $baseUrl =
        ($parsedUrl['scheme'] ?? '') . ($parsedUrl['scheme'] ? '://' : '') .
        ($parsedUrl['host'] ?? '') .
        ($parsedUrl['path'] ?? '');

    return $baseUrl . '?' . $queryString;
}

function ap_set_lang($lang)
{
    return \AnirbanPay\Service\GatewayApiService::ap_set_lang($lang);
}

function ap_site_address()
{
    return \AnirbanPay\Service\GatewayApiService::ap_site_address();
}

function ap_callback_url()
{
    return \AnirbanPay\Service\GatewayApiService::ap_callback_url();
}

function ap_ipn_url($gatewayid)
{
    return \AnirbanPay\Service\GatewayApiService::ap_ipn_url($gatewayid);
}

function ap_check_transaction($ppid = '')
{
    return \AnirbanPay\Service\GatewayApiService::ap_check_transaction($ppid);
}

function ap_check_transaction_id($trxid = '')
{
    return \AnirbanPay\Service\GatewayApiService::ap_check_transaction_id($trxid);
}

function ap_set_transaction_status($transactionid, $status = '', $gateway_id = '', $trxid = '', $source_info = [])
{
    return \AnirbanPay\Service\TransactionService::ap_set_transaction_status($transactionid, $status, $gateway_id, $trxid, $source_info);
}

function ap_checkout_address($paymentid = '')
{
    return \AnirbanPay\Service\GatewayApiService::ap_checkout_address($paymentid);
}

function ap_hexToRgba($hex, $opacity = 1)
{
    return \AnirbanPay\Service\GatewayApiService::ap_hexToRgba($hex, $opacity);
}

function ap_assets($position = '')
{
    return \AnirbanPay\Service\GatewayApiService::ap_assets($position);
}

function ap_downloadReceiptPDF($data = [])
{
    return \AnirbanPay\Service\PdfService::ap_downloadReceiptPDF($data);
}

function sectionTitle($pdf, $title)
{
    return \AnirbanPay\Service\PdfService::sectionTitle($pdf, $title);
}

function infoRow($pdf, $label, $value)
{
    return \AnirbanPay\Service\PdfService::infoRow($pdf, $label, $value);
}

function resolveModuleLanguage($brandLanguage, array $supportedLanguages)
{
    if (!empty($_SESSION['ui_language'])) {
        $sessionLang = $_SESSION['ui_language'];
        if (isset($supportedLanguages[$sessionLang])) {
            return $sessionLang;
        }
    }

    if (isset($supportedLanguages[$brandLanguage])) {
        return $brandLanguage;
    }

    return array_key_first($supportedLanguages);
}

function buildLangArray(array $langText, ?string $language = 'en')
{
    $lang = [];

    foreach ($langText as $key => $translations) {
        $lang[$key] = $translations[$language]
            ?? reset($translations);
    }

    return $lang;
}

function ap_gateways($tab = '', $data = [])
{
    return \AnirbanPay\Service\GatewayRendererService::ap_gateways($tab, $data);
}

function ap_gateway_info($gateway_id = '', $data = [])
{
    return \AnirbanPay\Service\GatewayRendererService::ap_gateway_info($gateway_id, $data);
}

function ap_gateway_render($gateway_id = '', $data = [])
{
    return \AnirbanPay\Service\GatewayRendererService::ap_gateway_render($gateway_id, $data);
}

function ap_renderFormFields(string $type = '', array $data = [])
{
    return \AnirbanPay\Service\GatewayRendererService::ap_renderFormFields($type, $data);
}

$GLOBALS['__actions'] = [];
$GLOBALS['__filters'] = [];

function add_action(string $hook, callable $callback, int $priority = 10)
{
    $GLOBALS['__actions'][$hook][$priority][] = $callback;
}

function do_action(string $hook, ...$args)
{
    if (empty($GLOBALS['__actions'][$hook])) {
        return;
    }

    ksort($GLOBALS['__actions'][$hook]);

    foreach ($GLOBALS['__actions'][$hook] as $callbacks) {
        foreach ($callbacks as $callback) {
            try {
                call_user_func_array($callback, $args);
            } catch (Throwable $e) {
                // prevent plugin crash
                error_log('Action error [' . $hook . ']: ' . $e->getMessage());
            }
        }
    }
}

function add_filter(string $hook, callable $callback, int $priority = 10)
{
    $GLOBALS['__filters'][$hook][$priority][] = $callback;
}

function apply_filters(string $hook, $value, ...$args)
{
    if (empty($GLOBALS['__filters'][$hook])) {
        return $value;
    }

    ksort($GLOBALS['__filters'][$hook]);

    foreach ($GLOBALS['__filters'][$hook] as $callbacks) {
        foreach ($callbacks as $callback) {
            try {
                $value = call_user_func($callback, $value, ...$args);
            } catch (Throwable $e) {
                error_log('Filter error [' . $hook . ']: ' . $e->getMessage());
            }
        }
    }

    return $value;
}

/*
add_filter('invoice.total', function ($total, $invoice) {
    return $total + 10;
});
add_action('invoice.updated', function ($invoice) {
    error_log('Wallet credited for invoice '.$invoice['id']);
});
*/

require_once __DIR__ . '/../../src/Database/Database.php';

