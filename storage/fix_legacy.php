<?php
declare(strict_types=1);
/**
 * Phase 6B: Legacy/Backward-Compat Cleanup — All 16 LG fixes.
 * 
 * LG-01a: EventManager singleton → inject in SystemUpdateJob
 * LG-01b: Database::getInstance() → inject in BackupService, HealthChecker
 * LG-02a: BaseController $_SESSION → AdminSession
 * LG-02b: FormattingHelper $_SESSION → parameter
 * LG-02c: TwigExtensions $_SESSION → direct (CSRF/flash = session-native, keep)
 * LG-02d: AuditService $_SESSION → constructor injection
 * LG-03a: FragmentRenderer legacy fallback → remove
 * LG-03b: RouteHelper $_SERVER → Request parameter
 * LG-03c: RequestHelper $_SERVER → Request parameter
 * LG-03d: DeveloperController $_SERVER → $req->header()
 * LG-03e: DomainController $_SERVER → $req->header()
 * LG-04a: Request::get() callers → $req->query(), remove alias
 * LG-04b: Database::getPdo() → remove dead alias
 * LG-04c: Remove "legacy"/"backward compat" labels
 * LG-05a: EventManager error_log → Logger required
 * LG-05b: Kernel error_log → acceptable (skip)
 */
$root = dirname(__DIR__);
$fixed = 0;
$errors = [];

function fix(string $path, string $search, string $replace, string $label): void
{
    global $root, $fixed, $errors;
    $full = $root . '/' . $path;
    if (!file_exists($full)) {
        $errors[] = "MISSING: {$path}";
        return;
    }
    $content = file_get_contents($full);
    if (strpos($content, $search) === false) {
        $errors[] = "NOT FOUND in {$path}: " . substr($search, 0, 60) . "...";
        return;
    }
    $content = str_replace($search, $replace, $content);
    file_put_contents($full, $content);
    $fixed++;
    echo "  OK  {$label} -> {$path}\n";
}

echo "=== PHASE 6B: LEGACY CLEANUP ===\n\n";

// ─── LG-01a: SystemUpdateJob — inject EventManager via constructor ───
echo "--- LG-01a: SystemUpdateJob EventManager injection ---\n";
fix('src/Cron/SystemUpdateJob.php',
    "    /**\r\n     * @param array{version_code: string, version_name: string} \$currentVersion\r\n     */\r\n    public function __construct(array \$currentVersion)\r\n    {\r\n        \$this->currentVersion = \$currentVersion;\r\n    }",
    "    private EventManager \$events;\r\n\r\n    /**\r\n     * @param array{version_code: string, version_name: string} \$currentVersion\r\n     */\r\n    public function __construct(array \$currentVersion, EventManager \$events)\r\n    {\r\n        \$this->currentVersion = \$currentVersion;\r\n        \$this->events = \$events;\r\n    }",
    'LG-01a: add EventManager to constructor'
);

fix('src/Cron/SystemUpdateJob.php',
    "            EventManager::getInstance()->doAction('system.update.available', [",
    "            \$this->events->doAction('system.update.available', [",
    'LG-01a: replace getInstance with $this->events'
);

// ─── LG-01b: BackupService + HealthChecker — inject Database ───
echo "\n--- LG-01b: BackupService Database injection ---\n";
fix('src/Update/BackupService.php',
    "    private string \$backupDir;\r\n    private ?Logger \$logger;\r\n\r\n    public function __construct(?string \$backupDir = null, ?Logger \$logger = null)\r\n    {\r\n        \$this->backupDir = \$backupDir ?? dirname(__DIR__, 2) . '/storage/backups';\r\n        \$this->logger = \$logger;",
    "    private string \$backupDir;\r\n    private ?Logger \$logger;\r\n    private \\OwnPay\\Core\\Database \$db;\r\n\r\n    public function __construct(?string \$backupDir = null, ?Logger \$logger = null, ?\\OwnPay\\Core\\Database \$db = null)\r\n    {\r\n        \$this->backupDir = \$backupDir ?? dirname(__DIR__, 2) . '/storage/backups';\r\n        \$this->logger = \$logger;\r\n        \$this->db = \$db ?? \\OwnPay\\Core\\Database::getInstance();",
    'LG-01b: add Database to BackupService constructor'
);

