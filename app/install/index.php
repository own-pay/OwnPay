<?php
/**
 * Own Pay — Setup Wizard
 *
 * A greenfield installer that:
 *   1. Hard-locks if op-config.php already exists.
 *   2. Verifies environment requirements (PHP version + extensions + writable paths).
 *   3. Tests + imports the consolidated master_install.sql schema (transactional).
 *   4. Atomically generates op-config.php from form input.
 *   5. Seeds the Super Admin (op_merchants → op_roles → op_merchant_users → op_currencies).
 *
 * Loaded inside the framework bootstrap chain. The following globals are expected
 * to be pre-populated by app/core/adapter.php / root index.php:
 *   $requirements             array  — system probe results
 *   $requriemntnoneedchecked  bool   — true when all checks pass
 *   $site_url                 string — base URL for asset includes
 *   $csp_nonce                string — CSP nonce for inline <script>
 *
 * Helpers expected to be loaded via app/core/functions.php:
 *   generateStrongPassword(), getCurrentDatetime(), insertData()
 */

if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

// =============================================================================
// MULTI-LAYER INSTALL LOCKOUT (WordPress-style "siteurl" check)
// =============================================================================
// Any one of these conditions blocks the installer from running. Even if the
// admin deletes op-config.php, the .installed marker prevents re-installation
// unless the entire app/install/ directory is deleted (recommended).
// =============================================================================

$INSTALL_MARKER = __DIR__ . '/.installed';
$CONFIG_FILE    = __DIR__ . '/../../op-config.php';
$lockReasons    = [];

// Layer 1: marker file written at the end of a successful install
if (file_exists($INSTALL_MARKER)) {
    $lockReasons[] = 'install_marker';
}

// Layer 2: configuration file exists with valid DB credentials
if (file_exists($CONFIG_FILE)) {
    $lockReasons[] = 'config_present';

    // Layer 3: DB probe — try to connect and verify a seeded admin row exists
    if (!in_array('db_seeded', $lockReasons, true)) {
        @include $CONFIG_FILE;
        if (!empty($db_host) && !empty($db_name) && !empty($db_user) && !empty($db_prefix)) {
            try {
                $probePdo = new PDO(
                    "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
                    $db_user, $db_pass ?? '',
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
                );
                $stmt = $probePdo->query("SELECT COUNT(*) FROM `{$db_prefix}merchant_users` WHERE status='active' LIMIT 1");
                if ($stmt && (int) $stmt->fetchColumn() > 0) {
                    $lockReasons[] = 'db_seeded';
                    // Auto-write the marker on first detect so subsequent visits are faster
                    if (!file_exists($INSTALL_MARKER)) {
                        @file_put_contents(
                            $INSTALL_MARKER,
                            "Installed: " . date('Y-m-d H:i:s') . "\nAuto-detected via DB probe\n",
                            LOCK_EX
                        );
                        @chmod($INSTALL_MARKER, 0640);
                    }
                }
            } catch (Throwable $e) {
                // DB unreachable or schema absent — config exists but install incomplete
            }
        }
    }
}

