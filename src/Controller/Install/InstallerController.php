<?php
declare(strict_types=1);

namespace OwnPay\Controller\Install;

use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Support\DateHelper;

/**
 * Class InstallerController
 *
 * Installer Controller — multi-step wizard.
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
        if ($this->isInstalled()) {
            return Response::html($this->renderPhpTemplate('install/locked.php', []));
        }
        $stepQuery = $req->query('step', '1');
        $stepVal = (is_int($stepQuery) || is_string($stepQuery) || is_numeric($stepQuery)) ? (int) $stepQuery : 1;
        $step = max(1, min(4, $stepVal));

        // Prevent skipping steps — must complete prerequisites
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
    public function testDatabase(Request $req): Response
    {
        if ($this->isInstalled()) {
            return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        }
        $body = $req->json();
        if (!is_array($body)) {
            $body = [];
        }
        $hostVal   = $body['host']   ?? 'localhost';
        $host      = trim(is_string($hostVal) ? $hostVal : 'localhost');
        $portVal   = $body['port'] ?? 3306;
        $port      = (is_int($portVal) || is_string($portVal) || is_numeric($portVal)) ? (int) $portVal : 3306;
        $nameVal   = $body['name']   ?? '';
        $name      = trim(is_string($nameVal) ? $nameVal : '');
        $userVal   = $body['user']   ?? '';
        $user      = trim(is_string($userVal) ? $userVal : '');
        $passVal   = $body['pass']        ?? '';
        $pass      = is_string($passVal) ? $passVal : '';
        $prefixVal = $body['prefix'] ?? 'op_';
        $prefix    = trim(is_string($prefixVal) ? $prefixVal : 'op_');

        if (!$name || !$user) {
            return Response::json(['success' => false, 'error' => 'DB name and user required'], 422);
        }
        // Strict validation for DB name — prevents SQL injection in CREATE DATABASE / USE.
        // Only alphanumeric + underscore allowed, max 64 chars (MySQL limit).
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) {
            return Response::json(['success' => false, 'error' => 'Invalid database name — alphanumeric and underscores only'], 422);
        }
        if (!preg_match('/^[a-z0-9_]{1,30}$/i', $prefix)) {
            return Response::json(['success' => false, 'error' => 'Invalid prefix'], 422);
        }

        try {
            $pdo = new \PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
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

            // Drop existing tables so a re-install works cleanly
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

            // Write temp env to storage/ (not webroot) to prevent credential exposure.
            $env = "DB_HOST={$host}\nDB_PORT={$port}\nDB_NAME={$name}\nDB_USER={$user}\nDB_PASS={$pass}\nDB_PREFIX={$prefix}\n";
            file_put_contents($this->rootDir . '/storage/.env.temp', $env, LOCK_EX);
            @chmod($this->rootDir . '/storage/.env.temp', 0640);

            return Response::json(['success' => true, 'message' => 'Schema imported successfully']);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // Sanitize — never expose raw SQL state, hostnames, or credentials
            if (str_contains($msg, 'Access denied')) {
                $error = 'Access denied. Check your database username and password.';
            } elseif (str_contains($msg, 'Connection refused') || str_contains($msg, 'No such file')) {
                $error = 'Could not connect to database server. Check host and port.';
            } elseif (str_contains($msg, 'Unknown database')) {
                $error = 'Database will be created automatically. Check user has CREATE privilege.';
            } else {
                $error = 'Database connection failed. Verify your credentials and try again.';
            }
            return Response::json(['success' => false, 'error' => $error], 500);
        }
    }

    /**
     * Creates the superadmin merchant account and seeds default system permissions.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response indicating success or failure.
     */
    public function createAdmin(Request $req): Response
    {
        if ($this->isInstalled()) {
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
            $env = parse_ini_file($envFile);
            if ($env === false) {
                return Response::json(['success' => false, 'error' => 'Failed to parse database environment configuration.'], 500);
            }
            $dbHostVal = $env['DB_HOST'] ?? 'localhost';
            $dbHost = is_string($dbHostVal) ? $dbHostVal : 'localhost';
            $dbPortVal = $env['DB_PORT'] ?? '3306';
            $dbPort = is_string($dbPortVal) || is_int($dbPortVal) || is_numeric($dbPortVal) ? (string) $dbPortVal : '3306';
            $dbNameVal = $env['DB_NAME'] ?? 'ownpay';
            $dbName = is_string($dbNameVal) ? $dbNameVal : 'ownpay';
            $dbUserVal = $env['DB_USER'] ?? 'root';
            $dbUser = is_string($dbUserVal) ? $dbUserVal : 'root';
            $dbPassVal = $env['DB_PASS'] ?? '';
            $dbPass = is_string($dbPassVal) ? $dbPassVal : '';
            $pVal      = $env['DB_PREFIX'] ?? 'op_';
            $p         = is_string($pVal) ? $pVal : 'op_';

            $pdo = new \PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                $dbUser, $dbPass
            );
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $now = DateHelper::now();

            // Use CSPRNG random_int() instead of weak mt_rand() for UUID.
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
            $stmt->execute([$merchantUuid, 'Own Pay', 'own-pay', $email, $now, $now]);
            $merchantId = (int) $pdo->lastInsertId();

            // 2. Insert the owner role
            $stmt = $pdo->prepare(
                "INSERT INTO {$p}roles (merchant_id, name, slug, description, is_system, created_at, updated_at) VALUES (?,?,?,?,1,?,?)"
            );
            $stmt->execute([$merchantId, 'Owner', 'owner', 'System owner role', $now, $now]);
            $roleId = (int) $pdo->lastInsertId();

            // 3. Insert the superadmin user (DS-11 FIX: include username column)
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

            // 5. DS-15 FIX: Seed default permissions
            $permissions = [
                // [slug, name, group_name]
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
        if ($this->isInstalled()) {
            return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        }
        $body     = $req->json();
        $body = $req->json();
        if (!is_array($body)) {
            $body = [];
        }
        $appNameVal  = $body['app_name']  ?? 'Own Pay';
        $appName     = trim(is_string($appNameVal) ? $appNameVal : 'Own Pay');
        $currencyVal = $body['currency']  ?? 'BDT';
        $currency    = trim(is_string($currencyVal) ? $currencyVal : 'BDT');
        $timezoneVal = $body['timezone']  ?? 'Asia/Dhaka';
        $timezone    = trim(is_string($timezoneVal) ? $timezoneVal : 'Asia/Dhaka');

        $tempEnv  = $this->rootDir . '/storage/.env.temp';
        $finalEnv = $this->rootDir . '/.env';

        if (!file_exists($tempEnv)) {
            return Response::json(['success' => false, 'error' => 'Complete previous steps'], 400);
        }

        try {
            // Generate independent keys — PCI-DSS 3.6 requires separate keys per purpose.
            $appKey        = base64_encode(random_bytes(32)); // Session/framework key
            $encryptionKey = base64_encode(random_bytes(32)); // AES-256-GCM for PII
            $hmacKey       = bin2hex(random_bytes(32));        // HMAC signing key
            $jwtSecret     = bin2hex(random_bytes(32));        // JWT signing key for mobile

            $envContent  = file_get_contents($tempEnv);
            $envContent .= "APP_NAME=\"{$appName}\"\n";
            $envContent .= "APP_TIMEZONE={$timezone}\n";
            $envContent .= "APP_CURRENCY={$currency}\n";
            $envContent .= "APP_ENV=production\n";
            $envContent .= "APP_DEBUG=false\n";
            $envContent .= "APP_KEY={$appKey}\n";
            $envContent .= "ENCRYPTION_KEY={$encryptionKey}\n";
            $envContent .= "HMAC_KEY={$hmacKey}\n";
            $envContent .= "JWT_SECRET={$jwtSecret}\n";
            $envContent .= "CACHE_DRIVER=file\n";
            $envContent .= "QUEUE_DRIVER=file\n";

            file_put_contents($finalEnv, $envContent, LOCK_EX);
            @chmod($finalEnv, 0640);

            // Connect using the temp env we already have in memory
            // NOTE: parse_ini_file() cannot parse base64 values containing '='
            $dbEnv = parse_ini_file($tempEnv);
            if ($dbEnv === false) {
                return Response::json(['success' => false, 'error' => 'Database config corrupted. Please go back to Step 2.'], 500);
            }
            $dbHostVal = $dbEnv['DB_HOST'] ?? 'localhost';
            $dbHost = is_string($dbHostVal) ? $dbHostVal : 'localhost';
            $dbPortVal = $dbEnv['DB_PORT'] ?? '3306';
            $dbPort = is_string($dbPortVal) || is_int($dbPortVal) || is_numeric($dbPortVal) ? (string) $dbPortVal : '3306';
            $dbNameVal = $dbEnv['DB_NAME'] ?? 'ownpay';
            $dbName = is_string($dbNameVal) ? $dbNameVal : 'ownpay';
            $dbUserVal = $dbEnv['DB_USER'] ?? 'root';
            $dbUser = is_string($dbUserVal) ? $dbUserVal : 'root';
            $dbPassVal = $dbEnv['DB_PASS'] ?? '';
            $dbPass = is_string($dbPassVal) ? $dbPassVal : '';
            $pVal      = $dbEnv['DB_PREFIX'] ?? 'op_';
            $p         = is_string($pVal) ? $pVal : 'op_';

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

            // Write .installed marker
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
            ['name' => 'PHP Version',      'required' => '≥ 8.2', 'current' => PHP_VERSION,                                      'ok' => version_compare(PHP_VERSION, '8.2.0', '>=')],
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
        ];
    }

    /**
     * Checks if the installation marker exists.
     *
     * @return bool True if already installed, false otherwise.
     */
    private function isInstalled(): bool
    {
        return file_exists($this->markerFile);
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