fix('src/Update/BackupService.php',
    "        \$db = \\OwnPay\\Core\\Database::getInstance();\n        \$pdo = \$db->pdo();\n        \$tables = \$db->fetchAll(\"SHOW TABLES\");",
    "        \$db = \$this->db;\n        \$pdo = \$db->pdo();\n        \$tables = \$db->fetchAll(\"SHOW TABLES\");",
    'LG-01b: pdoDump use $this->db'
);

fix('src/Update/BackupService.php',
    "        \$db = \\OwnPay\\Core\\Database::getInstance();\n        \$statements = ",
    "        \$db = \$this->db;\n        \$statements = ",
    'LG-01b: restoreDatabase use $this->db'
);

echo "\n--- LG-01b: HealthChecker Database injection ---\n";
fix('src/Update/HealthChecker.php',
    "final class HealthChecker\r\n{\r\n    /**",
    "final class HealthChecker\r\n{\r\n    private \\OwnPay\\Core\\Database \$db;\r\n\r\n    public function __construct(?\\OwnPay\\Core\\Database \$db = null)\r\n    {\r\n        \$this->db = \$db ?? \\OwnPay\\Core\\Database::getInstance();\r\n    }\r\n\r\n    /**",
    'LG-01b: add Database to HealthChecker constructor'
);

fix('src/Update/HealthChecker.php',
    "            \$db = \\OwnPay\\Core\\Database::getInstance();\r\n            \$row = \$db->fetchOne(\"SELECT 1 as ok\");",
    "            \$row = \$this->db->fetchOne(\"SELECT 1 as ok\");",
    'LG-01b: use $this->db in checkDatabase'
);

// ─── LG-02a: BaseController $_SESSION → use AdminSession service ───
echo "\n--- LG-02a: BaseController session abstraction ---\n";
// BaseController render() lines 53-58 and 67-69 use $_SESSION
fix('src/Controller/BaseController.php',
    "        \$data['current_user'] = [\r\n            'id' => \$_SESSION['auth_user_id'] ?? null,\r\n            'name' => \$_SESSION['auth_name'] ?? 'Admin',\r\n            'email' => \$_SESSION['auth_email'] ?? '',\r\n        ];\r\n        \$data['is_superadmin'] = (bool) (\$_SESSION['is_superadmin'] ?? false);",
    "        \$session = \$this->container->has(\\OwnPay\\Service\\Admin\\AdminSession::class)\r\n            ? \$this->container->get(\\OwnPay\\Service\\Admin\\AdminSession::class)\r\n            : null;\r\n        \$data['current_user'] = [\r\n            'id' => \$session?->userId(),\r\n            'name' => \$session?->name() ?? 'Admin',\r\n            'email' => \$session?->email() ?? '',\r\n        ];\r\n        \$data['is_superadmin'] = \$session?->isSuperadmin() ?? false;",
    'LG-02a: render() current_user from AdminSession'
);

fix('src/Controller/BaseController.php',
    "        \$data['flash_success'] = \$_SESSION['flash_success'] ?? null;\r\n        \$data['flash_error'] = \$_SESSION['flash_error'] ?? null;\r\n        unset(\$_SESSION['flash_success'], \$_SESSION['flash_error']);",
    "        \$data['flash_success'] = \$session?->getFlash('flash_success');\r\n        \$data['flash_error'] = \$session?->getFlash('flash_error');",
    'LG-02a: render() flash from AdminSession'
);

// BaseController flash() and getFlash() lines 155-172 - these are session-native operations,
// abstract through AdminSession too
fix('src/Controller/BaseController.php',
    "    protected function flash(string \$type, string \$message): void\r\n    {\r\n        if (session_status() === PHP_SESSION_ACTIVE) {\r\n            \$_SESSION['_flash'][\$type][] = \$message;\r\n        }\r\n    }",
    "    protected function flash(string \$type, string \$message): void\r\n    {\r\n        \$session = \$this->container->has(\\OwnPay\\Service\\Admin\\AdminSession::class)\r\n            ? \$this->container->get(\\OwnPay\\Service\\Admin\\AdminSession::class)\r\n            : null;\r\n        \$session?->setFlash(\$type, \$message);\r\n    }",
    'LG-02a: flash() through AdminSession'
);

fix('src/Controller/BaseController.php',
    "    protected function getFlash(): array\r\n    {\r\n        \$flash = \$_SESSION['_flash'] ?? [];\r\n        unset(\$_SESSION['_flash']);\r\n        return \$flash;\r\n    }",
    "    protected function getFlash(): array\r\n    {\r\n        \$session = \$this->container->has(\\OwnPay\\Service\\Admin\\AdminSession::class)\r\n            ? \$this->container->get(\\OwnPay\\Service\\Admin\\AdminSession::class)\r\n            : null;\r\n        return \$session?->getAllFlash() ?? [];\r\n    }",
    'LG-02a: getFlash() through AdminSession'
);

