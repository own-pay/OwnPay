<?php
declare(strict_types=1);

namespace OwnPay\Controller\Install;

use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Support\DateHelper;

/**
 * Installer Controller — multi-step wizard.
 * Requirements → DB → Admin → Settings → Done
 * Input validation, Argon2ID, .installed lockout.
 */
final class InstallerController
{
    private string $rootDir;
    private string $markerFile;

    public function __construct()
    {
        $this->rootDir    = dirname(__DIR__, 3);
        $this->markerFile = $this->rootDir . '/storage/.installed';
    }

    public function show(Request $req): Response
    {
        if ($this->isInstalled()) {
            return Response::html($this->renderPhpTemplate('install/locked.php', []));
        }
        $step = max(1, min(4, (int) $req->query('step', '1')));

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
        return Response::html($this->renderPhpTemplate("install/step{$step}.php", $data));
    }

    //Test DB + import schema
    public function testDatabase(Request $req): Response
    {
        if ($this->isInstalled()) return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        $body   = $req->json();
        $host   = trim($body['host']   ?? 'localhost');
        $port   = (int) ($body['port'] ?? 3306);
        $name   = trim($body['name']   ?? '');
        $user   = trim($body['user']   ?? '');
        $pass   = $body['pass']        ?? '';
        $prefix = trim($body['prefix'] ?? 'op_');

        if (!$name || !$user) return Response::json(['success' => false, 'error' => 'DB name and user required'], 422);
        // Strict validation for DB name — prevents SQL injection in CREATE DATABASE / USE.
        // Only alphanumeric + underscore allowed, max 64 chars (MySQL limit).
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) return Response::json(['success' => false, 'error' => 'Invalid database name — alphanumeric and underscores only'], 422);
        if (!preg_match('/^[a-z0-9_]{1,30}$/i', $prefix)) return Response::json(['success' => false, 'error' => 'Invalid prefix'], 422);

        try {
            $pdo = new \PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$name}`");

            $sqlPath = $this->rootDir . '/database/schema.sql';
            if (!file_exists($sqlPath)) return Response::json(['success' => false, 'error' => 'Schema file missing'], 500);
            $sql = file_get_contents($sqlPath);
            if (strlen($sql) < 10000) return Response::json(['success' => false, 'error' => 'Schema integrity failed'], 500);

            if ($prefix !== 'op_') $sql = str_replace('`op_', "`{$prefix}", $sql);

            // Drop existing tables so a re-install works cleanly
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $existing = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
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

