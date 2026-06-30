<?php
declare(strict_types=1);

namespace OwnPay\Controller\Install;

use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Support\DateHelper;

/**
 * Class InstallerController
 *
 * Installer Controller - multi-step wizard.
 * Requirements → DB → Admin → Settings → Done
 * Input validation, Argon2ID, .installed lockout.
 *
 * @package OwnPay\Controller\Install
 */
final class InstallerController
{
    /**
     * @var string The root directory of the application.
     */
    private string $rootDir;

    /**
     * @var string The path to the .installed marker file.
     */
    private string $markerFile;

    /**
     * @var bool|null Memoized result of the database installed-state probe.
     */
    private ?bool $dbProbeResult = null;

    /**
     * InstallerController constructor.
     */
    public function __construct()
    {
        $this->rootDir    = dirname(__DIR__, 3);
        $this->markerFile = $this->rootDir . '/storage/.installed';
    }

    /**
     * Renders the installation wizard step view.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTML view response.
     */
    public function show(Request $req): Response
    {
        if ($this->isInstalled($req)) {
            return Response::html($this->renderPhpTemplate('install/locked.php', []));
        }
        $stepQuery = $req->query('step', '1');
        $stepVal = (is_int($stepQuery) || is_string($stepQuery) || is_numeric($stepQuery)) ? (int) $stepQuery : 1;
        $step = max(1, min(4, $stepVal));

        // Prevent skipping steps - must complete prerequisites
        $tempEnv = $this->rootDir . '/storage/.env.temp';
        if ($step >= 3 && !file_exists($tempEnv)) {
            return Response::redirect('/install?step=2');
        }

        $data = match ($step) {
            1       => ['requirements' => $this->checkRequirements()],
            default => [],
        };
        $data['step'] = $step;
        $nonceVal = $req->getAttribute('csp_nonce');
        $data['csp_nonce'] = is_string($nonceVal) ? $nonceVal : '';
        return Response::html($this->renderPhpTemplate("install/step{$step}.php", $data));
    }

    /**
     * Tests DB connection and imports the schema.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response indicating success or failure.
     */
    /**
     * Tests DB connection and retrieves schema diagnostics without altering database structures.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response indicating success or failure.
     */
    public function testDatabase(Request $req): Response
    {
        if ($this->isInstalled($req)) {
            return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        }
        $body = $req->json();
        if (!is_array($body)) {
            $body = [];
        }
        $hostVal   = $body['host']   ?? null;
        $host      = is_string($hostVal) ? trim($hostVal) : 'localhost';
        $portVal   = $body['port'] ?? null;
        $port      = (is_int($portVal) || is_string($portVal) || is_numeric($portVal)) ? (int)$portVal : 3306;
        $nameVal   = $body['name']   ?? null;
        $name      = is_string($nameVal) ? trim($nameVal) : '';
        $userVal   = $body['user']   ?? null;
        $user      = is_string($userVal) ? trim($userVal) : '';
        $passVal   = $body['pass']   ?? null;
        $pass      = is_string($passVal) ? $passVal : '';
        $prefixVal = $body['prefix'] ?? null;
        $prefix    = is_string($prefixVal) ? trim($prefixVal) : 'op_';

        if (!$name || !$user) {
            return Response::json(['success' => false, 'error' => 'DB name and user required'], 422);
        }
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) {
            return Response::json(['success' => false, 'error' => 'Invalid database name - alphanumeric and underscores only'], 422);
        }
        if (!preg_match('/^[a-z0-9_]{1,30}$/i', $prefix)) {
            return Response::json(['success' => false, 'error' => 'Invalid prefix'], 422);
        }