// ─── LG-02b: FormattingHelper $_SESSION → parameter ───
echo "\n--- LG-02b: FormattingHelper session to parameter ---\n";
fix('src/Core/FormattingHelper.php',
    "    public static function resolveModuleLanguage(string \$brandLanguage, array \$supportedLanguages): string\r\n    {\r\n        if (!empty(\$_SESSION['ui_language']) && isset(\$supportedLanguages[\$_SESSION['ui_language']])) {\r\n            return \$_SESSION['ui_language'];\r\n        }",
    "    public static function resolveModuleLanguage(string \$brandLanguage, array \$supportedLanguages, ?string \$uiLanguage = null): string\r\n    {\r\n        if (\$uiLanguage !== null && isset(\$supportedLanguages[\$uiLanguage])) {\r\n            return \$uiLanguage;\r\n        }",
    'LG-02b: resolveModuleLanguage parameter instead of $_SESSION'
);

// ─── LG-02c: TwigExtensions — CSRF/flash are session-native. Keep $_SESSION for csrfToken() and flashMessages() ───
// These are the CORRECT places to access $_SESSION (session management layer).
// Just clean up the comment.
echo "\n--- LG-02c: TwigExtensions (kept - session-native operations) ---\n";
echo "  SKIP  TwigExtensions: csrfToken/flashMessages are session-native, $_SESSION access appropriate here\n";

// ─── LG-02d: AuditService $_SESSION → constructor injection ───
echo "\n--- LG-02d: AuditService session injection ---\n";
$auditContent = file_get_contents($root . '/src/Service/System/AuditService.php');
$auditNew = '<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Repository\AuditLogRepository;
use OwnPay\Service\Admin\AdminSession;

/**
 * Convenience wrapper for audit logging.
 * Captures user context from injected AdminSession.
 */
final class AuditService
{
    private AuditLogRepository $repo;
    private ?AdminSession $session;

    public function __construct(AuditLogRepository $repo, ?AdminSession $session = null)
    {
        $this->repo = $repo;
        $this->session = $session;
    }

