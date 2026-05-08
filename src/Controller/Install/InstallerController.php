<?php
declare(strict_types=1);

namespace OwnPay\Controller\Install;

use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Support\DateHelper;

/**
 * Installer Controller â€” multi-step wizard.
 * K1-K10: Requirements â†’ DB â†’ Admin â†’ Settings â†’ Done
 * OWASP: Input validation, Argon2ID, .installed lockout.
 */
final class InstallerController
{
    private string $rootDir;
    private string $markerFile;

    public function __construct()
    {
        $this->rootDir = dirname(__DIR__, 3);
        $this->markerFile = $this->rootDir . '/storage/.installed';
    }

    public function show(Request $req): Response
    {
        if ($this->isInstalled()) {
            return Response::html($this->renderTwig('install/locked.twig', []));
        }
        $step = max(1, min(4, (int) $req->query('step', '1')));
        $data = match ($step) {
            1 => ['requirements' => $this->checkRequirements()],
            2 => [],
            3 => [],
            4 => [],
            default => [],
        };
        $data['step'] = $step;
        return Response::html($this->renderTwig("install/step{$step}.twig", $data));
    }

    /** K3: Test DB + import schema */
    public function testDatabase(Request $req): Response
    {
        if ($this->isInstalled()) return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        $body = $req->json();
        $host = trim($body['host'] ?? 'localhost');
        $port = (int) ($body['port'] ?? 3306);
        $name = trim($body['name'] ?? '');
        $user = trim($body['user'] ?? '');
        $pass = $body['pass'] ?? '';
        $prefix = trim($body['prefix'] ?? 'op_');

        if (!$name || !$user) return Response::json(['success' => false, 'error' => 'DB name and user required'], 422);
        if (!preg_match('/^[a-z0-9_]{1,30}$/i', $prefix)) return Response::json(['success' => false, 'error' => 'Invalid prefix'], 422);

        try {
            // Connect without dbname first so we can create it if needed
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
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            // Parse SQL statements properly:
            // Strip single-line -- comments, then split on bare semicolons
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $statements = $this->parseSqlStatements($sql);
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            // Save temp env for next steps
            $env = "DB_HOST={$host}\nDB_PORT={$port}\nDB_NAME={$name}\nDB_USER={$user}\nDB_PASS={$pass}\nDB_PREFIX={$prefix}\n";
            file_put_contents($this->rootDir . '/.env.temp', $env, LOCK_EX);
            @chmod($this->rootDir . '/.env.temp', 0640);

            return Response::json(['success' => true, 'message' => 'Schema imported successfully']);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** K4+K8: Create admin account */
    public function createAdmin(Request $req): Response
    {
        if ($this->isInstalled()) return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        $body = $req->json();
        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if (!$name || !$email || !$username || !$password) return Response::json(['success' => false, 'error' => 'All fields required'], 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return Response::json(['success' => false, 'error' => 'Invalid email'], 422);
        if (strlen($password) < 8) return Response::json(['success' => false, 'error' => 'Password min 8 chars'], 422);

        $envFile = $this->rootDir . '/.env.temp';
        if (!file_exists($envFile)) return Response::json(['success' => false, 'error' => 'Complete DB step first'], 400);

        try {
            $env = parse_ini_file($envFile);
            $pdo = new \PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4", $env['DB_USER'], $env['DB_PASS']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $p = $env['DB_PREFIX'];
            $now = DateHelper::now();
            $merchantUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            $slug = 'system-default';
            $hash = password_hash($password, PASSWORD_ARGON2ID);

            // 1. Insert the merchant
            $stmt = $pdo->prepare("INSERT INTO {$p}merchants (uuid, name, slug, email, timezone, default_currency, status, created_at) VALUES (?,?,?,?,'Asia/Dhaka','BDT','active',?)");
            $stmt->execute([$merchantUuid, 'System Default', $slug, $email, $now]);
            $merchantId = (int) $pdo->lastInsertId();

            // 2. Insert the owner role for this merchant
            $stmt = $pdo->prepare("INSERT INTO {$p}roles (merchant_id, name, slug, description, is_system, created_at) VALUES (?,?,?,?,1,?)");
            $stmt->execute([$merchantId, 'Owner', 'owner', 'System owner role', $now]);
            $roleId = (int) $pdo->lastInsertId();

            // 3. Insert the admin user with is_superadmin = 1
            $stmt = $pdo->prepare("INSERT INTO {$p}merchant_users (merchant_id, role_id, name, email, password_hash, is_superadmin, status, created_at) VALUES (?,?,?,?,?,1,'active',?)");
            $stmt->execute([$merchantId, $roleId, $name, $email, $hash, $now]);

            // 4. Seed default currency
            $pdo->exec("INSERT IGNORE INTO {$p}currencies (code, name, symbol, decimal_places, status) VALUES ('BDT','Bangladeshi Taka','à§³',2,'active')");

            return Response::json(['success' => true]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** K5-K7+K9: Finalize â€” settings, .env, .installed marker */
    public function finalize(Request $req): Response
    {
        if ($this->isInstalled()) return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        $body = $req->json();
        $appName = trim($body['app_name'] ?? 'Own Pay');
        $currency = trim($body['currency'] ?? 'BDT');
        $timezone = trim($body['timezone'] ?? 'Asia/Dhaka');

        $tempEnv = $this->rootDir . '/.env.temp';
        $finalEnv = $this->rootDir . '/.env';

        if (!file_exists($tempEnv)) return Response::json(['success' => false, 'error' => 'Complete previous steps'], 400);

        try {
            $envContent = file_get_contents($tempEnv);
            $envContent .= "APP_NAME=\"{$appName}\"\nAPP_TIMEZONE={$timezone}\nAPP_CURRENCY={$currency}\n";
            file_put_contents($finalEnv, $envContent, LOCK_EX);
            @chmod($finalEnv, 0640);


            // Seed settings into op_system_settings (group_name, key_name, value, type)
            $env = parse_ini_file($finalEnv);
            $pdo = new \PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4", $env['DB_USER'], $env['DB_PASS']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $p = $env['DB_PREFIX'];
            $seeds = [
                ['general', 'app_name',  $appName,   'string'],
                ['general', 'timezone',  $timezone,  'string'],
                ['general', 'currency',  $currency,  'string'],
                ['general', 'theme',     'own-pay',  'string'],
                ['general', 'version',   '0.1.0',    'string'],
            ];
            $stmt = $pdo->prepare("INSERT IGNORE INTO {$p}system_settings (group_name, key_name, value, type) VALUES (?,?,?,?)");
            foreach ($seeds as $s) $stmt->execute($s);

            // Write .installed marker
            file_put_contents($this->markerFile, "Installed: " . DateHelper::iso() . "\n", LOCK_EX);
            @chmod($this->markerFile, 0640);

            @unlink($tempEnv);

            return Response::json(['success' => true, 'message' => 'Installation complete']);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** K2: Requirements check */
    private function checkRequirements(): array
    {
        return [
            ['name' => 'PHP Version', 'required' => 'â‰¥ 8.1', 'current' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '8.1.0', '>=')],
            ['name' => 'PDO MySQL', 'required' => 'Enabled', 'current' => extension_loaded('pdo_mysql') ? 'Yes' : 'No', 'ok' => extension_loaded('pdo_mysql')],
            ['name' => 'cURL', 'required' => 'Enabled', 'current' => extension_loaded('curl') ? 'Yes' : 'No', 'ok' => extension_loaded('curl')],
            ['name' => 'OpenSSL', 'required' => 'Enabled', 'current' => extension_loaded('openssl') ? 'Yes' : 'No', 'ok' => extension_loaded('openssl')],
            ['name' => 'Mbstring', 'required' => 'Enabled', 'current' => extension_loaded('mbstring') ? 'Yes' : 'No', 'ok' => extension_loaded('mbstring')],
            ['name' => 'JSON', 'required' => 'Enabled', 'current' => extension_loaded('json') ? 'Yes' : 'No', 'ok' => extension_loaded('json')],
            ['name' => 'BCMath', 'required' => 'Enabled', 'current' => extension_loaded('bcmath') ? 'Yes' : 'No', 'ok' => extension_loaded('bcmath')],
            ['name' => 'Fileinfo', 'required' => 'Enabled', 'current' => extension_loaded('fileinfo') ? 'Yes' : 'No', 'ok' => extension_loaded('fileinfo')],
            ['name' => 'GD Library', 'required' => 'Enabled', 'current' => extension_loaded('gd') ? 'Yes' : 'No', 'ok' => extension_loaded('gd')],
            ['name' => 'Writable: .env', 'required' => 'Yes', 'current' => is_writable($this->rootDir) ? 'Yes' : 'No', 'ok' => is_writable($this->rootDir)],
            ['name' => 'Composer vendor/', 'required' => 'Exists', 'current' => is_dir($this->rootDir . '/vendor') ? 'Yes' : 'No', 'ok' => is_dir($this->rootDir . '/vendor')],
        ];
    }

    private function isInstalled(): bool
    {
        return file_exists($this->markerFile);
    }

    private function renderTwig(string $template, array $data): string
    {
        // Minimal Twig-like render for installer (no container available)
        $file = $this->rootDir . '/templates/' . $template;
        if (!file_exists($file)) return '<h1>Template not found: ' . htmlspecialchars($template) . '</h1>';
        extract($data);
        ob_start();
        include $file;
        return ob_get_clean() ?: '';
    }

    /**
     * Properly parse SQL into individual executable statements.
     * Strips -- comments, handles multi-line statements, splits on ';'.
     *
     * @return string[]
     */
    private function parseSqlStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $inString   = false;
        $strChar    = '';
        $lines      = explode("\n", str_replace("\r\n", "\n", $sql));

        foreach ($lines as $line) {
            // If not inside a string, strip trailing inline -- comments
            if (!$inString) {
                $stripped = preg_replace('/\s*--.*$/', '', $line);
                // If the line was only a comment, skip it
                if (trim($stripped) === '') {
                    continue;
                }
                $line = $stripped;
            }

            // Walk character by character to track string context and find ';'
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $c = $line[$i];

                if ($inString) {
                    $current .= $c;
                    if ($c === '\\') {
                        // Escaped character â€” consume next char too
                        if ($i + 1 < $len) {
                            $current .= $line[++$i];
                        }
                    } elseif ($c === $strChar) {
                        $inString = false;
                    }
                } else {
                    if ($c === "'" || $c === '"' || $c === '`') {
                        $inString = true;
                        $strChar  = $c;
                        $current .= $c;
                    } elseif ($c === ';') {
                        $stmt = trim($current);
                        if ($stmt !== '') {
                            $statements[] = $stmt;
                        }
                        $current = '';
                    } else {
                        $current .= $c;
                    }
                }
            }
            $current .= "\n"; // Preserve newline for multi-line statements
        }

        // Flush any trailing statement without a semicolon
        $stmt = trim($current);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }
}