        try {
            $pdo = new \PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            
            $mysqlVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $collationStmt = $pdo->query("SELECT @@collation_connection");
            $collation = $collationStmt ? $collationStmt->fetchColumn() : 'utf8mb4_unicode_ci';
            if ($collation === false) {
                $collation = 'utf8mb4_unicode_ci';
            }

            $dbExists = false;
            $tableCount = 0;
            
            $dbCheck = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
            $dbCheck->execute([$name]);
            if ($dbCheck->fetch()) {
                $dbExists = true;
                $pdo->exec("USE `{$name}`");
                $tablesStmt = $pdo->query("SHOW TABLES");
                if ($tablesStmt !== false) {
                    $tableCount = count($tablesStmt->fetchAll(\PDO::FETCH_COLUMN));
                }
            }

            return Response::json([
                'success' => true,
                'details' => [
                    'mysql_version' => $mysqlVersion,
                    'collation' => $collation,
                    'table_count' => $tableCount,
                    'exists' => $dbExists
                ]
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Access denied')) {
                $error = 'Access denied. Check your database username and password.';
            } elseif (str_contains($msg, 'Connection refused') || str_contains($msg, 'No such file')) {
                $error = 'Could not connect to database server. Check host and port.';
            } else {
                $error = 'Database connection failed. Verify your credentials and try again.';
            }
            return Response::json(['success' => false, 'error' => $error], 500);
        }
    }

    /**
     * Decoupled schema import - drops/overwrites table and executes DDL.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response indicating success or failure.
     */
    public function importSchema(Request $req): Response
    {
        if ($this->isInstalled($req)) {
            return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        }
        $body = $req->json();
        if (!is_array($body)) {
            $body = [];
        }
        $hostVal      = $body['host']   ?? null;
        $host         = is_string($hostVal) ? trim($hostVal) : 'localhost';
        $portVal      = $body['port'] ?? null;
        $port         = (is_int($portVal) || is_string($portVal) || is_numeric($portVal)) ? (int)$portVal : 3306;
        $nameVal      = $body['name']   ?? null;
        $name         = is_string($nameVal) ? trim($nameVal) : '';
        $userVal      = $body['user']   ?? null;
        $user         = is_string($userVal) ? trim($userVal) : '';
        $passVal      = $body['pass']   ?? null;
        $pass         = is_string($passVal) ? $passVal : '';
        $prefixVal    = $body['prefix'] ?? null;
        $prefix       = is_string($prefixVal) ? trim($prefixVal) : 'op_';
        $overwriteVal = $body['confirm_overwrite'] ?? null;
        $overwrite    = (is_int($overwriteVal) || is_string($overwriteVal) || is_numeric($overwriteVal)) ? (bool)$overwriteVal : false;

        if (!$name || !$user) {
            return Response::json(['success' => false, 'error' => 'DB name and user required'], 422);
        }
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) {
            return Response::json(['success' => false, 'error' => 'Invalid database name - alphanumeric and underscores only'], 422);
        }
        if (!preg_match('/^[a-z0-9_]{1,30}$/i', $prefix)) {
            return Response::json(['success' => false, 'error' => 'Invalid prefix'], 422);
        }

        try {
            $pdo = new \PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            
            $dbCheck = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
            $dbCheck->execute([$name]);
            if ($dbCheck->fetch()) {
                $pdo->exec("USE `{$name}`");
                $tablesStmt = $pdo->query("SHOW TABLES");
                if ($tablesStmt !== false) {
                    $existingTables = $tablesStmt->fetchAll(\PDO::FETCH_COLUMN);
                    if (count($existingTables) > 0 && !$overwrite) {
                        return Response::json(['success' => false, 'error' => 'Database contains existing tables. Overwrite not confirmed.'], 422);
                    }
                }
            }

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$name}`");

            $sqlPath = $this->rootDir . '/database/schema.sql';
            if (!file_exists($sqlPath)) {
                return Response::json(['success' => false, 'error' => 'Schema file missing'], 500);
            }
            $sql = file_get_contents($sqlPath);
            if ($sql === false || strlen($sql) < 10000) {
                return Response::json(['success' => false, 'error' => 'Schema integrity failed'], 500);
            }

            if ($prefix !== 'op_') {
                $sql = str_replace('`op_', "`{$prefix}", $sql);
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $existing = [];
            $showTablesStmt = $pdo->query("SHOW TABLES");
            if ($showTablesStmt !== false) {
                $existing = $showTablesStmt->fetchAll(\PDO::FETCH_COLUMN);
            }
            foreach ($existing as $table) {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            }

            $statements = $this->parseSqlStatements($sql);
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $env = 'DB_HOST=' . $this->envToken($host) . "\n"
                 . 'DB_PORT=' . $this->envToken((string) $port) . "\n"
                 . 'DB_NAME=' . $this->envToken($name) . "\n"
                 . 'DB_USER=' . $this->envToken($user) . "\n"
                 . 'DB_PASS=' . $this->envToken($pass) . "\n"
                 . 'DB_PREFIX=' . $this->envToken($prefix) . "\n";
            file_put_contents($this->rootDir . '/storage/.env.temp', $env, LOCK_EX);
            @chmod($this->rootDir . '/storage/.env.temp', 0640);