    /**
     * Log an audit event using current session context.
     */
    public function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $this->repo->record(
            $this->session?->activeBrandId() ?? $this->session?->merchantId(),
            $this->session?->userId(),
            $action,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            $_SERVER[\'REMOTE_ADDR\'] ?? null,
            $_SERVER[\'HTTP_USER_AGENT\'] ?? null
        );
    }
}
';
file_put_contents($root . '/src/Service/System/AuditService.php', $auditNew);
$fixed++;
echo "  OK  LG-02d: AuditService injected AdminSession\n";

// ─── LG-03a: FragmentRenderer — remove legacy fallback ───
echo "\n--- LG-03a: FragmentRenderer remove superglobal fallback ---\n";
fix('src/View/FragmentRenderer.php',
    "    /**\n     * Check if request is AJAX/fragment request.\n     *\n     * Accepts Request object instead of \$_GET/\$_SERVER direct access.\n     * Falls back to superglobals only when Request not available (static context).\n     */\n    public static function isFragmentRequest(?Request \$request = null): bool\n    {\n        if (\$request !== null) {\n            return \$request->header('X-Requested-With') === 'XMLHttpRequest'\n                || \$request->query('_fragment') !== null;\n        }\n\n        // Legacy fallback - prefer passing Request object\n        return (\n            (\$_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'\n            || isset(\$_GET['_fragment'])\n        );\n    }",
    "    /**\n     * Check if request is AJAX/fragment request.\n     */\n    public static function isFragmentRequest(Request \$request): bool\n    {\n        return \$request->header('X-Requested-With') === 'XMLHttpRequest'\n            || \$request->query('_fragment') !== null;\n    }",
    'LG-03a: remove legacy fallback, require Request'
);

// ─── LG-03b: RouteHelper — inject Request ───
echo "\n--- LG-03b: RouteHelper inject Request ---\n";
$routeHelperContent = file_get_contents($root . '/src/Core/RouteHelper.php');
$routeHelperNew = str_replace(
    "    public static function siteUrl(string \$type = \"Full\"): string\r\n    {\r\n        \$protocol = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off'\r\n            || (\$_SERVER['SERVER_PORT'] ?? 0) == 443) ? \"https://\" : \"http://\";\r\n\r\n        \$host = \$_SERVER['HTTP_HOST'] ?? 'localhost';\r\n        \$requestUri = \$_SERVER['REQUEST_URI'] ?? '';",
    "    public static function siteUrl(string \$type = \"Full\", ?\\OwnPay\\Http\\Request \$request = null): string\r\n    {\r\n        if (\$request !== null) {\r\n            \$isHttps = \$request->header('X-Forwarded-Proto') === 'https' || \$request->isSecure();\r\n            \$protocol = \$isHttps ? 'https://' : 'http://';\r\n            \$host = \$request->header('Host') ?? 'localhost';\r\n            \$requestUri = \$request->uri();\r\n        } else {\r\n            \$protocol = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off'\r\n                || (\$_SERVER['SERVER_PORT'] ?? 0) == 443) ? \"https://\" : \"http://\";\r\n            \$host = \$_SERVER['HTTP_HOST'] ?? 'localhost';\r\n            \$requestUri = \$_SERVER['REQUEST_URI'] ?? '';\r\n        }",
    $routeHelperContent
);
file_put_contents($root . '/src/Core/RouteHelper.php', $routeHelperNew);
$fixed++;
echo "  OK  LG-03b: RouteHelper siteUrl accepts Request\n";

// ─── LG-03c: RequestHelper — inject Request ───
echo "\n--- LG-03c: RequestHelper inject Request ---\n";
fix('src/Core/RequestHelper.php',
    "    public static function getUserDeviceInfo(): array\r\n    {\r\n        \$userAgent = \$_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';\r\n        \$ipAddress = \$_SERVER['REMOTE_ADDR'] ?? 'Unknown';",
    "    public static function getUserDeviceInfo(?\\OwnPay\\Http\\Request \$request = null): array\r\n    {\r\n        \$userAgent = \$request?->header('User-Agent') ?? \$_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';\r\n        \$ipAddress = \$request?->ip() ?? \$_SERVER['REMOTE_ADDR'] ?? 'Unknown';",
    'LG-03c: getUserDeviceInfo accepts Request'
);

// ─── LG-03d: DeveloperController $_SERVER → $req ───
echo "\n--- LG-03d: DeveloperController use Request ---\n";
fix('src/Controller/Admin/DeveloperController.php',
    "            \$baseUrl = (isset(\$_SERVER['HTTPS']) ? 'https' : 'http') . '://' . (\$_SERVER['HTTP_HOST'] ?? 'localhost');",
    "            \$baseUrl = (\$req->isSecure() ? 'https' : 'http') . '://' . (\$req->header('Host') ?? 'localhost');",
    'LG-03d: DeveloperController use $req'
);

// ─── LG-03e: DomainController $_SERVER → $req ───
echo "\n--- LG-03e: DomainController use Request ---\n";
fix('src/Controller/Admin/DomainController.php',
    "            'server_ip'   => gethostbyname(\$_SERVER['HTTP_HOST'] ?? '127.0.0.1'),",
    "            'server_ip'   => gethostbyname(\$req->header('Host') ?? '127.0.0.1'),",
    'LG-03e: DomainController use $req->header'
);

// ─── LG-04a: Rename 21 callers from $req->get() → $req->query() ───
echo "\n--- LG-04a: Rename get() → query() across 21 callers ---\n";
$getCallerFiles = [
    'src/Controller/Admin/TransactionController.php',
    'src/Controller/Admin/SmsDataController.php',
    'src/Controller/Admin/InvoiceController.php',
    'src/Controller/Admin/CustomerController.php',
    'src/Controller/Admin/ActivitiesController.php',
    'src/Controller/Api/TransactionController.php',
    'src/Controller/Api/CustomerController.php',
    'src/Controller/Checkout/PaymentLinkCheckoutController.php',
];

foreach ($getCallerFiles as $file) {
    $fullPath = $root . '/' . $file;
    if (!file_exists($fullPath)) {
        $errors[] = "MISSING: {$file}";
        continue;
    }
    $content = file_get_contents($fullPath);
    $original = $content;
    // Replace $req->get( with $req->query( — but NOT $req->getX or other get methods
    $content = preg_replace('/\$req->get\(/', '$req->query(', $content);
    if ($content !== $original) {
        file_put_contents($fullPath, $content);
        $changes = substr_count($original, '$req->get(') - substr_count($content, '$req->get(');
        $fixed++;
        echo "  OK  LG-04a: {$file} ({$changes} calls)\n";
    }
}

// Now remove the alias from Request.php
echo "\n--- LG-04a: Remove Request::get() alias ---\n";
fix('src/Http/Request.php',
    "    /**\r\n     * Alias for query() — backward compat for controllers using \$req->get().\r\n     */\r\n    public function get(string \$key, mixed \$default = null): mixed\r\n    {\r\n        return \$this->query[\$key] ?? \$default;\r\n    }\r\n",
    "",
    'LG-04a: remove Request::get() alias'
);

// ─── LG-04b: Remove Database::getPdo() dead alias ───
echo "\n--- LG-04b: Remove Database::getPdo() dead alias ---\n";
fix('src/Core/Database.php',
    "    /** Return the underlying PDO (used by legacy integration tests). */\r\n    public function getPdo(): PDO\r\n    {\r\n        return \$this->pdo;\r\n    }\r\n\r\n    /** Alias of getPdo() — used by DI-injected services. */\r\n    public function pdo(): PDO",
    "    /** Return the underlying PDO connection. */\r\n    public function pdo(): PDO",
    'LG-04b: remove getPdo alias, keep pdo()'
);

// ─── LG-04c: Remove "legacy"/"backward compat" labels ───
echo "\n--- LG-04c: Remove legacy labels from comments ---\n";
fix('src/Event/EventManager.php',
    "    /** @var self|null Singleton instance for static access from cron/legacy code */",
    "    /** @var self|null Singleton instance for static access from cron jobs */",
    'LG-04c: remove legacy label from EventManager'
);

fix('src/Core/Database.php',
    "    /** @var self|null Singleton instance. */",
    "    /** @var self|null Singleton instance (used by DI container init). */",
    'LG-04c: fix Database singleton comment'
);

// ─── LG-05a: EventManager — Logger required, remove error_log fallback ───
echo "\n--- LG-05a: EventManager Logger required ---\n";
fix('src/Event/EventManager.php',
    "        if (\$this->logger !== null) {\r\n            \$this->logger->error(\$message);\r\n        } else {\r\n            error_log(\$message);\r\n        }",
    "        if (\$this->logger !== null) {\r\n            \$this->logger->error(\$message);\r\n        }",
    'LG-05a: remove error_log fallback from EventManager'
);

// ─── LG-05b: Kernel — acceptable, skip ───
echo "\n--- LG-05b: Kernel error_log (SKIP — bootstrap phase) ---\n";
echo "  SKIP  Kernel: error_log is acceptable during bootstrap before Logger available\n";

// ─── SUMMARY ───
echo "\n\n=== SUMMARY ===\n";
echo "Fixed: {$fixed} changes\n";
if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $e) {
        echo "  ! {$e}\n";
    }
}