// If any layer fired, render the "already installed" landing page and stop.
if (!empty($lockReasons)) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Already Installed · Own Pay</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Inter', system-ui, sans-serif;
                background: #0a0a0a;
                background-image:
                    radial-gradient(at 20% 0%, rgba(0, 212, 255, 0.08) 0px, transparent 45%),
                    radial-gradient(at 80% 100%, rgba(168, 85, 247, 0.06) 0px, transparent 50%);
                color: #fafafa;
                min-height: 100vh;
                display: flex; align-items: center; justify-content: center;
                padding: 1.5rem;
                line-height: 1.55;
                letter-spacing: -0.01em;
            }
            .card {
                background: rgba(255, 255, 255, 0.025);
                backdrop-filter: blur(16px);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 16px;
                box-shadow: 0 1px 0 rgba(255, 255, 255, 0.04) inset, 0 30px 60px -30px rgba(0, 0, 0, 0.7);
                max-width: 520px; width: 100%;
                padding: 2.5rem;
                text-align: center;
            }
            .lock-mark {
                width: 64px; height: 64px;
                border-radius: 16px;
                background: rgba(16, 185, 129, 0.1);
                color: #10b981;
                display: inline-flex; align-items: center; justify-content: center;
                font-size: 1.75rem;
                margin-bottom: 1.25rem;
                border: 1px solid rgba(16, 185, 129, 0.25);
                box-shadow: 0 0 24px rgba(16, 185, 129, 0.18);
            }
            h1 {
                font-size: 1.4rem; font-weight: 700; letter-spacing: -0.025em;
                margin-bottom: 0.5rem;
            }
            p {
                font-size: 0.9rem; color: #a1a1aa; margin-bottom: 1.5rem;
            }
            .reasons {
                background: rgba(255, 255, 255, 0.02);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 10px;
                padding: 0.85rem 1.1rem;
                text-align: left;
                font-family: 'JetBrains Mono', monospace;
                font-size: 0.72rem;
                color: #71717a;
                margin-bottom: 1.5rem;
            }
            .reasons strong { color: #fafafa; font-weight: 600; }
            .reasons div { padding: 0.2rem 0; }
            .reasons div::before { content: '✓ '; color: #10b981; }
            .actions {
                display: flex; gap: 0.6rem; flex-direction: column;
            }
            .btn {
                display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
                padding: 0.7rem 1.4rem;
                border-radius: 10px;
                font-family: inherit;
                font-size: 0.9rem; font-weight: 600;
                cursor: pointer; transition: all 0.2s;
                border: 1px solid transparent;
                text-decoration: none;
            }
            .btn-primary {
                background: #00d4ff; color: #000;
            }
            .btn-primary:hover {
                background: #33ddff;
                transform: translateY(-1px);
                box-shadow: 0 4px 24px rgba(0, 212, 255, 0.35);
            }
            .btn-secondary {
                background: rgba(255, 255, 255, 0.025);
                color: #a1a1aa;
                border-color: rgba(255, 255, 255, 0.08);
            }
            .btn-secondary:hover {
                background: rgba(255, 255, 255, 0.04);
                color: #fafafa;
            }
            .hint {
                margin-top: 1.5rem;
                padding-top: 1.5rem;
                border-top: 1px solid rgba(255, 255, 255, 0.08);
                font-size: 0.75rem;
                color: #71717a;
            }
            .hint code {
                background: #111111;
                padding: 1px 6px;
                border-radius: 4px;
                color: #00d4ff;
                font-family: 'JetBrains Mono', monospace;
                font-size: 0.85em;
                border: 1px solid rgba(255, 255, 255, 0.08);
            }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="lock-mark">✓</div>
            <h1>Already Installed</h1>
            <p>Own Pay is configured on this server. The installer has been locked to prevent re-installation and protect your data.</p>

            <div class="reasons">
                <strong>Lock signals:</strong>
                <?php foreach ($lockReasons as $r): ?>
                    <div><?= htmlspecialchars(match($r) {
                        'install_marker' => 'Marker file present (.installed)',
                        'config_present' => 'Configuration file exists (op-config.php)',
                        'db_seeded'      => 'Database seeded with admin user',
                        default          => $r,
                    }) ?></div>
                <?php endforeach; ?>
            </div>

            <div class="actions">
                <a href="login" class="btn btn-primary">Go to Admin Login →</a>
                <a href="/" class="btn btn-secondary">Return to Site</a>
            </div>

            <div class="hint">
                <strong style="color:#fafafa">Recommended:</strong> Delete the <code>app/install/</code> directory entirely for maximum security. The lock prevents accidental re-installation but the directory itself contains the schema and installer code.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =============================================================================
// ACTION ROUTER — POST handlers (early-return; HTML below only renders for GET)
// =============================================================================

// ─── ACTION 1: Test DB connection + import master schema ─────────────────────
if (isset($_POST['test_databse_request'])) {
    header('Content-Type: application/json');

    $host        = trim($_POST['dbHost']        ?? '');
    $port        = trim($_POST['dbPort']        ?? '3306');
    $dbname      = trim($_POST['dbName']        ?? '');
    $username    = trim($_POST['dbUsername']    ?? '');
    $password    = $_POST['dbPassword']         ?? '';
    $tablePrefix = trim($_POST['tablePrefix']   ?? 'op_');

    if (!$host || !$dbname || !$username) {
        echo json_encode(['status' => 'false', 'message' => 'Please fill in all required fields.']);
        exit;
    }

    // ── INPUT VALIDATION ─────────────────────────────────────────────────────
    // Strict charset whitelists (defense against SQL injection via schema substitution)
    if (!preg_match('/^[a-z0-9_]{1,30}$/i', $tablePrefix)) {
        echo json_encode(['status' => 'false', 'message' => 'Invalid table prefix. Use only letters, numbers, and underscore (max 30 chars).']);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9_\.\-]+$/', $dbname)) {
        echo json_encode(['status' => 'false', 'message' => 'Invalid database name.']);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9_\.\-]+$/', $username)) {
        echo json_encode(['status' => 'false', 'message' => 'Invalid database username.']);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9_\.\-]+$/', $host)) {
        echo json_encode(['status' => 'false', 'message' => 'Invalid host (use letters, numbers, dots, dashes only).']);
        exit;
    }
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        echo json_encode(['status' => 'false', 'message' => 'Invalid port number.']);
        exit;
    }

    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        echo json_encode(['status' => 'false', 'message' => 'PDO MySQL driver is not enabled on this server.']);
        exit;
    }

    if (isset($requriemntnoneedchecked) && $requriemntnoneedchecked === false) {
        echo json_encode([
            'status' => 'false',
            'title'  => 'Server Requirements Not Met',
            'message' => 'Your server does not meet the minimum requirements. Please enable the required PHP extensions and try again.',
        ]);
        exit;
    }

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_AUTOCOMMIT         => false,
        ]);

        // Read consolidated master schema
        $sqlPath = __DIR__ . '/master_install.sql';
        if (!file_exists($sqlPath)) {
            throw new Exception("Master schema file not found: master_install.sql");
        }
        $sqlContent = file_get_contents($sqlPath);
        if ($sqlContent === false || $sqlContent === '') {
            throw new Exception("Master schema is empty or unreadable.");
        }
        // Integrity guard: refuse suspiciously small schemas (typical master is ~70KB).
        // Real corruption / replacement attacks would either truncate or expand wildly.
        $schemaSize = strlen($sqlContent);
        if ($schemaSize < 10000 || $schemaSize > 2000000) {
            throw new Exception("Master schema integrity check failed: unexpected size {$schemaSize} bytes.");
        }
        // Structural sanity: master must contain at least 30 CREATE TABLE statements.
        if (substr_count(strtoupper($sqlContent), 'CREATE TABLE') < 30) {
            throw new Exception("Master schema integrity check failed: too few CREATE TABLE statements.");
        }

        // Optional prefix substitution: master file ships with `op_` exclusively.
        if (!empty($tablePrefix) && $tablePrefix !== 'op_') {
            $sqlContent = str_replace('op_', $tablePrefix, $sqlContent);
        }

        // Cross-platform statement split: handle ;\n and ;\r\n
        $queries = array_filter(array_map('trim', preg_split('/;\r?\n/', $sqlContent)));

        $pdo->beginTransaction();
        foreach ($queries as $query) {
            if ($query !== '') {
                $pdo->exec($query);
            }
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        // Write a temp config with LOCK_EX; promoted to op-config.php after admin step
        $configContent = "<?php\n"
            . "    \$db_host   = '" . addslashes($host)        . "';\n"
            . "    \$db_user   = '" . addslashes($username)    . "';\n"
            . "    \$db_pass   = '" . addslashes($password)    . "';\n"
            . "    \$db_name   = '" . addslashes($dbname)      . "';\n"
            . "    \$db_prefix = '" . addslashes($tablePrefix) . "';\n"
            . "?>\n";

        $tempPath = __DIR__ . '/../../op-temp-config.php';
        if (file_put_contents($tempPath, $configContent, LOCK_EX) === false) {
            throw new Exception("Failed to write temp config file.");
        }
        @chmod($tempPath, 0640);

        echo json_encode([
            'status'  => 'true',
            'title'   => 'Imported successfully',
            'message' => 'Database connection verified and schema imported successfully.',
        ]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'status'  => 'false',
            'title'   => 'Database Error',
            'message' => $e->getMessage(),
        ]);
    }
    exit;
}