            return Response::json(['success' => true, 'message' => 'Schema imported successfully']);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => 'Import failed: ' . $this->sanitizeErrorMessage($e->getMessage())], 500);
        }
    }

    /**
     * Parses simple temp env file line by line without parse_ini_file limitations.
     *
     * @param string $path The path to the temp env file.
     * @return array<string, string> The parsed environment keys and values.
     */
    private function parseTempEnv(string $path): array
    {
        $vars = [];
        if (!file_exists($path)) {
            return $vars;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $vars;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1]);
                if (strlen($val) >= 2 && str_starts_with($val, '"') && str_ends_with($val, '"')) {
                    $val = (string) preg_replace('/\\\\(.)/s', '$1', substr($val, 1, -1));
                } elseif (strlen($val) >= 2 && str_starts_with($val, "'") && str_ends_with($val, "'")) {
                    $val = substr($val, 1, -1);
                }
                $vars[$key] = $val;
            }
        }
        return $vars;
    }

    /**
     * Creates the superadmin merchant account and seeds default system permissions.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response indicating success or failure.
     */
    public function createAdmin(Request $req): Response
    {
        if ($this->isInstalled($req)) {
            return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        }
        $body = $req->json();
        if (!is_array($body)) {
            $body = [];
        }
        $nameVal     = $body['name']     ?? '';
        $name        = trim(is_string($nameVal) ? $nameVal : '');
        $emailVal    = $body['email']    ?? '';
        $email       = trim(is_string($emailVal) ? $emailVal : '');
        $usernameVal = $body['username'] ?? '';
        $username    = trim(is_string($usernameVal) ? $usernameVal : '');
        $passwordVal = $body['password']      ?? '';
        $password    = is_string($passwordVal) ? $passwordVal : '';

        if (!$name || !$email || !$username || !$password) {
            return Response::json(['success' => false, 'error' => 'All fields required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['success' => false, 'error' => 'Invalid email'], 422);
        }
        if (strlen($password) < 8) {
            return Response::json(['success' => false, 'error' => 'Password min 8 chars'], 422);
        }

        $envFile = $this->rootDir . '/storage/.env.temp';
        if (!file_exists($envFile)) {
            return Response::json(['success' => false, 'error' => 'Complete DB step first'], 400);
        }

        try {
            $env = $this->parseTempEnv($envFile);
            if (empty($env)) {
                return Response::json(['success' => false, 'error' => 'Failed to parse database environment configuration.'], 500);
            }
            $dbHost = $env['DB_HOST'] ?? 'localhost';
            $dbPort = $env['DB_PORT'] ?? '3306';
            $dbName = $env['DB_NAME'] ?? 'ownpay';
            $dbUser = $env['DB_USER'] ?? 'root';
            $dbPass = $env['DB_PASS'] ?? '';
            $p      = $env['DB_PREFIX'] ?? 'op_';

            $pdo = new \PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                $dbUser, $dbPass
            );
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $now = DateHelper::now();

            $merchantUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
                random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
                random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
            );
            $hash = password_hash($password, PASSWORD_ARGON2ID);

            // 1. Insert the merchant
            $stmt = $pdo->prepare(
                "INSERT INTO {$p}merchants (uuid, name, slug, email, timezone, default_currency, status, created_at, updated_at)
                 VALUES (?,?,?,?,'Asia/Dhaka','BDT','active',?,?)"
            );
            $stmt->execute([$merchantUuid, 'OwnPay', 'own-pay', $email, $now, $now]);
            $merchantId = (int) $pdo->lastInsertId();

            // 2. Insert the owner role
            $stmt = $pdo->prepare(
                "INSERT INTO {$p}roles (merchant_id, name, slug, description, is_system, created_at) VALUES (?,?,?,?,1,?)"
            );
            $stmt->execute([$merchantId, 'Owner', 'owner', 'System owner role', $now]);
            $roleId = (int) $pdo->lastInsertId();

            // 3. Insert the superadmin user
            $stmt = $pdo->prepare(
                "INSERT INTO {$p}merchant_users (merchant_id, role_id, name, username, email, password_hash, is_superadmin, status, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,1,'active',?,?)"
            );
            $stmt->execute([$merchantId, $roleId, $name, $username, $email, $hash, $now, $now]);

            // 4. Seed default currencies
            $currencies = [
                ['BDT', 'Bangladeshi Taka',  '৳',  2],
                ['USD', 'US Dollar',         '$',  2],
                ['EUR', 'Euro',              '€',  2],
                ['GBP', 'British Pound',     '£',  2],
                ['INR', 'Indian Rupee',      '₹',  2],
            ];
            $cs = $pdo->prepare("INSERT IGNORE INTO {$p}currencies (code, name, symbol, decimal_places, status) VALUES (?,?,?,?,'active')");
            foreach ($currencies as $c) {
                $cs->execute($c);
            }

            // 5. Seed default permissions
            $permissions = [
                ['admin.access',          'Admin Access',             'system'],
                ['transactions.view',     'View Transactions',        'payments'],
                ['transactions.manage',   'Manage Transactions',      'payments'],
                ['invoices.view',         'View Invoices',            'payments'],
                ['invoices.manage',       'Manage Invoices',          'payments'],
                ['payment_links.view',    'View Payment Links',       'payments'],
                ['payment_links.manage',  'Manage Payment Links',     'payments'],
                ['customers.view',        'View Customers',           'people'],
                ['customers.manage',      'Manage Customers',         'people'],
                ['gateways.view',         'View Gateways',            'gateways'],
                ['gateways.manage',       'Manage Gateways',          'gateways'],
                ['brands.view',           'View Brands',              'people'],
                ['brands.manage',         'Manage Brands',            'people'],
                ['brands.access_all',     'Access All Brands view',   'people'],
                ['staff.view',            'View Staff',               'people'],
                ['staff.manage',          'Manage Staff',             'people'],
                ['settings.view',         'View Settings',            'system'],
                ['settings.manage',       'Manage Settings',          'system'],
                ['api_keys.view',         'View API Keys',            'developers'],
                ['api_keys.manage',       'Manage API Keys',          'developers'],
                ['sms.view',              'View SMS',                 'mobile'],
                ['sms.manage',            'Manage SMS',               'mobile'],
                ['devices.view',          'View Devices',             'mobile'],
                ['devices.manage',        'Manage Devices',           'mobile'],
                ['plugins.view',          'View Plugins',             'system'],
                ['plugins.manage',        'Manage Plugins',           'system'],
                ['domains.view',          'View Domains',             'system'],
                ['domains.manage',        'Manage Domains',           'system'],
                ['system.update',         'System Update',            'system'],
                ['system.audit',          'View Audit Log',           'system'],
                ['system.reports',        'View Reports',             'system'],
                ['system.balance',        'Balance Verification',     'system'],
            ];
            $ps = $pdo->prepare("INSERT IGNORE INTO {$p}permissions (slug, name, group_name) VALUES (?,?,?)");
            foreach ($permissions as $perm) {
                $ps->execute($perm);
            }

            // 6. Assign ALL permissions to the Owner role
            $allPerms = [];
            $allPermsStmt = $pdo->query("SELECT id FROM {$p}permissions");
            if ($allPermsStmt !== false) {
                $allPerms = $allPermsStmt->fetchAll(\PDO::FETCH_COLUMN);
            }
            $rps = $pdo->prepare("INSERT IGNORE INTO {$p}role_permissions (role_id, permission_id) VALUES (?,?)");
            foreach ($allPerms as $permId) {
                $rps->execute([$roleId, $permId]);
            }

            return Response::json(['success' => true]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate entry')) {
                $error = 'An admin with that email already exists. Use a different email.';
            } elseif (str_contains($msg, 'Access denied')) {
                $error = 'Database access denied. Please go back to Step 2.';
            } else {
                $error = 'Failed to create admin account. Please try again.';
            }
            return Response::json(['success' => false, 'error' => $error], 500);
        }
    }

    /**
     * Finalizes installation by creating .env file, writing default system settings, and writing lock file.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response indicating success or failure.
     */
    public function finalize(Request $req): Response
    {
        if ($this->isInstalled($req)) {
            return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        }
        $body = $req->json();
        if (!is_array($body)) {
            $body = [];
        }
        $appNameVal  = $body['app_name']  ?? null;
        $appName     = is_string($appNameVal) ? trim($appNameVal) : 'OwnPay';
        $currencyVal = $body['currency']  ?? null;
        $currency    = is_string($currencyVal) ? trim($currencyVal) : 'BDT';
        $timezoneVal = $body['timezone']  ?? null;
        $timezone    = is_string($timezoneVal) ? trim($timezoneVal) : 'Asia/Dhaka';

        $tempEnv  = $this->rootDir . '/storage/.env.temp';
        $finalEnv = $this->rootDir . '/.env';

        if (!file_exists($tempEnv)) {
            return Response::json(['success' => false, 'error' => 'Complete previous steps'], 400);
        }

        try {
            $appKey          = 'base64:' . base64_encode(random_bytes(32));
            $encryptionKey   = 'base64:' . base64_encode(random_bytes(32));
            $hmacKey         = bin2hex(random_bytes(32));
            $jwtSecret       = bin2hex(random_bytes(32));
            $auditHmacSecret = bin2hex(random_bytes(32));

            $dbEnv = $this->parseTempEnv($tempEnv);
            if (empty($dbEnv)) {
                return Response::json(['success' => false, 'error' => 'Database config corrupted. Please go back to Step 2.'], 500);
            }

            $httpHostRaw = $req->server('HTTP_HOST') ?: 'localhost';
            $httpHost = preg_match('/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$/', $httpHostRaw) === 1 ? $httpHostRaw : 'localhost';
            $scheme = ($req->server('HTTPS') === 'on' || $req->server('HTTP_X_FORWARDED_PROTO') === 'https') ? 'https' : 'http';
            $appUrl = "{$scheme}://{$httpHost}";
            $appDomain = parse_url($appUrl, PHP_URL_HOST) ?: $httpHost;

            $examplePath = $this->rootDir . '/.env.example';
            if (!file_exists($examplePath)) {
                return Response::json(['success' => false, 'error' => '.env.example file missing'], 500);
            }
            $exampleContent = file_get_contents($examplePath);
            if ($exampleContent === false) {
                return Response::json(['success' => false, 'error' => 'Failed to read .env.example'], 500);
            }

            $replacements = [
                'APP_NAME' => $this->envToken($appName),
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_URL' => $this->envToken($appUrl),
                'APP_DOMAIN' => $this->envToken($appDomain),
                'APP_TIMEZONE' => $this->envToken($timezone),
                'APP_CURRENCY' => $this->envToken($currency),

                'DB_HOST' => $this->envToken($dbEnv['DB_HOST'] ?? 'localhost'),
                'DB_PORT' => $this->envToken($dbEnv['DB_PORT'] ?? '3306'),
                'DB_NAME' => $this->envToken($dbEnv['DB_NAME'] ?? 'ownpay'),
                'DB_USER' => $this->envToken($dbEnv['DB_USER'] ?? 'root'),
                'DB_PASS' => $this->envToken($dbEnv['DB_PASS'] ?? ''),
                'DB_PREFIX' => $this->envToken($dbEnv['DB_PREFIX'] ?? 'op_'),

                'APP_KEY' => $appKey,
                'ENCRYPTION_KEY' => $encryptionKey,
                'HMAC_KEY' => $hmacKey,
                'JWT_SECRET' => $jwtSecret,
                'AUDIT_HMAC_SECRET' => $auditHmacSecret,

                'CACHE_DRIVER' => 'file',
                'QUEUE_DRIVER' => 'file',
            ];

            $lines = explode("\n", str_replace("\r\n", "\n", $exampleContent));
            foreach ($lines as $idx => $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                
                if (preg_match('/^([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $matches)) {
                    $key = $matches[1];
                    $valAndComment = $matches[2];
                    
                    if (array_key_exists($key, $replacements)) {
                        $newValue = $replacements[$key];
                        $comment = '';
                        if (preg_match('/\s*(#.*)$/', $valAndComment, $commentMatches)) {
                            $comment = ' ' . $commentMatches[1];
                        }
                        $lines[$idx] = "{$key}={$newValue}{$comment}";
                    }
                }
            }
            $finalContent = implode("\n", $lines);
            
            file_put_contents($finalEnv, $finalContent, LOCK_EX);
            @chmod($finalEnv, 0640);

            $dbHost = $dbEnv['DB_HOST'] ?? 'localhost';
            $dbPort = $dbEnv['DB_PORT'] ?? '3306';
            $dbName = $dbEnv['DB_NAME'] ?? 'ownpay';
            $dbUser = $dbEnv['DB_USER'] ?? 'root';
            $dbPass = $dbEnv['DB_PASS'] ?? '';
            $p      = $dbEnv['DB_PREFIX'] ?? 'op_';

            $pdo = new \PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                $dbUser, $dbPass
            );
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $seeds = [
                ['general',  'app_name',        $appName,                  'string'],
                ['general',  'timezone',        $timezone,                 'string'],
                ['general',  'currency',        $currency,                 'string'],
                ['general',  'active_theme',    'own-pay',                 'string'],
                ['general',  'version',         '0.1.0',                   'string'],
                ['branding', 'site_name',       $appName,                  'string'],
                ['branding', 'site_logo',       '',                        'string'],
                ['branding', 'site_favicon',    '',                        'string'],
                ['branding', 'primary_color',   '#6366f1',               'string'],
                ['branding', 'footer_text',     "\u00a9 2025 {$appName}",  'string'],
                ['mail',     'driver',          'smtp',                    'string'],
                ['mail',     'from_address',    '',                        'string'],
                ['mail',     'from_name',       $appName,                  'string'],
                ['payment',  'default_gateway', '',                        'string'],
                ['payment',  'success_url',     '',                        'string'],
                ['payment',  'cancel_url',      '',                        'string'],
                ['general',  'base_currency',   $currency,                 'string'],
            ];
            $stmt = $pdo->prepare("INSERT IGNORE INTO {$p}system_settings (group_name, key_name, value, type) VALUES (?,?,?,?)");
            foreach ($seeds as $s) {
                $stmt->execute($s);
            }

            // Bootstrap languages folder and copy master en.json
            $languagesDir = $this->rootDir . '/storage/languages';
            if (!is_dir($languagesDir)) {
                @mkdir($languagesDir, 0755, true);
            }
            $masterEn = $this->rootDir . '/config/languages/en.json';
            $destEn = $languagesDir . '/en.json';
            if (file_exists($masterEn)) {
                @copy($masterEn, $destEn);
                @chmod($destEn, 0664);
            }

            file_put_contents($this->markerFile, "Installed: " . DateHelper::iso() . "\nVersion: 0.1.0\n", LOCK_EX);
            @chmod($this->markerFile, 0640);
            @unlink($tempEnv);

            return Response::json(['success' => true, 'message' => 'Installation complete']);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Access denied')) {
                $error = 'Database access denied. Please go back to Step 2 and reconfigure.';
            } else {
                $error = 'Installation failed. Please check your database settings and try again.';
            }
            return Response::json(['success' => false, 'error' => $error], 500);
        }
    }

    /**
     * Renders a value as a safe, single-line, double-quoted .env token.
     *
     * Strips all control characters (newlines included) so a value can never
     * span multiple lines and inject additional environment directives, then
     * escapes backslash, double-quote and dollar so the value round-trips
     * correctly through both parseTempEnv() and the production phpdotenv parser.
     *
     * @param string $raw The raw value to encode.
     * @return string The quoted, escaped .env token.
     */
    private function envToken(string $raw): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $clean);
        return '"' . $escaped . '"';
    }

    /**
     * Sanitize error message - strip file paths and credentials.
     *
     * @param string $message The raw error message.
     * @return string The sanitized error message.
     */
    private function sanitizeErrorMessage(string $message): string
    {
        $message = preg_replace('#[A-Z]:\\\\[^\s:]+#', '[path]', $message) ?? $message;
        $message = preg_replace('#/[^\s:]+\.php#', '[path]', $message) ?? $message;
        $message = preg_replace('#using password: (?:YES|NO)#i', 'using password: ***', $message) ?? $message;
        return $message;
    }

    /**
     * Run system requirements checks.
     *
     * @return array<int, array{name: string, required: string, current: string, ok: bool}> The list of requirements checked and their statuses.
     */
    private function checkRequirements(): array
    {
        $envPath = $this->rootDir . '/.env';
        $envWritable = is_writable($this->rootDir) || (file_exists($envPath) && is_writable($envPath));
        $storageDir = $this->rootDir . '/storage';
        $storageWritable = is_dir($storageDir) && is_writable($storageDir);
        $publicDir = $this->rootDir . '/public';
        $publicWritable = is_dir($publicDir) && is_writable($publicDir);

        return [
            ['name' => 'PHP Version',      'required' => '≥ 8.3', 'current' => PHP_VERSION,                                      'ok' => version_compare(PHP_VERSION, '8.3.0', '>=')],
            ['name' => 'PDO MySQL',        'required' => 'Enabled', 'current' => extension_loaded('pdo_mysql') ? 'Yes' : 'No',   'ok' => extension_loaded('pdo_mysql')],
            ['name' => 'cURL',             'required' => 'Enabled', 'current' => extension_loaded('curl')      ? 'Yes' : 'No',   'ok' => extension_loaded('curl')],
            ['name' => 'OpenSSL',          'required' => 'Enabled', 'current' => extension_loaded('openssl')   ? 'Yes' : 'No',   'ok' => extension_loaded('openssl')],
            ['name' => 'Mbstring',         'required' => 'Enabled', 'current' => extension_loaded('mbstring')  ? 'Yes' : 'No',   'ok' => extension_loaded('mbstring')],
            ['name' => 'JSON',             'required' => 'Enabled', 'current' => extension_loaded('json')      ? 'Yes' : 'No',   'ok' => extension_loaded('json')],
            ['name' => 'BCMath',           'required' => 'Enabled', 'current' => extension_loaded('bcmath')    ? 'Yes' : 'No',   'ok' => extension_loaded('bcmath')],
            ['name' => 'Fileinfo',         'required' => 'Enabled', 'current' => extension_loaded('fileinfo')  ? 'Yes' : 'No',   'ok' => extension_loaded('fileinfo')],
            ['name' => 'GD Library',       'required' => 'Enabled', 'current' => extension_loaded('gd')        ? 'Yes' : 'No',   'ok' => extension_loaded('gd')],
            ['name' => 'Writable: .env',   'required' => 'Yes',    'current' => $envWritable ? 'Yes' : 'No',                     'ok' => $envWritable],
            ['name' => 'Writable: storage/','required' => 'Yes',    'current' => $storageWritable ? 'Yes' : 'No',                 'ok' => $storageWritable],
            ['name' => 'Writable: public/', 'required' => 'Yes',    'current' => $publicWritable ? 'Yes' : 'No',                  'ok' => $publicWritable],
            ['name' => 'Composer vendor/', 'required' => 'Exists', 'current' => is_dir($this->rootDir . '/vendor') ? 'Yes' : 'No', 'ok' => is_dir($this->rootDir . '/vendor')],
            ['name' => 'Argon2id Hashing', 'required' => 'Enabled', 'current' => defined('PASSWORD_ARGON2ID') ? 'Yes' : 'No',   'ok' => defined('PASSWORD_ARGON2ID')],
        ];
    }

    /**
     * Checks if the system is installed.
     *
     * The marker file is the fast path, but it is NOT the only authority:
     * if the marker is deleted (accidentally or maliciously) while the
     * configured database still holds a superadmin, the wizard must stay
     * locked - otherwise an unauthenticated visitor could drop every table
     * via importSchema() or mint a fresh superadmin via createAdmin().
     * A deliberate reinstall over a populated database requires the
     * INSTALL_FORCE_KEY escape hatch (or deleting .env as well).
     *
     * @param Request|null $req The incoming HTTP request, when available, to honor the force key.
     * @return bool True if already installed, false otherwise.
     */
    private function isInstalled(?Request $req = null): bool
    {
        if (file_exists($this->markerFile)) {
            return true;
        }

        if (!$this->databaseLooksInstalled()) {
            return false;
        }

        if ($req !== null && $this->forceKeyMatches($req)) {
            return false;
        }

        @file_put_contents(
            $this->markerFile,
            "Installed: " . DateHelper::iso() . "\nRestored: database probe (marker file was missing)\n",
            LOCK_EX
        );
        @chmod($this->markerFile, 0640);
        error_log('[OwnPay] SECURITY: storage/.installed was missing but the configured database already contains a superadmin - marker self-healed, installer locked.');

        return true;
    }

    /**
     * Probes the database configured in the live environment for an existing installation.
     *
     * Returns false on any connection or query failure: a fresh install has
     * no .env / no reachable database, and must never be blocked by this probe.
     *
     * @return bool True when the configured database contains a superadmin user.
     */
    private function databaseLooksInstalled(): bool
    {
        if ($this->dbProbeResult !== null) {
            return $this->dbProbeResult;
        }

        $host = $_ENV['DB_HOST'] ?? null;
        $name = $_ENV['DB_NAME'] ?? null;
        $user = $_ENV['DB_USER'] ?? null;
        if (!is_string($host) || $host === '' || !is_string($name) || $name === '' || !is_string($user) || $user === '') {
            return $this->dbProbeResult = false;
        }
        $passVal = $_ENV['DB_PASS'] ?? '';
        $pass = is_string($passVal) ? $passVal : '';
        $portVal = $_ENV['DB_PORT'] ?? 3306;
        $port = is_scalar($portVal) ? (int) $portVal : 3306;
        $prefixVal = $_ENV['DB_PREFIX'] ?? 'op_';
        $prefix = is_string($prefixVal) && preg_match('/^[a-z0-9_]{1,30}$/i', $prefixVal) ? $prefixVal : 'op_';

        try {
            $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 2,
            ]);
            $stmt = $pdo->query("SELECT 1 FROM `{$prefix}merchant_users` WHERE is_superadmin = 1 LIMIT 1");
            return $this->dbProbeResult = ($stmt !== false && $stmt->fetch() !== false);
        } catch (\Throwable) {
            return $this->dbProbeResult = false;
        }
    }

    /**
     * Validates the reinstall force key supplied with the request.
     *
     * The key must be explicitly configured via the INSTALL_FORCE_KEY
     * environment variable and at least 16 characters long - an unset or
     * trivially short value never unlocks a populated installation.
     *
     * @param Request $req The incoming HTTP request.
     * @return bool True when a sufficiently strong key is configured and matches.
     */
    private function forceKeyMatches(Request $req): bool
    {
        $configuredVal = $_ENV['INSTALL_FORCE_KEY'] ?? '';
        $configured = is_string($configuredVal) ? $configuredVal : '';
        if (strlen($configured) < 16) {
            return false;
        }

        $provided = $req->header('X-Install-Force-Key');
        return $provided !== '' && hash_equals($configured, $provided);
    }

    /**
     * Renders a PHP template file with parameters.
     *
     * @param string $template The template name/path.
     * @param array<string, mixed>  $data     The template parameters.
     * @return string The rendered template content.
     */
    private function renderPhpTemplate(string $template, array $data): string
    {
        $file = $this->rootDir . '/templates/' . $template;
        if (!file_exists($file)) {
            return '<h1>Template not found: ' . htmlspecialchars($template) . '</h1>';
        }
        extract($data, EXTR_SKIP); // prevent template data overwriting local vars
        ob_start();
        include $file;
        return ob_get_clean() ?: '';
    }

    /**
     * Parses SQL schema file content into discrete SQL statements.
     *
     * @param string $sql The raw SQL content.
     * @return string[] The discrete SQL statements.
     */
    private function parseSqlStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $inString   = false;
        $strChar    = '';

        foreach (explode("\n", str_replace("\r\n", "\n", $sql)) as $line) {
            if (!$inString) {
                $stripped = (string) preg_replace('/\s*--.*$/', '', $line);
                if (trim($stripped) === '') {
                    continue;
                }
                $line = $stripped;
            }
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $c = $line[$i];
                if ($inString) {
                    $current .= $c;
                    if ($c === '\\' && $i + 1 < $len) {
                        $current .= $line[++$i];
                    } elseif ($c === $strChar) {
                        $inString = false;
                    }
                } else {
                    if ($c === "'" || $c === '"' || $c === '`') {
                        $inString = true;
                        $strChar = $c;
                        $current .= $c;
                    } elseif ($c === ';') {
                        if (trim($current) !== '') {
                            $statements[] = trim($current);
                        }
                        $current = '';
                    } else {
                        $current .= $c;
                    }
                }
            }
            $current .= "\n";
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