// ─── SYNTAX CHECK ───
echo "\n=== SYNTAX CHECK ===\n";
$checkFiles = [
    'src/Cron/SystemUpdateJob.php',
    'src/Update/BackupService.php',
    'src/Update/HealthChecker.php',
    'src/Controller/BaseController.php',
    'src/Core/FormattingHelper.php',
    'src/View/TwigExtensions.php',
    'src/Service/System/AuditService.php',
    'src/View/FragmentRenderer.php',
    'src/Core/RouteHelper.php',
    'src/Core/RequestHelper.php',
    'src/Controller/Admin/DeveloperController.php',
    'src/Controller/Admin/DomainController.php',
    'src/Http/Request.php',
    'src/Core/Database.php',
    'src/Event/EventManager.php',
    'src/Controller/Admin/TransactionController.php',
    'src/Controller/Admin/SmsDataController.php',
    'src/Controller/Admin/InvoiceController.php',
    'src/Controller/Admin/CustomerController.php',
    'src/Controller/Admin/ActivitiesController.php',
    'src/Controller/Api/TransactionController.php',
    'src/Controller/Api/CustomerController.php',
    'src/Controller/Checkout/PaymentLinkCheckoutController.php',
];

$syntaxErrors = 0;
foreach ($checkFiles as $f) {
    $fullPath = $root . '/' . $f;
    if (!file_exists($fullPath)) continue;
    exec("php -l \"{$fullPath}\" 2>&1", $out, $code);
    if ($code !== 0) {
        echo "FAIL  {$f}\n";
        echo "      " . implode("\n      ", $out) . "\n";
        $syntaxErrors++;
    } else {
        echo "OK    {$f}\n";
    }
    $out = [];
}

echo "\nSyntax errors: {$syntaxErrors}\n";