// ─── ACTION 2: Create Super Admin + atomically promote temp config ───────────
if (isset($_POST['adminName'])) {
    header('Content-Type: application/json');

    $adminName       = trim($_POST['adminName']        ?? '');
    $adminEmail      = trim($_POST['adminEmail']       ?? '');
    $adminUsername   = trim($_POST['adminUsername']    ?? '');
    $adminPassword   = $_POST['adminPassword']         ?? '';
    $confirmPassword = $_POST['confirmPassword']       ?? '';

    if (isset($requriemntnoneedchecked) && $requriemntnoneedchecked === false) {
        echo json_encode([
            'status' => 'false',
            'title'  => 'Server Requirements Not Met',
            'message' => 'Your server does not meet the minimum requirements.',
        ]);
        exit;
    }

    if ($adminName === '' || $adminEmail === '' || $adminUsername === '' || $adminPassword === '' || $confirmPassword === '') {
        echo json_encode(['status' => 'false', 'message' => 'Enter all info before proceeding.']);
        exit;
    }
    if ($adminPassword !== $confirmPassword) {
        echo json_encode(['status' => 'false', 'message' => 'Password and Confirm Password must be the same.']);
        exit;
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'false', 'message' => 'Invalid email address.']);
        exit;
    }
    if (strlen($adminPassword) < 8) {
        echo json_encode(['status' => 'false', 'message' => 'Password must be at least 8 characters.']);
        exit;
    }
    // Password complexity: at least 1 letter and 1 digit (defense against weak passwords)
    if (!preg_match('/[A-Za-z]/', $adminPassword) || !preg_match('/[0-9]/', $adminPassword)) {
        echo json_encode(['status' => 'false', 'message' => 'Password must contain at least one letter and one digit.']);
        exit;
    }
    // Username charset whitelist
    if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,50}$/', $adminUsername)) {
        echo json_encode(['status' => 'false', 'message' => 'Username must be 3-50 chars (letters, numbers, underscore, dot, dash).']);
        exit;
    }
    // Full name: limit length, allow common punctuation only
    if (mb_strlen($adminName) > 200 || !preg_match('/^[\p{L}\p{N}\s\.\-\']{1,200}$/u', $adminName)) {
        echo json_encode(['status' => 'false', 'message' => 'Invalid full name (1-200 chars, letters/numbers/spaces only).']);
        exit;
    }

    if (!function_exists('generateUuidV4')) {
        function generateUuidV4(): string {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
    }

    $tempFile  = __DIR__ . '/../../op-temp-config.php';
    $finalFile = __DIR__ . '/../../op-config.php';

    if (!file_exists($tempFile)) {
        echo json_encode(['status' => 'false', 'message' => 'Temp config not found. Please complete the database step first.']);
        exit;
    }

    try {
        // Initialize the SOA Database singleton from temp config credentials.
        // This is required because insertData() → CrudService → Database::getInstance()
        // expects the singleton to already be initialized, but Bootstrap::init() fails
        // during install since op-config.php doesn't exist yet.
        if (class_exists('\\OwnPay\\Core\\Database')) {
            \OwnPay\Core\Database::init($db_host, $db_name, $db_user, $db_pass);
        }
        $newTempPassword   = generateStrongPassword(8);
        $hashedPass        = password_hash($adminPassword, PASSWORD_BCRYPT);
        $tempPasswordHash  = password_hash($newTempPassword, PASSWORD_BCRYPT);

        $merchantId       = 1; // initial seed
        $roleId           = 1;
        $merchantPublicId = generateUuidV4();
        $userPublicId     = generateUuidV4();
        $currentTime      = getCurrentDatetime('Y-m-d H:i:s');

        // 1. System Merchant
        insertData($db_prefix . 'merchants',
            ['public_id', 'business_name', 'base_currency', 'timezone', 'created_at', 'updated_at'],
            [$merchantPublicId, 'System Default', 'BDT', 'Asia/Dhaka', $currentTime, $currentTime]
        );

        // 2. Owner Role
        insertData($db_prefix . 'roles',
            ['slug', 'name', 'description', 'is_system', 'created_at', 'updated_at'],
            ['owner', 'Owner', 'System Owner Role', 1, $currentTime, $currentTime]
        );

        // 3. Super Admin User
        insertData($db_prefix . 'merchant_users',
            ['public_id', 'merchant_id', 'role_id', 'full_name', 'email', 'username', 'password_hash', 'temp_password', 'status', 'created_at', 'updated_at'],
            [$userPublicId, $merchantId, $roleId, $adminName, $adminEmail, $adminUsername, $hashedPass, $tempPasswordHash, 'active', $currentTime, $currentTime]
        );

        // 4. Default Currency
        insertData($db_prefix . 'currencies',
            ['code', 'name', 'symbol', 'decimals', 'is_active', 'created_at'],
            ['BDT', 'Bangladeshi Taka', '৳', 2, 1, $currentTime]
        );

        // 5. Auxiliary sessions table for cookie verification
        try {
            $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$db_prefix}sessions (
                cookie       VARCHAR(100) NOT NULL PRIMARY KEY,
                user_id      BIGINT UNSIGNED NOT NULL,
                merchant_id  BIGINT UNSIGNED NOT NULL,
                role_id      BIGINT UNSIGNED NOT NULL,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Non-fatal — continue
        }

        // 5b. Legacy Compatibility Seeding — bridge V2 data into legacy tables
        try {
            // Seed op_brands from merchant data
            insertData($db_prefix . 'brands',
                ['brand_id', 'identify_name', 'name', 'currency_code', 'currency_symbol', 'timezone', 'language'],
                [$merchantPublicId, 'System Default', 'System Default', 'BDT', '৳', 'Asia/Dhaka', 'en']
            );

            // Seed op_admin from admin user data
            $adminAid = generateUuidV4();
            insertData($db_prefix . 'admin',
                ['a_id', 'name', 'email', 'username', 'password', 'temp_password', 'role', 'status', 'created_date', 'updated_date'],
                [$adminAid, $adminName, $adminEmail, $adminUsername, $hashedPass, $tempPasswordHash, 'admin', 'active', $currentTime, $currentTime]
            );

            // Seed op_currency
            insertData($db_prefix . 'currency',
                ['brand_id', 'code', 'name', 'symbol', 'rate', 'status'],
                [$merchantPublicId, 'BDT', 'Bangladeshi Taka', '৳', '1.00000000', 'active']
            );

            // Seed essential op_env settings
            $envDefaults = [
                ['both', 'app_name', 'OwnPay'],
                ['both', 'app_version', '2.0.0'],
                ['both', 'timezone', 'Asia/Dhaka'],
                ['both', 'language', 'en'],
                ['both', 'currency', 'BDT'],
                ['both', 'theme', 'own-pay'],
            ];
            foreach ($envDefaults as $env) {
                insertData($db_prefix . 'env',
                    ['brand_id', 'option_name', 'value'],
                    [$env[0], $env[1], $env[2]]
                );
            }
        } catch (Throwable $e) {
            // Non-fatal — legacy seeding failure should not block install
            error_log('[OwnPay Installer] Legacy seeding warning: ' . $e->getMessage());
        }

        // 6. Atomically promote temp → final config
        $configContent = file_get_contents($tempFile);
        if ($configContent === false) {
            throw new Exception('Failed to read temp config.');
        }
        if (file_put_contents($finalFile, $configContent, LOCK_EX) === false) {
            throw new Exception('Failed to write final config.');
        }
        @chmod($finalFile, 0640);
        @unlink($tempFile);

        // 7. Write the install marker — this is the WordPress-style siteurl flag
        //    that locks the installer permanently even if op-config.php is removed.
        $markerContent = "Installed: " . date('Y-m-d H:i:s') . "\n"
                       . "Admin email: " . $adminEmail . "\n"
                       . "Schema: master_install.sql v2.1\n"
                       . "Lock: This file MUST exist to prevent installer re-execution.\n"
                       . "Recommended: Delete the entire app/install/ directory after a successful install.\n";
        @file_put_contents(__DIR__ . '/.installed', $markerContent, LOCK_EX);
        @chmod(__DIR__ . '/.installed', 0640);

        // 8. Make the master schema read-only post-install (prevent tampering)
        @chmod(__DIR__ . '/master_install.sql', 0444);
        @chmod(__DIR__ . '/index.php', 0444);

        // NOTE: Composer dependencies must be installed manually post-install
        //       via: composer install --no-dev --optimize-autoloader
        //       (see post-install checklist on the Done screen)

        echo json_encode(['status' => 'true', 'message' => 'Install completed.']);
    } catch (Throwable $e) {
        // Server-side log full error; return generic message to client
        error_log('[OwnPay Installer] create_admin failed: ' . $e->getMessage());
        echo json_encode(['status' => 'false', 'message' => 'Setup failed. Check server logs for details.']);
    }
    exit;
}

