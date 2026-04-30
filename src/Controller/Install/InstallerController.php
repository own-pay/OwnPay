<?php
declare(strict_types=1);

namespace OwnPay\Controller\Install;

use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Installer Controller — multi-step wizard.
 * K1-K10: Requirements → DB → Admin → Settings → Done
 * OWASP: Input validation, Argon2ID, .installed lockout.
 */
final class InstallerController
{
    private string $rootDir;
    private string $markerFile;

    public function __construct()
    {
        $this->rootDir = dirname(__DIR__, 3);
        $this->markerFile = $this->rootDir . '/app/install/.installed';
    }

    public function show(Request $req): Response
    {
        if ($this->isInstalled()) {
            return Response::html($this->renderTwig('install/locked.twig', []));
        }
        $step = max(1, min(4, (int) $req->get('step', '1')));
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
        $body = $req->jsonBody();
        $host = trim($body['host'] ?? 'localhost');
        $port = (int) ($body['port'] ?? 3306);
        $name = trim($body['name'] ?? '');
        $user = trim($body['user'] ?? '');
        $pass = $body['pass'] ?? '';
        $prefix = trim($body['prefix'] ?? 'op_');

        if (!$name || !$user) return Response::json(['success' => false, 'error' => 'DB name and user required'], 422);
        if (!preg_match('/^[a-z0-9_]{1,30}$/i', $prefix)) return Response::json(['success' => false, 'error' => 'Invalid prefix'], 422);

        try {
            $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $sqlPath = $this->rootDir . '/app/install/master_install.sql';
            if (!file_exists($sqlPath)) return Response::json(['success' => false, 'error' => 'Schema file missing'], 500);
            $sql = file_get_contents($sqlPath);
            if (strlen($sql) < 10000) return Response::json(['success' => false, 'error' => 'Schema integrity failed'], 500);

            if ($prefix !== 'op_') $sql = str_replace('op_', $prefix, $sql);
            $queries = array_filter(array_map('trim', preg_split('/;\r?\n/', $sql)));

            $pdo->beginTransaction();
            foreach ($queries as $q) { if ($q !== '') $pdo->exec($q); }
            $pdo->commit();

            // Save temp env
            $env = "DB_HOST={$host}\nDB_PORT={$port}\nDB_NAME={$name}\nDB_USER={$user}\nDB_PASS={$pass}\nDB_PREFIX={$prefix}\n";
            file_put_contents($this->rootDir . '/.env.temp', $env, LOCK_EX);
            @chmod($this->rootDir . '/.env.temp', 0640);

            return Response::json(['success' => true, 'message' => 'Schema imported']);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** K4+K8: Create admin account */
    public function createAdmin(Request $req): Response
    {
        if ($this->isInstalled()) return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        $body = $req->jsonBody();
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
            $now = date('Y-m-d H:i:s');
            $uuid = bin2hex(random_bytes(16));
            $hash = password_hash($password, PASSWORD_ARGON2ID);

            $pdo->exec("INSERT INTO {$p}merchants (public_id, business_name, base_currency, timezone, created_at) VALUES ('{$uuid}','System Default','BDT','Asia/Dhaka','{$now}')");
            $pdo->exec("INSERT INTO {$p}roles (slug, name, description, is_system, created_at) VALUES ('owner','Owner','System Owner',1,'{$now}')");

            $stmt = $pdo->prepare("INSERT INTO {$p}merchant_users (public_id, merchant_id, role_id, full_name, email, username, password_hash, status, created_at) VALUES (?,1,1,?,?,?,?,'active',?)");
            $stmt->execute([bin2hex(random_bytes(16)), $name, $email, $username, $hash, $now]);

            $pdo->exec("INSERT INTO {$p}currencies (code, name, symbol, decimals, is_active, created_at) VALUES ('BDT','Bangladeshi Taka','৳',2,1,'{$now}')");

            return Response::json(['success' => true]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** K5-K7+K9: Finalize — settings, .env, .installed marker */
    public function finalize(Request $req): Response
    {
        if ($this->isInstalled()) return Response::json(['success' => false, 'error' => 'Already installed'], 403);
        $body = $req->jsonBody();
        $appName = trim($body['app_name'] ?? 'Own Pay');
        $currency = trim($body['currency'] ?? 'BDT');
        $timezone = trim($body['timezone'] ?? 'Asia/Dhaka');

        $tempEnv = $this->rootDir . '/.env.temp';
        $finalEnv = $this->rootDir . '/.env';
        $configFile = $this->rootDir . '/op-config.php';

        if (!file_exists($tempEnv)) return Response::json(['success' => false, 'error' => 'Complete previous steps'], 400);

        try {
            $envContent = file_get_contents($tempEnv);
            $envContent .= "APP_NAME={$appName}\nAPP_TIMEZONE={$timezone}\nAPP_CURRENCY={$currency}\n";
            file_put_contents($finalEnv, $envContent, LOCK_EX);
            @chmod($finalEnv, 0640);

            // Generate op-config.php
            $config = "<?php\n/** Own Pay Config — auto-generated */\n"
                . "\$autoloadPath = __DIR__ . '/vendor/autoload.php';\n"
                . "if (file_exists(\$autoloadPath)) require_once \$autoloadPath;\n"
                . "if (file_exists(__DIR__.'/.env') && class_exists('Dotenv\\Dotenv')) {\n"
                . "    Dotenv\\Dotenv::createImmutable(__DIR__)->safeLoad();\n}\n"
                . "\$db_host=\$_ENV['DB_HOST']??'localhost';\$db_user=\$_ENV['DB_USER']??'root';\n"
                . "\$db_pass=\$_ENV['DB_PASS']??'';\$db_name=\$_ENV['DB_NAME']??'ownpay';\n"
                . "\$db_port=\$_ENV['DB_PORT']??3306;\$db_prefix=\$_ENV['DB_PREFIX']??'op_';\n";
            file_put_contents($configFile, $config, LOCK_EX);
            @chmod($configFile, 0640);

            // Seed settings
            $env = parse_ini_file($finalEnv);
            $pdo = new \PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4", $env['DB_USER'], $env['DB_PASS']);
            $p = $env['DB_PREFIX'];
            $now = date('Y-m-d H:i:s');
            $seeds = [['app_name', $appName], ['timezone', $timezone], ['currency', $currency], ['theme', 'own-pay'], ['version', '0.1.0']];
            $stmt = $pdo->prepare("INSERT INTO {$p}settings (setting_key, setting_value, created_at) VALUES (?,?,?)");
            foreach ($seeds as $s) $stmt->execute([$s[0], $s[1], $now]);

            // Write .installed marker
            file_put_contents($this->markerFile, "Installed: " . date('c') . "\n", LOCK_EX);
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
            ['name' => 'PHP Version', 'required' => '≥ 8.1', 'current' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '8.1.0', '>=')],
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
        return file_exists($this->markerFile) || file_exists($this->rootDir . '/op-config.php');
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
}