    // Create admin account
    public function createAdmin(Request $req): Response
    {
        if ($this->isInstalled()) return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        $body     = $req->json();
        $name     = trim($body['name']     ?? '');
        $email    = trim($body['email']    ?? '');
        $username = trim($body['username'] ?? '');
        $password = $body['password']      ?? '';

        if (!$name || !$email || !$username || !$password) return Response::json(['success' => false, 'error' => 'All fields required'], 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return Response::json(['success' => false, 'error' => 'Invalid email'], 422);
        if (strlen($password) < 8) return Response::json(['success' => false, 'error' => 'Password min 8 chars'], 422);

        $envFile = $this->rootDir . '/storage/.env.temp';
        if (!file_exists($envFile)) return Response::json(['success' => false, 'error' => 'Complete DB step first'], 400);

        try {
            $env = parse_ini_file($envFile);
            $pdo = new \PDO(
                "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4",
                $env['DB_USER'], $env['DB_PASS']
            );
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $p   = $env['DB_PREFIX'];
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
                "INSERT INTO {$p}roles (merchant_id, name, slug, description, is_system, created_at) VALUES (?,?,?,?,1,?)"
            );
            $stmt->execute([$merchantId, 'Owner', 'owner', 'System owner role', $now]);
            $roleId = (int) $pdo->lastInsertId();

            // 3. Insert the superadmin user
            $stmt = $pdo->prepare(
                "INSERT INTO {$p}merchant_users (merchant_id, role_id, name, email, password_hash, is_superadmin, status, created_at, updated_at)
                 VALUES (?,?,?,?,?,1,'active',?,?)"
            );
            $stmt->execute([$merchantId, $roleId, $name, $email, $hash, $now, $now]);

            // 4. Seed default currencies
            $currencies = [
                ['BDT', 'Bangladeshi Taka',  '৳',  2],
                ['USD', 'US Dollar',         '$',  2],
                ['EUR', 'Euro',              '€',  2],
                ['GBP', 'British Pound',     '£',  2],
                ['INR', 'Indian Rupee',      '₹',  2],
            ];
            $cs = $pdo->prepare("INSERT IGNORE INTO {$p}currencies (code, name, symbol, decimal_places, status) VALUES (?,?,?,?,'active')");
            foreach ($currencies as $c) $cs->execute($c);

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

    // Finalize — generate APP_KEY, write .env, seed settings, write .installed marker 
    public function finalize(Request $req): Response
    {
        if ($this->isInstalled()) return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        $body     = $req->json();
        $appName  = trim($body['app_name']  ?? 'Own Pay');
        $currency = trim($body['currency']  ?? 'BDT');
        $timezone = trim($body['timezone']  ?? 'Asia/Dhaka');

        $tempEnv  = $this->rootDir . '/storage/.env.temp';
        $finalEnv = $this->rootDir . '/.env';

        if (!file_exists($tempEnv)) return Response::json(['success' => false, 'error' => 'Complete previous steps'], 400);

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
            $pdo = new \PDO(
                "mysql:host={$dbEnv['DB_HOST']};port={$dbEnv['DB_PORT']};dbname={$dbEnv['DB_NAME']};charset=utf8mb4",
                $dbEnv['DB_USER'], $dbEnv['DB_PASS']
            );
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $p = $dbEnv['DB_PREFIX'];

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
            ];
            $stmt = $pdo->prepare("INSERT IGNORE INTO {$p}system_settings (group_name, key_name, value, type) VALUES (?,?,?,?)");
            foreach ($seeds as $s) $stmt->execute($s);

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

    // Requirements check
    private function checkRequirements(): array
    {
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
            ['name' => 'Writable: .env',   'required' => 'Yes',    'current' => is_writable($this->rootDir)    ? 'Yes' : 'No',   'ok' => is_writable($this->rootDir)],
            ['name' => 'Composer vendor/', 'required' => 'Exists', 'current' => is_dir($this->rootDir . '/vendor') ? 'Yes' : 'No', 'ok' => is_dir($this->rootDir . '/vendor')],
        ];
    }

    private function isInstalled(): bool
    {
        return file_exists($this->markerFile);
    }

    private function renderPhpTemplate(string $template, array $data): string
    {
        $file = $this->rootDir . '/templates/' . $template;
        if (!file_exists($file)) return '<h1>Template not found: ' . htmlspecialchars($template) . '</h1>';
        extract($data, EXTR_SKIP); // prevent template data overwriting local vars
        ob_start();
        include $file;
        return ob_get_clean() ?: '';
    }

    /** @return string[] */
    private function parseSqlStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $inString   = false;
        $strChar    = '';

        foreach (explode("\n", str_replace("\r\n", "\n", $sql)) as $line) {
            if (!$inString) {
                $stripped = preg_replace('/\s*--.*$/', '', $line);
                if (trim($stripped) === '') continue;
                $line = $stripped;
            }
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $c = $line[$i];
                if ($inString) {
                    $current .= $c;
                    if ($c === '\\' && $i + 1 < $len) { $current .= $line[++$i]; }
                    elseif ($c === $strChar) { $inString = false; }
                } else {
                    if ($c === "'" || $c === '"' || $c === '`') {
                        $inString = true; $strChar = $c; $current .= $c;
                    } elseif ($c === ';') {
                        if (trim($current) !== '') $statements[] = trim($current);
                        $current = '';
                    } else {
                        $current .= $c;
                    }
                }
            }
            $current .= "\n";
        }
        if (trim($current) !== '') $statements[] = trim($current);
        return $statements;
    }
}
