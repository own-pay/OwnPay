<?php
declare(strict_types=1);

if (!function_exists('ensureType')) {
    /**
     * @template T of object
     * @param mixed $object
     * @param class-string<T> $class
     * @return T
     */
    function ensureType(mixed $object, string $class): object
    {
        if (!$object instanceof $class) {
            throw new \RuntimeException("Expected instance of {$class}");
        }
        return $object;
    }
}

if (!function_exists('ensureArray')) {
    /**
     * @param mixed $value
     * @return array<mixed, mixed>
     */
    function ensureArray(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException("Expected array");
        }
        return $value;
    }
}

if (!function_exists('ensureString')) {
    function ensureString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException("Expected string");
        }
        return $value;
    }
}

if (!function_exists('ensureInt')) {
    function ensureInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        throw new \RuntimeException("Expected integer");
    }
}

/**
 * OwnPay Service Container Bindings.
 *
 * This file is invoked by the application Kernel during boot to bootstrap and register
 * all core services, repositories, handlers, and configuration dependencies into the
 * lightweight PSR-11 compatible Dependency Injection Container.
 *
 * @param \OwnPay\Container $c The application's service container instance.
 * @return void
 */
return static function (\OwnPay\Container $c): void {

    // --- Configuration
    $c->singleton('config.app', static function () {
        return require __DIR__ . '/app.php';
    });

    $c->singleton('config.database', static function () {
        return require __DIR__ . '/database.php';
    });

    // --- PDO Database Connection
    /**
     * Registers the shared \PDO database connection instance.
     *
     * Configures the connection with explicit strict-mode SQL options
     * to safeguard transactional state execution.
     *
     * @param \OwnPay\Container $c
     * @return \PDO
     */
    $c->singleton(\PDO::class, static function (\OwnPay\Container $c): \PDO {
        $cfg = ensureArray($c->get('config.database'));
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            ensureString($cfg['driver']),
            ensureString($cfg['host']),
            ensureInt($cfg['port']),
            ensureString($cfg['database']),
            ensureString($cfg['charset'])
        );
        $username = is_string($cfg['username'] ?? null) ? $cfg['username'] : null;
        $password = is_string($cfg['password'] ?? null) ? $cfg['password'] : null;
        $options  = is_array($cfg['options'] ?? null) ? $cfg['options'] : null;

        // Connection wait strategy: under transient saturation (MySQL
        // max_connections exhaustion, brief refusals, dropped connections) retry
        // a few times with linear backoff before giving up, so a short spike does
        // not immediately surface as an error. Credential/schema errors fail fast.
        $maxAttempts = max(1, (int) (getenv('DB_CONNECT_RETRIES') ?: 3));
        $pdo = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $pdo = new \PDO($dsn, $username, $password, $options);
                break;
            } catch (\PDOException $e) {
                $message = $e->getMessage();
                $transient = stripos($message, 'too many connections') !== false
                    || stripos($message, 'Connection refused') !== false
                    || stripos($message, 'server has gone away') !== false
                    || stripos($message, 'Lost connection') !== false;
                if (!$transient || $attempt === $maxAttempts) {
                    throw $e;
                }
                usleep(100000 * $attempt); // 100ms, 200ms, 300ms ... linear backoff
            }
        }
        if (!$pdo instanceof \PDO) {
            throw new \RuntimeException('Database connection could not be established.');
        }
        $pdo->exec("SET NAMES '" . ensureString($cfg['charset']) . "' COLLATE '" . ensureString($cfg['collation']) . "'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    });

    $c->singleton(\OwnPay\Core\Database::class, static function (\OwnPay\Container $c): \OwnPay\Core\Database {
        $db = new \OwnPay\Core\Database(ensureType($c->get(\PDO::class), \PDO::class));
        \OwnPay\Core\Database::setInstance($db);
        return $db;
    });

    // --- Event Manager
    $c->singleton(\OwnPay\Event\EventManager::class, static function (\OwnPay\Container $c): \OwnPay\Event\EventManager {
        $events = new \OwnPay\Event\EventManager();
        $events->setContainer($c);
        return $events;
    });

    // --- Cache
    $c->singleton(\OwnPay\Cache\CacheInterface::class, static function (\OwnPay\Container $c): \OwnPay\Cache\CacheInterface {
        $appCfg = ensureArray($c->get('config.app'));
        $driver = $appCfg['cache_driver'] ?? 'file';
        if ($driver === 'redis' && class_exists(\Redis::class)) {
            try {
                return new \OwnPay\Cache\RedisCache(
                    getenv('REDIS_HOST') ?: '127.0.0.1',
                    (int) (getenv('REDIS_PORT') ?: 6379),
                    getenv('REDIS_PREFIX') ?: 'op:'
                );
            } catch (\Throwable) {
                // Graceful fallback to file cache
            }
        }
        $paths = ensureArray($appCfg['paths'] ?? null);
        return new \OwnPay\Cache\FileCache(
            ensureString($paths['cache'] ?? '')
        );
    });

    // --- Queue
    $c->singleton(\OwnPay\Queue\QueueInterface::class, static function (\OwnPay\Container $c): \OwnPay\Queue\QueueInterface {
        $appCfg = ensureArray($c->get('config.app'));
        $driver = $appCfg['queue_driver'] ?? 'file';
        if ($driver === 'redis' && class_exists(\Redis::class)) {
            try {
                return new \OwnPay\Queue\RedisQueue(
                    getenv('REDIS_HOST') ?: '127.0.0.1',
                    (int) (getenv('REDIS_PORT') ?: 6379),
                    getenv('REDIS_PREFIX') ?: 'op:queue:'
                );
            } catch (\Throwable) {
                // Graceful fallback to file queue
            }
        }
        $paths = ensureArray($appCfg['paths'] ?? null);
        return new \OwnPay\Queue\FileQueue(
            ensureString($paths['queue'] ?? '')
        );
    });

    // --- Router
    $c->singleton(\OwnPay\Http\Router::class, static function (\OwnPay\Container $c): \OwnPay\Http\Router {
        return new \OwnPay\Http\Router($c);
    });

    // Twig
    /**
     * Registers the Twig Environment rendering engine.
     *
     * Configures template loaders, filesystem overrides, autoescape,
     * lazy-loaded global CSRF variables, and CSP nonces.
     *
     * @param \OwnPay\Container $c
     * @return \Twig\Environment
     */
    $c->singleton(\Twig\Environment::class, static function (\OwnPay\Container $c): \Twig\Environment {
        $appCfg = ensureArray($c->get('config.app'));
        $paths = ensureArray($appCfg['paths'] ?? null);
        /** @var array<int, string> $templatePaths */
        $templatePaths = [
            ensureString($paths['templates'] ?? '')
        ];
        $loader = new \Twig\Loader\FilesystemLoader($templatePaths);
        // Add module theme paths for overrides
        $themesDir = ensureString($paths['modules'] ?? '') . '/themes';
        if (is_dir($themesDir)) {
            foreach (glob($themesDir . '/*/templates') ?: [] as $themeTemplateDir) {
                $themeName = basename(dirname($themeTemplateDir));
                $loader->addPath($themeTemplateDir, $themeName);
            }
        }
        $twig = new \Twig\Environment($loader, [
            'cache'       => ensureString($paths['cache'] ?? '') . '/twig',
            'auto_reload' => (bool) ($appCfg['debug'] ?? false),
            'strict_variables' => true,
            'autoescape'  => 'html',
        ]);
        // Register core extensions
        if (class_exists(\OwnPay\View\TwigExtensions::class)) {
            $twig->addExtension(new \OwnPay\View\TwigExtensions($c));
        }
        // Register CoreExtension - provides ownpay_footer(), ownpay_meta()
        $appVersion = ensureString($appCfg['version'] ?? '0.1.0');
        $appUrlRaw = $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? getenv('APP_URL') ?: '';
        $appUrl = rtrim(is_string($appUrlRaw) ? $appUrlRaw : '', '/');
        $twig->addExtension(new \OwnPay\View\TwigExtension\CoreExtension($appVersion, $appUrl));
        
        /**
         * CSRF token must be read lazily at render time, NOT at container build time.
         * At container build, session may not be started yet → token would be empty string.
         * Use a __toString() proxy so Twig reads session when rendering {{ csrf_token }}.
         */
        $twig->addGlobal('csrf_token', new class implements \Stringable {
            public function __toString(): string
            {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    if (empty($_SESSION['_csrf_token'])) {
                        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
                    }
                    $token = $_SESSION['_csrf_token'] ?? '';
                    return is_string($token) ? $token : '';
                }
                return '';
            }
        });
        $appName = $_ENV['APP_NAME'] ?? 'OwnPay';
        $twig->addGlobal('app_name', is_string($appName) ? $appName : 'OwnPay');
        // i18n dynamic translation setup
        $twig->addFunction(new \Twig\TwigFunction('__', function (string $key, array $replace = []) use ($c): string {
            $trans = $c->get(\OwnPay\Service\System\TranslationService::class);
            if ($trans instanceof \OwnPay\Service\System\TranslationService) {
                return $trans->trans($key, $replace);
            }
            return $key;
        }));

        $twig->addFunction(new \Twig\TwigFunction('locale', function () use ($c): string {
            $trans = $c->get(\OwnPay\Service\System\TranslationService::class);
            if ($trans instanceof \OwnPay\Service\System\TranslationService) {
                return $trans->getLocale();
            }
            return 'en';
        }));

        $twig->addFilter(new \Twig\TwigFilter('__', function (string $key, array $replace = []) use ($c): string {
            $trans = $c->get(\OwnPay\Service\System\TranslationService::class);
            if ($trans instanceof \OwnPay\Service\System\TranslationService) {
                return $trans->trans($key, $replace);
            }
            return $key;
        }));

        $twig->addGlobal('lang', new class($c) implements \ArrayAccess {
            private \OwnPay\Container $c;
            public function __construct(\OwnPay\Container $c) { $this->c = $c; }
            public function offsetExists(mixed $offset): bool { return true; }
            public function offsetGet(mixed $offset): mixed {
                $trans = $this->c->get(\OwnPay\Service\System\TranslationService::class);
                if ($trans instanceof \OwnPay\Service\System\TranslationService) {
                    $offsetStr = is_scalar($offset) ? (string)$offset : '';
                    $value = $trans->trans($offsetStr);
                    // trans() echoes the key back when it has no translation. Return null in
                    // that case so template `lang.x ?? 'Fallback'` expressions use their fallback
                    // instead of rendering the raw key (e.g. "paying_to") on the checkout page.
                    return $value === $offsetStr ? null : $value;
                }
                return null;
            }
            public function offsetSet(mixed $offset, mixed $value): void {}
            public function offsetUnset(mixed $offset): void {}
        });

        /**
         * Expose CSP nonce to all templates.
         * SecurityHeadersMiddleware stores nonce in Container as 'csp_nonce'.
         * Use lazy proxy since nonce isn't generated until middleware runs.
         */
        $twig->addGlobal('csp_nonce', new class($c) implements \Stringable {
            private \OwnPay\Container $c;
            public function __construct(\OwnPay\Container $c) { $this->c = $c; }
            public function __toString(): string
            {
                return $this->c->has('csp_nonce') && is_string($n = $this->c->get('csp_nonce')) ? $n : '';
            }
        });
        return $twig;
    });

    // --- Smart SMS Analyzer
    $c->singleton(\OwnPay\Service\Sms\SmartSmsAnalyzer::class, static function (): \OwnPay\Service\Sms\SmartSmsAnalyzer {
        return new \OwnPay\Service\Sms\SmartSmsAnalyzer();
    });

    // --- SMS Parser Orchestrator
    // SmsParserService has an intentionally untyped constructor (test-double friendly), so the
    // container cannot autowire it. It MUST be registered explicitly or Api\Mobile\SmsController
    // (POST /api/mobile/v1/sms ingestion + GET /api/mobile/v1/sms/queues) fails to resolve at runtime.
    $c->singleton(\OwnPay\Service\Sms\SmsParserService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Sms\SmsParserService {
        return new \OwnPay\Service\Sms\SmsParserService(
            ensureType($c->get(\OwnPay\Repository\PairedDeviceRepository::class), \OwnPay\Repository\PairedDeviceRepository::class),
            ensureType($c->get(\OwnPay\Repository\SmsTemplateRepository::class), \OwnPay\Repository\SmsTemplateRepository::class),
            ensureType($c->get(\OwnPay\Repository\SmsDataRepository::class), \OwnPay\Repository\SmsDataRepository::class),
            ensureType($c->get(\OwnPay\Service\Sms\SmsRegexParser::class), \OwnPay\Service\Sms\SmsRegexParser::class),
            ensureType($c->get(\OwnPay\Service\Sms\SmsHeuristicParser::class), \OwnPay\Service\Sms\SmsHeuristicParser::class),
            ensureType($c->get(\OwnPay\Security\FieldEncryptor::class), \OwnPay\Security\FieldEncryptor::class),
            ensureType($c->get(\OwnPay\Service\Notification\MobileNotificationService::class), \OwnPay\Service\Notification\MobileNotificationService::class),
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class),
            ensureType($c->get(\OwnPay\Service\System\Logger::class), \OwnPay\Service\System\Logger::class),
        );
    });

    // --- Logger
    $c->singleton(\OwnPay\Service\System\Logger::class, static function (\OwnPay\Container $c): \OwnPay\Service\System\Logger {
        $appCfg = ensureArray($c->get('config.app'));
        $paths = ensureArray($appCfg['paths'] ?? null);
        return new \OwnPay\Service\System\Logger(
            ensureString($paths['logs'] ?? '')
        );
    });

    // --- Plugin System
    $c->singleton(\OwnPay\Plugin\PluginRegistry::class, static function (\OwnPay\Container $c): \OwnPay\Plugin\PluginRegistry {
        return new \OwnPay\Plugin\PluginRegistry(
            ensureType($c->get(\OwnPay\Repository\PluginRepository::class), \OwnPay\Repository\PluginRepository::class)
        );
    });

    $c->singleton(\OwnPay\Plugin\PluginLoader::class, static function (\OwnPay\Container $c): \OwnPay\Plugin\PluginLoader {
        return new \OwnPay\Plugin\PluginLoader(
            $c,
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class),
            ensureType($c->get(\OwnPay\Plugin\PluginRegistry::class), \OwnPay\Plugin\PluginRegistry::class)
        );
    });

    $c->singleton(\OwnPay\Plugin\PluginInstaller::class, static function (\OwnPay\Container $c): \OwnPay\Plugin\PluginInstaller {
        $appCfg = ensureArray($c->get('config.app'));
        $paths = ensureArray($appCfg['paths'] ?? null);
        return new \OwnPay\Plugin\PluginInstaller(
            ensureString($paths['modules'] ?? '')
        );
    });

    // --- Payment Services
    $c->singleton(\OwnPay\Service\Payment\InvoiceService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\InvoiceService {
        return new \OwnPay\Service\Payment\InvoiceService(
            ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class),
            ensureType($c->get(\OwnPay\Service\System\PdfService::class), \OwnPay\Service\System\PdfService::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\PaymentLinkService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\PaymentLinkService {
        return new \OwnPay\Service\Payment\PaymentLinkService(ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class));
    });

    // --- Brand Context
    $c->singleton(\OwnPay\Service\Brand\BrandContext::class, static function (\OwnPay\Container $c): \OwnPay\Service\Brand\BrandContext {
        return new \OwnPay\Service\Brand\BrandContext(
            ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class)
        );
    });

    // --- Aliases
    $c->alias('db', \PDO::class);
    $c->alias('database', \OwnPay\Core\Database::class);
    $c->alias('events', \OwnPay\Event\EventManager::class);
    $c->alias('cache', \OwnPay\Cache\CacheInterface::class);
    $c->alias('queue', \OwnPay\Queue\QueueInterface::class);
    $c->alias('router', \OwnPay\Http\Router::class);
    $c->alias('twig', \Twig\Environment::class);
    $c->alias('logger', \OwnPay\Service\System\Logger::class);
    $c->alias('brand', \OwnPay\Service\Brand\BrandContext::class);

    // Eager-boot Database so getInstance() is populated
    /**
     * Eager-boots the Database wrapper singleton instance.
     * Ensures that Database::getInstance() is properly initialized during boot
     * for legacy static resolutions, if the application installation is complete.
     */
    if (file_exists(dirname(__DIR__) . '/storage/.installed')) {
        try {
            $c->get(\OwnPay\Core\Database::class);
        } catch (\Throwable $e) {
            // Ignore if DB is unreachable during boot
        }
    }

    // Repositories
    // All repos extend BaseRepository which needs Database injected.
    $repoFactory = static function (string $class) {
        return static function (\OwnPay\Container $c) use ($class) {
            return new $class(ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class));
        };
    };

    $c->singleton(\OwnPay\Repository\TransactionRepository::class, $repoFactory(\OwnPay\Repository\TransactionRepository::class));
    $c->singleton(\OwnPay\Repository\ManualGatewayRepository::class, $repoFactory(\OwnPay\Repository\ManualGatewayRepository::class));
    $c->singleton(\OwnPay\Repository\GatewayConfigRepository::class, $repoFactory(\OwnPay\Repository\GatewayConfigRepository::class));
    $c->singleton(\OwnPay\Repository\GatewayRepository::class, $repoFactory(\OwnPay\Repository\GatewayRepository::class));
    $c->singleton(\OwnPay\Repository\MerchantRepository::class, $repoFactory(\OwnPay\Repository\MerchantRepository::class));
    $c->singleton(\OwnPay\Repository\MerchantUserRepository::class, $repoFactory(\OwnPay\Repository\MerchantUserRepository::class));
    $c->singleton(\OwnPay\Repository\SettingsRepository::class, $repoFactory(\OwnPay\Repository\SettingsRepository::class));
    $c->singleton(\OwnPay\Repository\CustomerRepository::class, $repoFactory(\OwnPay\Repository\CustomerRepository::class));
    $c->singleton(\OwnPay\Repository\InvoiceRepository::class, $repoFactory(\OwnPay\Repository\InvoiceRepository::class));
    $c->singleton(\OwnPay\Repository\RefundRepository::class, $repoFactory(\OwnPay\Repository\RefundRepository::class));
    $c->singleton(\OwnPay\Repository\FeeRuleRepository::class, $repoFactory(\OwnPay\Repository\FeeRuleRepository::class));
    $c->singleton(\OwnPay\Repository\LedgerRepository::class, $repoFactory(\OwnPay\Repository\LedgerRepository::class));
    $c->singleton(\OwnPay\Repository\DisputeRepository::class, $repoFactory(\OwnPay\Repository\DisputeRepository::class));
    $c->singleton(\OwnPay\Repository\WebhookRepository::class, $repoFactory(\OwnPay\Repository\WebhookRepository::class));
    $c->singleton(\OwnPay\Repository\WebhookEventRepository::class, $repoFactory(\OwnPay\Repository\WebhookEventRepository::class));
    $c->singleton(\OwnPay\Repository\IdempotencyRepository::class, $repoFactory(\OwnPay\Repository\IdempotencyRepository::class));
    $c->singleton(\OwnPay\Repository\PaymentIntentRepository::class, $repoFactory(\OwnPay\Repository\PaymentIntentRepository::class));
    $c->singleton(\OwnPay\Repository\PaymentLinkRepository::class, $repoFactory(\OwnPay\Repository\PaymentLinkRepository::class));
    $c->singleton(\OwnPay\Repository\ApiKeyRepository::class, $repoFactory(\OwnPay\Repository\ApiKeyRepository::class));
    $c->singleton(\OwnPay\Repository\AuditLogRepository::class, $repoFactory(\OwnPay\Repository\AuditLogRepository::class));
    $c->singleton(\OwnPay\Repository\CommLogRepository::class, $repoFactory(\OwnPay\Repository\CommLogRepository::class));
    $c->singleton(\OwnPay\Repository\RoleRepository::class, $repoFactory(\OwnPay\Repository\RoleRepository::class));
    $c->singleton(\OwnPay\Repository\DomainRepository::class, $repoFactory(\OwnPay\Repository\DomainRepository::class));
    $c->singleton(\OwnPay\Repository\SmsDataRepository::class, $repoFactory(\OwnPay\Repository\SmsDataRepository::class));
    $c->singleton(\OwnPay\Repository\SmsTemplateRepository::class, $repoFactory(\OwnPay\Repository\SmsTemplateRepository::class));
    $c->singleton(\OwnPay\Repository\SmsParsedRepository::class, $repoFactory(\OwnPay\Repository\SmsParsedRepository::class));
    $c->singleton(\OwnPay\Repository\MobileNotificationRepository::class, $repoFactory(\OwnPay\Repository\MobileNotificationRepository::class));
    $c->singleton(\OwnPay\Repository\PairedDeviceRepository::class, $repoFactory(\OwnPay\Repository\PairedDeviceRepository::class));
    $c->singleton(\OwnPay\Repository\DevicePairingTokenRepository::class, $repoFactory(\OwnPay\Repository\DevicePairingTokenRepository::class));
    $c->singleton(\OwnPay\Repository\RateLimitRepository::class, $repoFactory(\OwnPay\Repository\RateLimitRepository::class));
    $c->singleton(\OwnPay\Repository\LoginAttemptRepository::class, $repoFactory(\OwnPay\Repository\LoginAttemptRepository::class));
    $c->singleton(\OwnPay\Repository\PluginRepository::class, $repoFactory(\OwnPay\Repository\PluginRepository::class));

    // --- Security
    $c->singleton(\OwnPay\Security\FieldEncryptor::class, static function (): \OwnPay\Security\FieldEncryptor {
        return new \OwnPay\Security\FieldEncryptor();
    });

    // --- Auth Services
    $c->singleton(\OwnPay\Service\Auth\JwtService::class, static function (): \OwnPay\Service\Auth\JwtService {
        $secret = is_string($s = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET')) ? $s : null;
        // Issuer is the stable JwtService::ISSUER constant (NOT APP_NAME) so renaming the app's display
        // name never invalidates already-issued device tokens. Pass only the secret; issuer defaults.
        return new \OwnPay\Service\Auth\JwtService($secret);
    });

    $c->singleton(\OwnPay\Service\Auth\AuthSessionService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Auth\AuthSessionService {
        return new \OwnPay\Service\Auth\AuthSessionService(
            ensureType($c->get(\OwnPay\Security\Authenticator::class), \OwnPay\Security\Authenticator::class),
            ensureType($c->get(\OwnPay\Repository\MerchantUserRepository::class), \OwnPay\Repository\MerchantUserRepository::class),
            ensureType($c->get(\OwnPay\Repository\RoleRepository::class), \OwnPay\Repository\RoleRepository::class),
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class)
        );
    });

    // --- Admin Session
    $c->singleton(\OwnPay\Service\Admin\AdminSession::class, static function (): \OwnPay\Service\Admin\AdminSession {
        return new \OwnPay\Service\Admin\AdminSession();
    });

    // --- Payment Services (Core Chain)
    $c->singleton(\OwnPay\Service\Payment\FeeService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\FeeService {
        return new \OwnPay\Service\Payment\FeeService(
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class),
            ensureType($c->get(\OwnPay\Repository\SettingsRepository::class), \OwnPay\Repository\SettingsRepository::class),
            ensureType($c->get(\OwnPay\Repository\FeeRuleRepository::class), \OwnPay\Repository\FeeRuleRepository::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\TransactionService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\TransactionService {
        return new \OwnPay\Service\Payment\TransactionService(
            ensureType($c->get(\OwnPay\Repository\TransactionRepository::class), \OwnPay\Repository\TransactionRepository::class),
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class),
            ensureType($c->get(\OwnPay\Repository\AuditLogRepository::class), \OwnPay\Repository\AuditLogRepository::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\LedgerService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\LedgerService {
        return new \OwnPay\Service\Payment\LedgerService(
            ensureType($c->get(\OwnPay\Repository\LedgerRepository::class), \OwnPay\Repository\LedgerRepository::class),
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class),
            ensureType($c->get(\OwnPay\Repository\TransactionRepository::class), \OwnPay\Repository\TransactionRepository::class)
        );
    });

    $c->singleton(\OwnPay\Gateway\GatewayBridge::class, static function (\OwnPay\Container $c): \OwnPay\Gateway\GatewayBridge {
        return new \OwnPay\Gateway\GatewayBridge(
            ensureType($c->get(\OwnPay\Repository\GatewayConfigRepository::class), \OwnPay\Repository\GatewayConfigRepository::class),
            ensureType($c->get(\OwnPay\Security\FieldEncryptor::class), \OwnPay\Security\FieldEncryptor::class),
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class),
            ensureType($c->get(\OwnPay\Repository\SettingsRepository::class), \OwnPay\Repository\SettingsRepository::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\GatewayApiService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\GatewayApiService {
        return new \OwnPay\Service\Payment\GatewayApiService(
            ensureType($c->get(\OwnPay\Gateway\GatewayBridge::class), \OwnPay\Gateway\GatewayBridge::class),
            ensureType($c->get(\OwnPay\Repository\GatewayRepository::class), \OwnPay\Repository\GatewayRepository::class),
            ensureType($c->get(\OwnPay\Service\Payment\TransactionService::class), \OwnPay\Service\Payment\TransactionService::class),
            ensureType($c->get(\OwnPay\Service\Payment\FeeService::class), \OwnPay\Service\Payment\FeeService::class),
            ensureType($c->get(\OwnPay\Service\Payment\LedgerService::class), \OwnPay\Service\Payment\LedgerService::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\RefundService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\RefundService {
        return new \OwnPay\Service\Payment\RefundService(
            ensureType($c->get(\OwnPay\Repository\RefundRepository::class), \OwnPay\Repository\RefundRepository::class),
            ensureType($c->get(\OwnPay\Repository\TransactionRepository::class), \OwnPay\Repository\TransactionRepository::class),
            ensureType($c->get(\OwnPay\Gateway\GatewayBridge::class), \OwnPay\Gateway\GatewayBridge::class),
            ensureType($c->get(\OwnPay\Service\Payment\LedgerService::class), \OwnPay\Service\Payment\LedgerService::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\DisputeService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\DisputeService {
        return new \OwnPay\Service\Payment\DisputeService(
            ensureType($c->get(\OwnPay\Repository\DisputeRepository::class), \OwnPay\Repository\DisputeRepository::class),
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\IdempotencyService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\IdempotencyService {
        return new \OwnPay\Service\Payment\IdempotencyService(
            ensureType($c->get(\OwnPay\Repository\IdempotencyRepository::class), \OwnPay\Repository\IdempotencyRepository::class)
        );
    });


    $c->singleton(\OwnPay\Service\Payment\PaymentService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\PaymentService {
        return new \OwnPay\Service\Payment\PaymentService(
            ensureType($c->get(\OwnPay\Repository\PaymentIntentRepository::class), \OwnPay\Repository\PaymentIntentRepository::class),
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\CurrencyService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\CurrencyService {
        return new \OwnPay\Service\Payment\CurrencyService(
            ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class)
        );
    });

    // White-label domain pipeline - central URL resolver
    $c->singleton(\OwnPay\Service\Domain\DomainUrlService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Domain\DomainUrlService {
        return new \OwnPay\Service\Domain\DomainUrlService(
            ensureType($c->get(\OwnPay\Repository\DomainRepository::class), \OwnPay\Repository\DomainRepository::class)
        );
    });

    // White-label brand theming - per-brand visual customization
    $c->singleton(\OwnPay\Service\Brand\BrandThemeService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Brand\BrandThemeService {
        return new \OwnPay\Service\Brand\BrandThemeService(
            ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class),
            ensureType($c->get(\OwnPay\Repository\SettingsRepository::class), \OwnPay\Repository\SettingsRepository::class)
        );
    });

    // Payment completion listener (invoice paid + link use_count)
    $c->singleton(\OwnPay\Service\Payment\PaymentCompletionListener::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\PaymentCompletionListener {
        return new \OwnPay\Service\Payment\PaymentCompletionListener(
            ensureType($c->get(\OwnPay\Repository\InvoiceRepository::class), \OwnPay\Repository\InvoiceRepository::class),
            ensureType($c->get(\OwnPay\Repository\PaymentLinkRepository::class), \OwnPay\Repository\PaymentLinkRepository::class)
        );
    });

    // Transactional email notifier (per-brand sender + on-payment/on-refund prefs)
    $c->singleton(\OwnPay\Service\Communication\EmailNotificationService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Communication\EmailNotificationService {
        return new \OwnPay\Service\Communication\EmailNotificationService(
            ensureType($c->get(\OwnPay\Service\Communication\CommunicationService::class), \OwnPay\Service\Communication\CommunicationService::class),
            ensureType($c->get(\OwnPay\Repository\SettingsRepository::class), \OwnPay\Repository\SettingsRepository::class),
            ensureType($c->get(\OwnPay\View\FragmentRenderer::class), \OwnPay\View\FragmentRenderer::class),
            ensureType($c->get(\OwnPay\Service\System\Logger::class), \OwnPay\Service\System\Logger::class)
        );
    });

    // Self-service password reset orchestration
    $c->singleton(\OwnPay\Service\Auth\PasswordResetService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Auth\PasswordResetService {
        return new \OwnPay\Service\Auth\PasswordResetService(
            ensureType($c->get(\OwnPay\Repository\MerchantUserRepository::class), \OwnPay\Repository\MerchantUserRepository::class),
            ensureType($c->get(\OwnPay\Repository\PasswordResetRepository::class), \OwnPay\Repository\PasswordResetRepository::class),
            ensureType($c->get(\OwnPay\Service\Communication\CommunicationService::class), \OwnPay\Service\Communication\CommunicationService::class),
            ensureType($c->get(\OwnPay\View\FragmentRenderer::class), \OwnPay\View\FragmentRenderer::class),
            ensureType($c->get(\OwnPay\Service\Domain\DomainUrlService::class), \OwnPay\Service\Domain\DomainUrlService::class),
            ensureType($c->get(\OwnPay\Service\System\Logger::class), \OwnPay\Service\System\Logger::class)
        );
    });

    // Wiring listener to hook eagerly during boot
    /**
     * Registers payment completion hooks.
     *
     * Hooks into the global EventManager to listen to 'payment.transaction.completed'
     * and route events to the PaymentCompletionListener, plus the transactional email
     * notifier on completion + refund (priority 20 so payment-state updates run first).
     */
    if (file_exists(dirname(__DIR__) . '/storage/.installed')) {
        try {
            $events = ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class);
            $events->addAction('system.boot', static function () use ($c, $events): void {
                $listener = ensureType($c->get(\OwnPay\Service\Payment\PaymentCompletionListener::class), \OwnPay\Service\Payment\PaymentCompletionListener::class);
                $events->addAction('payment.transaction.completed', [$listener, 'onTransactionCompleted']);

                $emailNotifier = ensureType($c->get(\OwnPay\Service\Communication\EmailNotificationService::class), \OwnPay\Service\Communication\EmailNotificationService::class);
                $events->addAction('payment.transaction.completed', [$emailNotifier, 'onTransactionCompleted'], 20);
                $events->addAction('refund.created', [$emailNotifier, 'onRefundCreated'], 20);
            });
        } catch (\Throwable) {}
    }

    // --- System Services
    $c->singleton(\OwnPay\Service\System\AuditLogger::class, static function (\OwnPay\Container $c): \OwnPay\Service\System\AuditLogger {
        return new \OwnPay\Service\System\AuditLogger(
            ensureType($c->get(\OwnPay\Repository\AuditLogRepository::class), \OwnPay\Repository\AuditLogRepository::class)
        );
    });

    $c->singleton(\OwnPay\Service\System\PdfService::class, static function (\OwnPay\Container $c): \OwnPay\Service\System\PdfService {
        $appCfg = ensureArray($c->get('config.app'));
        $paths = ensureArray($appCfg['paths'] ?? null);
        $outputDir = is_string($paths['storage'] ?? null) ? $paths['storage'] . '/pdf' : null;
        return new \OwnPay\Service\System\PdfService($outputDir);
    });

    $c->singleton(\OwnPay\Service\System\PaginationService::class, static function (): \OwnPay\Service\System\PaginationService {
        return new \OwnPay\Service\System\PaginationService();
    });

    // Webhook
    $c->singleton(\OwnPay\Gateway\WebhookInboundProcessor::class, static function (\OwnPay\Container $c): \OwnPay\Gateway\WebhookInboundProcessor {
        return new \OwnPay\Gateway\WebhookInboundProcessor(
            ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class),
            ensureType($c->get(\OwnPay\Service\Payment\TransactionService::class), \OwnPay\Service\Payment\TransactionService::class),
            ensureType($c->get(\OwnPay\Repository\TransactionRepository::class), \OwnPay\Repository\TransactionRepository::class),
            ensureType($c->get(\OwnPay\Service\System\AuditLogger::class), \OwnPay\Service\System\AuditLogger::class),
            ensureType($c->get(\OwnPay\Service\System\Logger::class), \OwnPay\Service\System\Logger::class),
            ensureType($c->get(\OwnPay\Service\Payment\LedgerService::class), \OwnPay\Service\Payment\LedgerService::class)
        );
    });

    // Update System
    $c->singleton(\OwnPay\Repository\UpdateHistoryRepository::class, $repoFactory(\OwnPay\Repository\UpdateHistoryRepository::class));

    $c->singleton(\OwnPay\Update\BackupService::class, static function (\OwnPay\Container $c): \OwnPay\Update\BackupService {
        $appCfg = ensureArray($c->get('config.app'));
        $paths = ensureArray($appCfg['paths'] ?? null);
        return new \OwnPay\Update\BackupService(
            is_string($bPath = $paths['backups'] ?? null) ? $bPath : null,
            ensureType($c->get(\OwnPay\Service\System\Logger::class), \OwnPay\Service\System\Logger::class),
            ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class)
        );
    });

    $c->singleton(\OwnPay\Update\HealthChecker::class, static function (\OwnPay\Container $c): \OwnPay\Update\HealthChecker {
        return new \OwnPay\Update\HealthChecker(
            ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class)
        );
    });

    $c->singleton(\OwnPay\Update\MaintenanceMode::class, static function (): \OwnPay\Update\MaintenanceMode {
        return new \OwnPay\Update\MaintenanceMode();
    });

    $c->singleton(\OwnPay\Update\UpdateService::class, static function (\OwnPay\Container $c): \OwnPay\Update\UpdateService {
        return new \OwnPay\Update\UpdateService(
            ensureType($c->get(\OwnPay\Update\BackupService::class), \OwnPay\Update\BackupService::class),
            ensureType($c->get(\OwnPay\Update\HealthChecker::class), \OwnPay\Update\HealthChecker::class),
            ensureType($c->get(\OwnPay\Update\MaintenanceMode::class), \OwnPay\Update\MaintenanceMode::class),
            ensureType($c->get(\OwnPay\Repository\UpdateHistoryRepository::class), \OwnPay\Repository\UpdateHistoryRepository::class),
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class),
            ensureType($c->get(\OwnPay\Service\System\Logger::class), \OwnPay\Service\System\Logger::class)
        );
    });

    $c->singleton(\OwnPay\Cron\SystemUpdateJob::class, static function (\OwnPay\Container $c): \OwnPay\Cron\SystemUpdateJob {
        $appCfg = ensureArray($c->get('config.app'));
        $version = ensureString($appCfg['version'] ?? '0.1.0');
        return new \OwnPay\Cron\SystemUpdateJob(
            $version,
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class),
            ensureType($c->get(\OwnPay\Repository\SettingsRepository::class), \OwnPay\Repository\SettingsRepository::class)
        );
    });

    $c->singleton(\OwnPay\Cron\RefundReconciliationJob::class, static function (\OwnPay\Container $c): \OwnPay\Cron\RefundReconciliationJob {
        return new \OwnPay\Cron\RefundReconciliationJob(
            ensureType($c->get(\OwnPay\Core\Database::class), \OwnPay\Core\Database::class),
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class),
            ensureType($c->get(\OwnPay\Service\System\AuditLogger::class), \OwnPay\Service\System\AuditLogger::class),
            ensureType($c->get(\OwnPay\Service\System\Logger::class), \OwnPay\Service\System\Logger::class)
        );
    });

    $c->singleton(\OwnPay\Cron\CronJobRunner::class, static function (\OwnPay\Container $c): \OwnPay\Cron\CronJobRunner {
        $logger = $c->has(\OwnPay\Service\System\Logger::class) ? ensureType($c->get(\OwnPay\Service\System\Logger::class), \OwnPay\Service\System\Logger::class) : null;
        $runner = new \OwnPay\Cron\CronJobRunner(
            ensureType($c->get(\OwnPay\Event\EventManager::class), \OwnPay\Event\EventManager::class),
            $logger
        );

        $runner->register('QueueWorker', ensureType($c->get(\OwnPay\Cron\QueueWorkerJob::class), \OwnPay\Cron\QueueWorkerJob::class), 'every_minute');
        $runner->register('SmsVerification', ensureType($c->get(\OwnPay\Cron\SmsVerificationJob::class), \OwnPay\Cron\SmsVerificationJob::class), 'every_minute');
        $runner->register('WebhookRetry', ensureType($c->get(\OwnPay\Cron\WebhookRetryCron::class), \OwnPay\Cron\WebhookRetryCron::class), 'every_5min');
        $runner->register('BalanceVerification', ensureType($c->get(\OwnPay\Cron\BalanceVerificationJob::class), \OwnPay\Cron\BalanceVerificationJob::class), 'every_5min');
        $runner->register('CurrencyUpdate', ensureType($c->get(\OwnPay\Cron\CurrencyUpdateJob::class), \OwnPay\Cron\CurrencyUpdateJob::class), 'hourly');
        $runner->register('DnsVerification', ensureType($c->get(\OwnPay\Cron\DnsVerificationJob::class), \OwnPay\Cron\DnsVerificationJob::class), 'hourly');
        $runner->register('RefundReconciliation', ensureType($c->get(\OwnPay\Cron\RefundReconciliationJob::class), \OwnPay\Cron\RefundReconciliationJob::class), 'hourly');
        $runner->register('UpdateCheck', ensureType($c->get(\OwnPay\Cron\UpdateCheckJob::class), \OwnPay\Cron\UpdateCheckJob::class), 'daily');
        $runner->register('SystemUpdate', ensureType($c->get(\OwnPay\Cron\SystemUpdateJob::class), \OwnPay\Cron\SystemUpdateJob::class), 'daily');

        // Plugin-declared cron jobs (manifest "cron"). Plugins are loaded during Kernel boot, so by
        // the time this runner is built for a /cron run the registry holds their manifests. Each
        // entry's "class" (a CronJobInterface the plugin ships, resolved via the plugin autoloader)
        // is scheduled under a slug-namespaced name so it can never collide with a core job.
        if ($c->has(\OwnPay\Plugin\PluginRegistry::class)) {
            $pluginRegistry = $c->get(\OwnPay\Plugin\PluginRegistry::class);
            if ($pluginRegistry instanceof \OwnPay\Plugin\PluginRegistry) {
                foreach ($pluginRegistry->getLoaded() as $pluginSlug => $pluginInstance) {
                    $pluginManifest = $pluginRegistry->getManifest($pluginSlug);
                    if ($pluginManifest === null) {
                        continue;
                    }
                    foreach ($pluginManifest->cron as $cronEntry) {
                        $jobClass = $cronEntry['class'] ?? '';
                        $jobName = $cronEntry['name'];
                        $jobSchedule = $cronEntry['schedule'];
                        if ($jobClass === '' || $jobName === '' || $jobSchedule === '') {
                            continue;
                        }
                        if (!class_exists($jobClass) || !is_subclass_of($jobClass, \OwnPay\Cron\CronJobInterface::class)) {
                            continue;
                        }
                        $jobInstance = $c->has($jobClass) ? $c->get($jobClass) : new $jobClass();
                        if ($jobInstance instanceof \OwnPay\Cron\CronJobInterface) {
                            $runner->register('plugin:' . $pluginSlug . ':' . $jobName, $jobInstance, $jobSchedule);
                        }
                    }
                }
            }
        }

        return $runner;
    });

    $c->singleton(\OwnPay\Service\System\TranslationService::class, static function (\OwnPay\Container $c): \OwnPay\Service\System\TranslationService {
        $db = $c->get(\OwnPay\Core\Database::class);
        if (!$db instanceof \OwnPay\Core\Database) {
            throw new \RuntimeException('Database instance not found in Container');
        }
        return new \OwnPay\Service\System\TranslationService($db);
    });
};