// =============================================================================
// VIEW LAYER — Render the wizard SPA
// =============================================================================

// ── Precompute requirements health ──
$fixGuides = [
    'PHP Version' => "Ubuntu/Debian:\nsudo apt install php8.2 php8.2-cli\nsudo update-alternatives --set php /usr/bin/php8.2\n\ncPanel: WHM → MultiPHP Manager → Select PHP 8.2",
    'cURL'        => "Ubuntu/Debian:  sudo apt install php-curl && sudo systemctl restart apache2\nCentOS/RHEL:    sudo yum install php-curl && sudo systemctl restart httpd\ncPanel:         WHM → EasyApache 4 → PHP Extensions → curl",
    'cURL Multi'  => "Included with the cURL extension. Enable cURL to resolve this.",
    'PDO'         => "Ubuntu/Debian:  sudo apt install php-mysql && sudo systemctl restart apache2\nCentOS/RHEL:    sudo yum install php-mysqlnd && sudo systemctl restart httpd\ncPanel:         WHM → EasyApache 4 → PHP Extensions → pdo_mysql",
    'GD Library'  => "Ubuntu/Debian:  sudo apt install php-gd && sudo systemctl restart apache2\nCentOS/RHEL:    sudo yum install php-gd && sudo systemctl restart httpd\ncPanel:         WHM → EasyApache 4 → PHP Extensions → gd",
    'Fileinfo'    => "Ubuntu/Debian:  sudo apt install php-common\nphp.ini:        Uncomment ;extension=fileinfo",
    'Imagick'     => "Ubuntu/Debian:  sudo apt install php-imagick && sudo systemctl restart apache2\nCentOS/RHEL:    sudo yum install php-imagick && sudo systemctl restart httpd\ncPanel:         WHM → EasyApache 4 → PHP Extensions → imagick",
    'OpenSSL'     => "Usually bundled with PHP. Check php.ini:\nextension=openssl",
    'ZipArchive'  => "Ubuntu/Debian:  sudo apt install php-zip && sudo systemctl restart apache2\nCentOS/RHEL:    sudo yum install php-pecl-zip && sudo systemctl restart httpd\ncPanel:         WHM → EasyApache 4 → PHP Extensions → zip",
    'Mbstring'    => "Ubuntu/Debian:  sudo apt install php-mbstring && sudo systemctl restart apache2\nCentOS/RHEL:    sudo yum install php-mbstring && sudo systemctl restart httpd\ncPanel:         WHM → EasyApache 4 → PHP Extensions → mbstring",
    'Tokenizer'   => "Usually bundled with PHP.\nphp.ini:  Uncomment ;extension=tokenizer",
    'JSON'        => "Bundled with PHP 8.0+. If missing, reinstall PHP.",
    'allow_url_fopen' => "php.ini:  Set allow_url_fopen = On\nThen restart your web server.",
    'file_uploads'    => "php.ini:  Set file_uploads = On\nThen restart your web server.",
    'bcmath'      => "Ubuntu/Debian:  sudo apt install php-bcmath && sudo systemctl restart apache2\nCentOS/RHEL:    sudo yum install php-bcmath && sudo systemctl restart httpd\ncPanel:         WHM → EasyApache 4 → PHP Extensions → bcmath",
    'Composer Dependencies' => "The 'vendor' directory is missing.\n- If you downloaded the release ZIP, ensure all files were uploaded.\n- If you cloned from GitHub, run 'composer install' via SSH/Terminal.",
];
$satisfied_btn = true;
$passCount = 0;
$totalCount = count($requirements);
foreach ($requirements as $r) {
    if ($r['check']) $passCount++;
    else $satisfied_btn = false;
}
$allPassed = ($passCount === $totalCount);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark">
    <title>Setup · Own Pay</title>
    <link rel="shortcut icon" href="<?= $OwnPay_favicon ?? '' ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════════════
           OWN PAY · INSTALLER — Dark-first, glassmorphism design system
           ═══════════════════════════════════════════════════════════════════ */
        :root {
            --bg-base: #0a0a0a;
            --bg-elevated: #111111;
            --surface: rgba(255, 255, 255, 0.025);
            --surface-hover: rgba(255, 255, 255, 0.04);
            --border: rgba(255, 255, 255, 0.08);
            --border-strong: rgba(255, 255, 255, 0.14);
            --text-primary: #fafafa;
            --text-secondary: #a1a1aa;
            --text-tertiary: #71717a;
            --accent: #00d4ff;
            --accent-glow: rgba(0, 212, 255, 0.35);
            --success: #10b981;
            --success-bg: rgba(16, 185, 129, 0.1);
            --warn: #f59e0b;
            --warn-bg: rgba(245, 158, 11, 0.1);
            --danger: #f43f5e;
            --danger-bg: rgba(244, 63, 94, 0.1);
            --radius-card: 16px;
            --radius-input: 10px;
            --shadow-card: 0 1px 0 rgba(255, 255, 255, 0.04) inset, 0 30px 60px -30px rgba(0, 0, 0, 0.7);
            --font-ui: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            --font-mono: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { font-size: 16px; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }

        body {
            font-family: var(--font-ui);
            background: var(--bg-base);
            background-image:
                radial-gradient(at 20% 0%, rgba(0, 212, 255, 0.08) 0px, transparent 45%),
                radial-gradient(at 80% 100%, rgba(168, 85, 247, 0.06) 0px, transparent 50%);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.55;
            letter-spacing: -0.01em;
        }

        a { color: var(--accent); text-decoration: none; transition: opacity .15s; }
        a:hover { opacity: 0.8; }

        code, .mono { font-family: var(--font-mono); font-size: 0.85em; }

        /* ═══════ HEADER ═══════ */
        .app-header {
            background: rgba(10, 10, 10, 0.6);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 0 1.5rem;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .app-header .brand { display: flex; align-items: center; gap: .65rem; }

        .app-header .brand-mark {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--accent) 0%, #7c3aed 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #000;
            font-weight: 800;
            font-size: 0.72rem;
            font-family: var(--font-mono);
            letter-spacing: -0.05em;
            box-shadow: 0 0 24px var(--accent-glow);
        }

        .app-header .brand-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
            letter-spacing: -0.02em;
        }
        .app-header .brand-name span {
            color: var(--text-tertiary);
            font-weight: 400;
            margin-left: 0.45rem;
        }

        .app-header .env-badges { display: flex; gap: 0.35rem; }
        .env-badge {
            font-family: var(--font-mono);
            font-size: 0.7rem;
            color: var(--text-secondary);
            background: var(--surface);
            border: 1px solid var(--border);
            padding: 4px 9px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .env-badge::before {
            content: '';
            width: 5px; height: 5px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 6px var(--success);
        }

        /* ═══════ LAYOUT ═══════ */
        .app-main {
            flex: 1;
            padding: 2.5rem 1rem 3rem;
            display: flex;
            justify-content: center;
        }

        .wizard { width: 100%; max-width: 600px; }

        /* ═══════ STEPPER ═══════ */
        .stepper {
            display: flex;
            align-items: flex-start;
            margin-bottom: 1.75rem;
            padding: 0 0.25rem;
        }
        .stepper .st {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }
        .stepper .st .num {
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 600;
            font-size: 0.82rem;
            font-family: var(--font-mono);
            background: var(--surface);
            color: var(--text-tertiary);
            border: 1px solid var(--border);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            z-index: 2;
        }
        .stepper .st.active .num {
            background: var(--accent);
            color: #000;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(0, 212, 255, 0.18), 0 0 24px var(--accent-glow);
        }
        .stepper .st.done .num {
            background: var(--success);
            color: #000;
            border-color: var(--success);
        }
        .stepper .st .txt {
            margin-top: 0.5rem;
            font-size: 0.72rem;
            font-weight: 500;
            color: var(--text-tertiary);
            text-align: center;
            transition: color 0.3s;
        }
        .stepper .st.active .txt { color: var(--text-primary); font-weight: 600; }
        .stepper .st.done .txt { color: var(--success); }
        .stepper .st:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 18px;
            left: calc(50% + 22px);
            width: calc(100% - 44px);
            height: 1px;
            background: var(--border);
            z-index: 1;
            transition: background 0.4s;
        }
        .stepper .st.done:not(:last-child)::after { background: var(--success); }

        /* ═══════ CARDS (glassmorphism) ═══════ */
        .wiz-card {
            background: var(--surface);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            overflow: hidden;
            display: none;
        }
        .wiz-card.on { display: block; animation: fadeUp 0.45s cubic-bezier(0.16, 1, 0.3, 1); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .wiz-hd {
            padding: 1.5rem 1.75rem 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        .wiz-hd .icon-badge {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 0.65rem;
            border: 1px solid var(--border);
        }
        .wiz-hd .icon-badge.cyan { background: rgba(0, 212, 255, 0.1); color: var(--accent); }
        .wiz-hd .icon-badge.green { background: var(--success-bg); color: var(--success); }
        .wiz-hd .icon-badge.purple { background: rgba(168, 85, 247, 0.1); color: #a855f7; }

        .wiz-hd h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 0.2rem;
            letter-spacing: -0.02em;
        }
        .wiz-hd p {
            font-size: 0.825rem;
            color: var(--text-secondary);
            margin: 0;
        }

        .wiz-bd { padding: 1.5rem 1.75rem 1.75rem; }

        /* ═══════ REQUIREMENTS ═══════ */
        .req-list { margin-bottom: 1.25rem; }
        .req-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.7rem 0.9rem;
            border-radius: 8px;
            transition: background 0.15s;
        }
        .req-item:hover { background: var(--surface-hover); }

        .req-item .ri-name {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-primary);
        }
        .req-item .ri-meta {
            font-size: 0.72rem;
            color: var(--text-tertiary);
            margin-top: 1px;
            font-family: var(--font-mono);
        }

        .req-badge {
            font-size: 0.72rem;
            font-weight: 600;
            font-family: var(--font-mono);
            padding: 3px 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            white-space: nowrap;
        }
        .req-badge.ok { background: var(--success-bg); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.25); }
        .req-badge.fail { background: var(--danger-bg); color: var(--danger); border: 1px solid rgba(244, 63, 94, 0.25); }

        .fix-box {
            margin: 0.25rem 0 0.5rem;
            padding: 0.65rem 0.9rem;
            background: var(--danger-bg);
            border: 1px solid rgba(244, 63, 94, 0.2);
            border-radius: 8px;
            font-size: 0.78rem;
        }
        .fix-box summary {
            cursor: pointer;
            font-weight: 600;
            color: var(--danger);
            font-size: 0.78rem;
            list-style: none;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }
        .fix-box summary::before { content: '▸'; transition: transform 0.2s; }
        .fix-box[open] summary::before { transform: rotate(90deg); }
        .fix-box pre {
            background: var(--bg-base);
            color: var(--text-secondary);
            padding: 0.7rem 0.9rem;
            border-radius: 8px;
            margin-top: 0.5rem;
            font-size: 0.72rem;
            overflow-x: auto;
            line-height: 1.6;
            white-space: pre-wrap;
            font-family: var(--font-mono);
            border: 1px solid var(--border);
        }

        /* ═══════ FORMS ═══════ */
        .form-group { margin-bottom: 0.95rem; }
        .form-group label {
            display: block;
            font-size: 0.78rem;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 0.35rem;
            letter-spacing: -0.005em;
        }
        .form-group label .opt {
            color: var(--text-tertiary);
            font-weight: 400;
            font-size: 0.7rem;
            margin-left: 0.25rem;
        }

        .form-input {
            width: 100%;
            padding: 0.62rem 0.85rem;
            font-size: 0.875rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-input);
            background: var(--bg-elevated);
            color: var(--text-primary);
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            font-family: var(--font-ui);
            outline: none;
        }
        .form-input::placeholder { color: var(--text-tertiary); }
        .form-input:hover { border-color: var(--border-strong); }
        .form-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0, 212, 255, 0.15);
            background: rgba(255, 255, 255, 0.02);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            cursor: pointer;
        }
        .form-check input[type=checkbox] {
            width: 16px; height: 16px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        /* ═══════ CALLOUTS ═══════ */
        .callout {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            padding: 0.75rem 0.95rem;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-bottom: 1.1rem;
            line-height: 1.5;
            border: 1px solid;
        }
        .callout .c-icon { flex-shrink: 0; font-size: 1rem; margin-top: 1px; }
        .callout.info { background: rgba(0, 212, 255, 0.06); border-color: rgba(0, 212, 255, 0.2); color: var(--text-secondary); }
        .callout.warn { background: var(--warn-bg); border-color: rgba(245, 158, 11, 0.25); color: var(--text-secondary); }
        .callout.success { background: var(--success-bg); border-color: rgba(16, 185, 129, 0.25); color: var(--text-secondary); }
        .callout strong { color: var(--text-primary); }

        /* ═══════ BUTTONS ═══════ */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.6rem 1.4rem;
            border-radius: var(--radius-input);
            font-size: 0.86rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
            font-family: var(--font-ui);
            letter-spacing: -0.005em;
        }

        .btn-primary {
            background: var(--accent);
            color: #000;
            box-shadow: 0 0 0 0 var(--accent-glow);
        }
        .btn-primary:hover {
            background: #33ddff;
            box-shadow: 0 4px 24px var(--accent-glow);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--surface);
            color: var(--text-secondary);
            border-color: var(--border);
        }
        .btn-secondary:hover {
            background: var(--surface-hover);
            color: var(--text-primary);
            border-color: var(--border-strong);
        }

        .btn-danger {
            background: var(--danger-bg);
            color: var(--danger);
            border-color: rgba(244, 63, 94, 0.25);
        }
        .btn-danger:hover { background: rgba(244, 63, 94, 0.18); }

        .btn-outline {
            background: transparent;
            color: var(--accent);
            border-color: rgba(0, 212, 255, 0.4);
            width: 100%;
        }
        .btn-outline:hover {
            background: rgba(0, 212, 255, 0.08);
            border-color: var(--accent);
        }

        .btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .btn-loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }
        .btn-loading::after {
            content: '';
            position: absolute;
            width: 16px; height: 16px;
            border: 2px solid rgba(0, 0, 0, 0.2);
            border-top-color: #000;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        .btn-secondary.btn-loading::after,
        .btn-outline.btn-loading::after {
            border-color: rgba(255, 255, 255, 0.2);
            border-top-color: var(--text-primary);
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        @keyframes pulseGlow {
            0%   { box-shadow: 0 0 0 0 var(--accent-glow); }
            70%  { box-shadow: 0 0 0 12px rgba(0, 212, 255, 0); }
            100% { box-shadow: 0 0 0 0 rgba(0, 212, 255, 0); }
        }
        .btn-pulse { animation: pulseGlow 2s infinite; }

        .btn-success-state {
            background: var(--success-bg) !important;
            color: var(--success) !important;
            border-color: rgba(16, 185, 129, 0.3) !important;
            opacity: 1 !important;
            cursor: default !important;
        }

        .btn-block { width: 100%; }
        .btn-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.3rem;
            gap: 1rem;
        }

        /* ═══════ PASSWORD METER ═══════ */
        .pw-meter-wrap { margin-top: 0.45rem; }
        .pw-meter-wrap .pw-label {
            font-size: 0.7rem;
            color: var(--text-tertiary);
            margin-bottom: 4px;
            font-family: var(--font-mono);
        }
        .pw-meter-wrap .pw-label strong { transition: color 0.2s; }

        .pw-bar { display: flex; gap: 3px; }
        .pw-bar .seg {
            height: 3px;
            flex: 1;
            border-radius: 2px;
            background: var(--border);
            transition: background 0.3s;
        }

        /* ═══════ COMPLETION ═══════ */
        .completion-hero {
            text-align: center;
            padding: 0.5rem 0 0.25rem;
        }
        .check-anim { width: 72px; height: 72px; margin: 0 auto 1rem; }
        .check-anim svg { width: 100%; height: 100%; filter: drop-shadow(0 0 12px rgba(16, 185, 129, 0.5)); }
        .check-anim .circle {
            stroke-dasharray: 166;
            stroke-dashoffset: 166;
            animation: draw 0.6s 0.3s forwards;
        }
        .check-anim .tick {
            stroke-dasharray: 48;
            stroke-dashoffset: 48;
            animation: draw 0.3s 0.9s forwards;
        }
        @keyframes draw { to { stroke-dashoffset: 0; } }

        .sec-list {
            list-style: none;
            padding: 0;
            margin: 0 0 1.25rem;
        }
        .sec-list li {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            padding: 0.55rem 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.825rem;
            color: var(--text-secondary);
        }
        .sec-list li:last-child { border-bottom: none; }
        .sec-list li code {
            background: var(--bg-elevated);
            padding: 1px 6px;
            border-radius: 4px;
            color: var(--accent);
            border: 1px solid var(--border);
        }

        /* ═══════ FOOTER ═══════ */
        .app-footer {
            padding: 1.5rem 1.5rem;
            text-align: center;
            border-top: 1px solid var(--border);
            background: rgba(10, 10, 10, 0.5);
        }
        .app-footer .ft-links {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .app-footer .ft-links a {
            font-size: 0.78rem;
            color: var(--text-tertiary);
            font-weight: 500;
        }
        .app-footer .ft-links a:hover { color: var(--text-primary); opacity: 1; }

        .app-footer .ft-copy {
            font-size: 0.7rem;
            color: var(--text-tertiary);
            font-family: var(--font-mono);
        }
        .app-footer .ft-sec {
            font-size: 0.7rem;
            color: var(--text-tertiary);
            margin-top: 0.25rem;
        }

        /* ═══════ RESPONSIVE ═══════ */
        @media(max-width: 640px) {
            .app-header { padding: 0 1rem; }
            .app-header .brand-name span { display: none; }
            .env-badges { display: none; }
            .app-main { padding: 1.5rem 0.75rem 2rem; }
            .wiz-hd, .wiz-bd { padding: 1.25rem; }
            .form-row { grid-template-columns: 1fr; }
            .stepper .st .txt { font-size: 0.65rem; }
            .stepper .st .num { width: 32px; height: 32px; font-size: 0.75rem; }
            .stepper .st:not(:last-child)::after {
                top: 16px;
                left: calc(50% + 20px);
                width: calc(100% - 40px);
            }
        }
    </style>
</head>

<body>

    <!-- ═══ HEADER ═══ -->
    <header class="app-header">
        <div class="brand">
            <div class="brand-mark">OP</div>
            <div class="brand-name">Own Pay <span>· Setup</span></div>
        </div>
        <div class="env-badges">
            <span class="env-badge">PHP <?= PHP_VERSION ?></span>
            <span class="env-badge"><?= PHP_OS ?></span>
        </div>
    </header>

    <!-- ═══ MAIN ═══ -->
    <div class="app-main">
        <div class="wizard">

            <!-- Stepper -->
            <div class="stepper">
                <div class="st active" data-step="1">
                    <div class="num">1</div>
                    <div class="txt">Requirements</div>
                </div>
                <div class="st" data-step="2">
                    <div class="num">2</div>
                    <div class="txt">Database</div>
                </div>
                <div class="st" data-step="3">
                    <div class="num">3</div>
                    <div class="txt">Account</div>
                </div>
                <div class="st" data-step="4">
                    <div class="num">4</div>
                    <div class="txt">Done</div>
                </div>
            </div>

            <!-- ═══ STEP 1: REQUIREMENTS ═══ -->
            <div class="wiz-card on" id="page1">
                <div class="wiz-hd">
                    <div class="icon-badge cyan">⚡</div>
                    <h2>Environment Check</h2>
                    <p>Verifying your server meets the prerequisites for a secure install.</p>
                </div>
                <div class="wiz-bd">
                    <?php if ($allPassed): ?>
                        <div class="callout success">
                            <span class="c-icon">✓</span>
                            <span>All <strong><?= $totalCount ?> checks passed</strong>. Your server is ready.</span>
                        </div>
                    <?php else: ?>
                        <div class="callout warn">
                            <span class="c-icon">⚠</span>
                            <span><strong><?= ($totalCount - $passCount) ?> requirement(s) failed.</strong> Enable the missing extensions before continuing.</span>
                        </div>
                    <?php endif; ?>
                    <?php
                        // HTTPS warning: production installs over HTTP leak the admin password
                        // and DB credentials in transit. Skip the warning for localhost dev installs.
                        $_isHttps = (
                            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
                            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                        );
                        $_host = $_SERVER['HTTP_HOST'] ?? '';
                        $_isLocal = (
                            $_host === 'localhost'
                            || str_starts_with($_host, 'localhost:')
                            || str_starts_with($_host, '127.0.0.1')
                            || str_starts_with($_host, '[::1]')
                        );
                        if (!$_isHttps && !$_isLocal):
                    ?>
                        <div class="callout warn">
                            <span class="c-icon">🔓</span>
                            <span><strong>Insecure connection.</strong> This installer is being served over <code class="mono">HTTP</code>. Your admin password and database credentials will be transmitted in plaintext. Enable HTTPS / TLS before completing the install on a production server.</span>
                        </div>
                    <?php endif; ?>

                    <div class="req-list">
                        <?php foreach ($requirements as $req): $ok = $req['check']; ?>
                            <div class="req-item">
                                <div>
                                    <div class="ri-name"><?= htmlspecialchars($req['name']) ?></div>
                                    <div class="ri-meta"><?= htmlspecialchars($req['required']) ?> · <?= htmlspecialchars($req['current']) ?></div>
                                </div>
                                <span class="req-badge <?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? '✓ pass' : '✗ fail' ?></span>
                            </div>
                            <?php if (!$ok && isset($fixGuides[$req['name']])): ?>
                                <details class="fix-box">
                                    <summary>How to enable <?= htmlspecialchars($req['name']) ?></summary>
                                    <pre><?= htmlspecialchars($fixGuides[$req['name']]) ?></pre>
                                </details>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="btn-actions">
                        <button class="btn btn-secondary" disabled>← Back</button>
                        <?php if (!$satisfied_btn): ?>
                            <button class="btn btn-danger" onclick="location.reload()">⟳ Re-check</button>
                        <?php else: ?>
                            <button class="btn btn-primary" id="btnCheckRequirements">Continue →</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ═══ STEP 2: DATABASE ═══ -->
            <div class="wiz-card" id="page2">
                <div class="wiz-hd">
                    <div class="icon-badge cyan">⌥</div>
                    <h2>Database Connection</h2>
                    <p>Connect to MySQL or MariaDB. Your credentials are written to <code class="mono">op-config.php</code> locally and never transmitted.</p>
                </div>
                <div class="wiz-bd">
                    <form id="dbForm" autocomplete="off">
                        <div class="form-group">
                            <label class="form-check">
                                <input type="checkbox" name="dbDriver" value="mysql" checked>
                                MySQL / MariaDB
                            </label>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Host</label>
                                <input type="text" class="form-input mono" id="dbHost" value="localhost" placeholder="localhost">
                            </div>
                            <div class="form-group">
                                <label>Port</label>
                                <input type="text" class="form-input mono" id="dbPort" value="3306" placeholder="3306">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Database</label>
                                <input type="text" class="form-input mono" id="dbName" placeholder="ownpay" required>
                            </div>
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" class="form-input mono" id="dbUsername" placeholder="root" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" class="form-input mono" id="dbPassword" placeholder="••••••••">
                        </div>
                        <div class="form-group">
                            <label>Table prefix <span class="opt">optional</span></label>
                            <input type="text" class="form-input mono" id="tablePrefix" value="op_" placeholder="op_">
                        </div>
                        <button type="button" class="btn btn-outline" id="btnTestConnection">⚡ Connect & Import Schema</button>
                    </form>

                    <div class="btn-actions">
                        <button class="btn btn-secondary" id="btnPrevToReq">← Back</button>
                        <button class="btn btn-primary" id="btnNextToAdmin" disabled>Continue →</button>
                    </div>
                </div>
            </div>

            <!-- ═══ STEP 3: ADMIN ═══ -->
            <div class="wiz-card" id="page3">
                <div class="wiz-hd">
                    <div class="icon-badge purple">◇</div>
                    <h2>Super Admin Account</h2>
                    <p>Create the primary owner account with full access to all operations.</p>
                </div>
                <div class="wiz-bd">
                    <div class="callout warn">
                        <span class="c-icon">⚠</span>
                        <span>Use a <strong>strong, unique password</strong>. This account has full access.</span>
                    </div>

                    <form id="adminForm" autocomplete="off">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full name</label>
                                <input type="text" class="form-input" id="adminName" name="adminName" placeholder="Jane Doe" required>
                            </div>
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" class="form-input mono" id="adminUsername" name="adminUsername" placeholder="admin" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" class="form-input mono" id="adminEmail" name="adminEmail" placeholder="admin@example.com" required>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" class="form-input mono" id="adminPassword" name="adminPassword" placeholder="Min 8 chars · mixed case · numbers · symbols" required>
                            <div class="pw-meter-wrap">
                                <div class="pw-label">strength: <strong id="pwText" style="color:var(--text-tertiary)">—</strong></div>
                                <div class="pw-bar">
                                    <div class="seg" id="s1"></div><div class="seg" id="s2"></div>
                                    <div class="seg" id="s3"></div><div class="seg" id="s4"></div>
                                    <div class="seg" id="s5"></div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Confirm password</label>
                            <input type="password" class="form-input mono" id="confirmPassword" name="confirmPassword" placeholder="Re-enter password" required>
                            <div id="pwMatch" style="margin-top:5px;font-size:.75rem;font-family:var(--font-mono)"></div>
                        </div>
                        <div style="display:flex;justify-content:flex-end;margin-top:1.25rem">
                            <button type="submit" class="btn btn-primary" id="btnComplete">Complete Install →</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ═══ STEP 4: DONE ═══ -->
            <div class="wiz-card" id="page4">
                <div class="wiz-hd" style="border-bottom:none">
                    <div class="completion-hero">
                        <div class="check-anim">
                            <svg viewBox="0 0 52 52">
                                <circle class="circle" cx="26" cy="26" r="25" fill="none" stroke="#10b981" stroke-width="2" />
                                <path class="tick" fill="none" stroke="#10b981" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" d="M14.1 27.2l7.1 7.2 16.7-16.8" />
                            </svg>
                        </div>
                        <h2>Install complete</h2>
                        <p>Own Pay is configured and ready to launch.</p>
                    </div>
                </div>
                <div class="wiz-bd" style="padding-top:0">
                    <div class="callout warn">
                        <span class="c-icon">🔒</span>
                        <span><strong>Post-install hardening</strong> — complete before going to production.</span>
                    </div>
                    <ul class="sec-list">
                        <li><span>›</span> Run <code>composer install --no-dev --optimize-autoloader</code> via SSH</li>
                        <li><span>›</span> Delete the <code>app/install/</code> directory</li>
                        <li><span>›</span> Set permissions: <code>644</code> files, <code>755</code> dirs, <code>640</code> on <code>op-config.php</code></li>
                        <li><span>›</span> Enable HTTPS / TLS on your domain</li>
                        <li><span>›</span> Configure automated daily database backups</li>
                        <li><span>›</span> Add gateway API keys via Settings panel</li>
                    </ul>
                    <a href="login" class="btn btn-primary btn-block" style="padding:.7rem">Go to Admin Dashboard →</a>
                </div>
            </div>

        </div><!-- .wizard -->
    </div><!-- .app-main -->

    <!-- ═══ FOOTER ═══ -->
    <footer class="app-footer">
        <div class="ft-links">
            <a href="https://ownpay.org" target="_blank" rel="noopener">ownpay.org</a>
            <a href="https://github.com/own-pay/ownpay" target="_blank" rel="noopener">GitHub</a>
            <a href="https://github.com/own-pay/ownpay/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a>
        </div>
        <div class="ft-copy">© <?= date('Y') ?> Own Pay · Open Source Payment Platform</div>
        <div class="ft-sec">AES-256 + bcrypt · Field-level PII encryption</div>
    </footer>

    <!-- ═══ SCRIPTS ═══ -->
    <script nonce="<?= $csp_nonce ?? '' ?>" src="<?= $site_url ?>assets/js/custom-toast.js?v=1.2"></script>
    <script nonce="<?= $csp_nonce ?? '' ?>">
        (function () {
            let cur = 1;
            function go(step) {
                document.querySelectorAll('.wiz-card').forEach(c => c.classList.remove('on'));
                const p = document.getElementById('page' + step);
                if (p) p.classList.add('on');
                document.querySelectorAll('.stepper .st').forEach(s => {
                    s.classList.remove('active', 'done');
                    const n = parseInt(s.dataset.step);
                    if (n < step) s.classList.add('done');
                    else if (n === step) s.classList.add('active');
                });
                cur = step;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            // Navigation buttons
            document.getElementById('btnCheckRequirements')?.addEventListener('click', () => go(2));
            document.getElementById('btnPrevToReq')?.addEventListener('click', () => go(1));
            document.getElementById('btnNextToAdmin')?.addEventListener('click', () => go(3));

            // Resume from temp config (mid-install)
            <?php if (file_exists(__DIR__ . '/../../op-temp-config.php')): ?>
                go(3);
            <?php endif; ?>

            // ── Test DB connection + import schema ──
            const testBtn = document.getElementById('btnTestConnection');
            if (testBtn) {
                testBtn.addEventListener('click', function () {
                    const drv = document.querySelector('input[name="dbDriver"]:checked');
                    if (!drv) {
                        createToast({ title: 'Error', description: 'Select a database driver.', svg: errSvg(), timeout: 5000, top: 20 });
                        return;
                    }
                    const fd = new URLSearchParams();
                    fd.append('test_databse_request', 'true');
                    fd.append('dbDriver', drv.value);
                    ['dbHost', 'dbPort', 'dbName', 'dbUsername', 'dbPassword', 'tablePrefix']
                        .forEach(id => fd.append(id, document.getElementById(id).value));
                    testBtn.classList.add('btn-loading');
                    testBtn.disabled = true;
                    fetch('install', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: fd.toString()
                    })
                        .then(r => r.json())
                        .then(d => {
                            testBtn.classList.remove('btn-loading');
                            testBtn.disabled = false;
                            if (d.status === true || d.status === 'true') {
                                const nxtBtn = document.getElementById('btnNextToAdmin');
                                nxtBtn.disabled = false;
                                nxtBtn.classList.add('btn-pulse');
                                testBtn.innerHTML = '✓ Connected & Imported';
                                testBtn.classList.remove('btn-outline');
                                testBtn.classList.add('btn-success-state');
                                testBtn.disabled = true;
                                createToast({ title: d.title, description: d.message, svg: okSvg(), timeout: 5000, top: 20 });
                            } else {
                                createToast({ title: 'Error', description: d.message, svg: errSvg(), timeout: 5000, top: 20 });
                            }
                        })
                        .catch(() => {
                            testBtn.classList.remove('btn-loading');
                            testBtn.disabled = false;
                            createToast({ title: 'Error', description: 'Network error.', svg: errSvg(), timeout: 5000, top: 20 });
                        });
                });
            }

            // ── Password strength meter ──
            const pwIn  = document.getElementById('adminPassword');
            const pwTxt = document.getElementById('pwText');
            const cols = ['', '#f43f5e', '#f59e0b', '#eab308', '#84cc16', '#10b981'];
            const lbls = ['—', 'very weak', 'weak', 'fair', 'strong', 'excellent'];
            if (pwIn) {
                pwIn.addEventListener('input', function () {
                    let s = 0;
                    if (this.value.length >= 8)   s++;
                    if (/[A-Z]/.test(this.value)) s++;
                    if (/[a-z]/.test(this.value)) s++;
                    if (/[0-9]/.test(this.value)) s++;
                    if (/[\W]/.test(this.value))  s++;
                    pwTxt.textContent = lbls[s];
                    pwTxt.style.color = cols[s] || 'var(--text-tertiary)';
                    for (let i = 1; i <= 5; i++) {
                        document.getElementById('s' + i).style.background = i <= s ? cols[s] : 'var(--border)';
                    }
                });
            }

            // ── Confirm-password match indicator ──
            const cfIn  = document.getElementById('confirmPassword');
            const pmDiv = document.getElementById('pwMatch');
            if (cfIn) {
                cfIn.addEventListener('input', function () {
                    if (!this.value) { pmDiv.innerHTML = ''; return; }
                    pmDiv.innerHTML = this.value === pwIn.value
                        ? '<span style="color:var(--success)">✓ passwords match</span>'
                        : '<span style="color:var(--danger)">✗ passwords do not match</span>';
                });
            }

            // ── Admin form submit ──
            const aForm = document.getElementById('adminForm');
            if (aForm) {
                aForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const btn = document.getElementById('btnComplete');
                    btn.classList.add('btn-loading');
                    btn.disabled = true;
                    const fd = new URLSearchParams();
                    new FormData(aForm).forEach((v, k) => fd.append(k, v));
                    fetch('install', {
                        method: 'POST',
                        body: fd,
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                    })
                        .then(r => r.json())
                        .then(d => {
                            btn.classList.remove('btn-loading');
                            btn.disabled = false;
                            if (d.status === 'true' || d.status === true) go(4);
                            else createToast({ title: 'Error', description: d.message, svg: errSvg(), timeout: 5000, top: 20 });
                        })
                        .catch(() => {
                            btn.classList.remove('btn-loading');
                            btn.disabled = false;
                            createToast({ title: 'Error', description: 'Network error.', svg: errSvg(), timeout: 5000, top: 20 });
                        });
                });
            }

            function okSvg() {
                return `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2l4 -4"/></svg>`;
            }
            function errSvg() {
                return `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f43f5e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 9v4"/><path d="M12 16h.01"/></svg>`;
            }
        })();
    </script>
</body>

</html>
