<?php
declare(strict_types=1);

/**
 * Service container bindings.
 *
 * Called by Kernel::boot() to register all core services.
 * Each binding is a closure that receives the Container.
 *
 * @param \OwnPay\Container $c
 */

return static function (\OwnPay\Container $c): void {

    // ─── Configuration ─────────────────────────────────────────
    $c->singleton('config.app', static function () {
        return require __DIR__ . '/app.php';
    });

    $c->singleton('config.database', static function () {
        return require __DIR__ . '/database.php';
    });

    // ─── PDO Database Connection ───────────────────────────────
    $c->singleton(\PDO::class, static function (\OwnPay\Container $c): \PDO {
        $cfg = $c->get('config.database');
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['driver'],
            $cfg['host'],
            $cfg['port'],
            $cfg['database'],
            $cfg['charset']
        );
        $pdo = new \PDO($dsn, $cfg['username'], $cfg['password'], $cfg['options']);
        $pdo->exec("SET NAMES '{$cfg['charset']}' COLLATE '{$cfg['collation']}'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    });

    // ─── Database Wrapper ──────────────────────────────────────
    $c->singleton(\OwnPay\Core\Database::class, static function (\OwnPay\Container $c): \OwnPay\Core\Database {
        $cfg = $c->get('config.database');
        $db  = \OwnPay\Core\Database::init(
            $cfg['host'],
            $cfg['database'],
            $cfg['username'],
            $cfg['password'],
            $cfg['port']
        );
        // Also set strict SQL mode
        $db->pdo()->exec("SET NAMES '{$cfg['charset']}' COLLATE '{$cfg['collation']}'");
        $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $db;
    });

    // ─── Event Manager ─────────────────────────────────────────
    $c->singleton(\OwnPay\Event\EventManager::class, static function (): \OwnPay\Event\EventManager {
        return new \OwnPay\Event\EventManager();
    });

    // ─── Cache ─────────────────────────────────────────────────
    $c->singleton(\OwnPay\Cache\CacheInterface::class, static function (\OwnPay\Container $c): \OwnPay\Cache\CacheInterface {
        $driver = $c->get('config.app')['cache_driver'] ?? 'file';
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
        return new \OwnPay\Cache\FileCache(
            $c->get('config.app')['paths']['cache']
        );
    });

    // ─── Queue ─────────────────────────────────────────────────
    $c->singleton(\OwnPay\Queue\QueueInterface::class, static function (\OwnPay\Container $c): \OwnPay\Queue\QueueInterface {
        $driver = $c->get('config.app')['queue_driver'] ?? 'file';
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
        return new \OwnPay\Queue\FileQueue(
            $c->get('config.app')['paths']['queue']
        );
    });

    // ─── Router ────────────────────────────────────────────────
    $c->singleton(\OwnPay\Http\Router::class, static function (\OwnPay\Container $c): \OwnPay\Http\Router {
        return new \OwnPay\Http\Router($c);
    });

    // ─── Twig ──────────────────────────────────────────────────
    $c->singleton(\Twig\Environment::class, static function (\OwnPay\Container $c): \Twig\Environment {
        $paths = $c->get('config.app')['paths'];
        $loader = new \Twig\Loader\FilesystemLoader([
            $paths['templates'],
        ]);
        // Add module theme paths for overrides
        $themesDir = $paths['modules'] . '/themes';
        if (is_dir($themesDir)) {
            foreach (glob($themesDir . '/*/templates') as $themeTemplateDir) {
                $themeName = basename(dirname($themeTemplateDir));
                $loader->addPath($themeTemplateDir, $themeName);
            }
        }
        $twig = new \Twig\Environment($loader, [
            'cache'       => $paths['cache'] . '/twig',
            'auto_reload' => $c->get('config.app')['debug'],
            'strict_variables' => true,
            'autoescape'  => 'html',
        ]);
        // Register core extensions (built in Phase A19)
        if (class_exists(\OwnPay\View\TwigExtensions::class)) {
            $twig->addExtension(new \OwnPay\View\TwigExtensions($c));
        }
        // Global vars available to ALL templates (admin, checkout, public)
        $twig->addGlobal('csrf_token', $_SESSION['_csrf_token'] ?? '');
        $twig->addGlobal('app_name', $_ENV['APP_NAME'] ?? 'Own Pay');
        $twig->addGlobal('lang', []);   // i18n placeholder — populated by locale plugin
        return $twig;
    });

    // ─── Logger ────────────────────────────────────────────────
    $c->singleton(\OwnPay\Service\System\Logger::class, static function (\OwnPay\Container $c): \OwnPay\Service\System\Logger {
        return new \OwnPay\Service\System\Logger(
            $c->get('config.app')['paths']['logs']
        );
    });

    // ─── Plugin System ─────────────────────────────────────────
    $c->singleton(\OwnPay\Plugin\PluginRegistry::class, static function (\OwnPay\Container $c): \OwnPay\Plugin\PluginRegistry {
        return new \OwnPay\Plugin\PluginRegistry(
            $c->get(\OwnPay\Repository\PluginRepository::class)
        );
    });

    $c->singleton(\OwnPay\Plugin\PluginLoader::class, static function (\OwnPay\Container $c): \OwnPay\Plugin\PluginLoader {
        return new \OwnPay\Plugin\PluginLoader(
            $c,
            $c->get(\OwnPay\Event\EventManager::class),
            $c->get(\OwnPay\Plugin\PluginRegistry::class)
        );
    });

    $c->singleton(\OwnPay\Plugin\PluginInstaller::class, static function (\OwnPay\Container $c): \OwnPay\Plugin\PluginInstaller {
        return new \OwnPay\Plugin\PluginInstaller(
            $c->get('config.app')['paths']['modules'],
            $c->get(\OwnPay\Plugin\PluginRegistry::class)
        );
    });

    // ─── Payment Services ──────────────────────────────────────
    $c->singleton(\OwnPay\Service\Payment\InvoiceService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\InvoiceService {
        return new \OwnPay\Service\Payment\InvoiceService($c->get(\OwnPay\Core\Database::class));
    });

    $c->singleton(\OwnPay\Service\Payment\PaymentLinkService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\PaymentLinkService {
        return new \OwnPay\Service\Payment\PaymentLinkService($c->get(\OwnPay\Core\Database::class));
    });

    // ─── Brand Context ─────────────────────────────────────────
    $c->singleton(\OwnPay\Service\Brand\BrandContext::class, static function (\OwnPay\Container $c): \OwnPay\Service\Brand\BrandContext {
        return new \OwnPay\Service\Brand\BrandContext(
            $c->get(\OwnPay\Core\Database::class)
        );
    });

    // ─── Aliases ───────────────────────────────────────────────
    $c->alias('db', \PDO::class);
    $c->alias('database', \OwnPay\Core\Database::class);
    $c->alias('events', \OwnPay\Event\EventManager::class);
    $c->alias('cache', \OwnPay\Cache\CacheInterface::class);
    $c->alias('queue', \OwnPay\Queue\QueueInterface::class);
    $c->alias('router', \OwnPay\Http\Router::class);
    $c->alias('twig', \Twig\Environment::class);
    $c->alias('logger', \OwnPay\Service\System\Logger::class);
    $c->alias('brand', \OwnPay\Service\Brand\BrandContext::class);

    // ─── Eager-boot Database so getInstance() is populated ─────
    // Must run before any service that calls Database::getInstance() directly.
    if (file_exists(dirname(__DIR__) . '/storage/.installed')) {
        try {
            $c->get(\OwnPay\Core\Database::class);
        } catch (\Throwable $e) {
            // Ignore if DB is unreachable during boot
        }
    }

    // ─── Repositories ─────────────────────────────────────────────
    // All repos extend BaseRepository which needs Database injected.
    $repoFactory = static function (string $class) {
        return static function (\OwnPay\Container $c) use ($class) {
            return new $class($c->get(\OwnPay\Core\Database::class));
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
    $c->singleton(\OwnPay\Repository\LedgerRepository::class, $repoFactory(\OwnPay\Repository\LedgerRepository::class));
    $c->singleton(\OwnPay\Repository\DisputeRepository::class, $repoFactory(\OwnPay\Repository\DisputeRepository::class));
    $c->singleton(\OwnPay\Repository\SettlementRepository::class, $repoFactory(\OwnPay\Repository\SettlementRepository::class));
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

    // ─── Security ─────────────────────────────────────────────────
    $c->singleton(\OwnPay\Security\FieldEncryptor::class, static function (): \OwnPay\Security\FieldEncryptor {
        return new \OwnPay\Security\FieldEncryptor();
    });

    // ─── Auth Services ────────────────────────────────────────────
    $c->singleton(\OwnPay\Service\Auth\JwtService::class, static function (): \OwnPay\Service\Auth\JwtService {
        return new \OwnPay\Service\Auth\JwtService();
    });

    $c->singleton(\OwnPay\Service\Auth\AuthSessionService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Auth\AuthSessionService {
        return new \OwnPay\Service\Auth\AuthSessionService(
            $c->get(\OwnPay\Security\Authenticator::class),
            $c->get(\OwnPay\Repository\MerchantUserRepository::class),
            $c->get(\OwnPay\Repository\RoleRepository::class),
            $c->get(\OwnPay\Event\EventManager::class)
        );
    });

    // ─── Admin Session ────────────────────────────────────────────
    $c->singleton(\OwnPay\Service\Admin\AdminSession::class, static function (): \OwnPay\Service\Admin\AdminSession {
        return new \OwnPay\Service\Admin\AdminSession();
    });

    // ─── Payment Services (Core Chain) ────────────────────────────
    $c->singleton(\OwnPay\Service\Payment\FeeService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\FeeService {
        return new \OwnPay\Service\Payment\FeeService(
            $c->get(\OwnPay\Event\EventManager::class),
            $c->get(\OwnPay\Repository\SettingsRepository::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\TransactionService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\TransactionService {
        return new \OwnPay\Service\Payment\TransactionService(
            $c->get(\OwnPay\Repository\TransactionRepository::class),
            $c->get(\OwnPay\Event\EventManager::class),
            $c->get(\OwnPay\Repository\AuditLogRepository::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\LedgerService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\LedgerService {
        return new \OwnPay\Service\Payment\LedgerService(
            $c->get(\OwnPay\Repository\LedgerRepository::class),
            $c->get(\OwnPay\Event\EventManager::class)
        );
    });

    $c->singleton(\OwnPay\Gateway\GatewayBridge::class, static function (\OwnPay\Container $c): \OwnPay\Gateway\GatewayBridge {
        return new \OwnPay\Gateway\GatewayBridge(
            $c->get(\OwnPay\Repository\GatewayConfigRepository::class),
            $c->get(\OwnPay\Security\FieldEncryptor::class),
            $c->get(\OwnPay\Event\EventManager::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\GatewayApiService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\GatewayApiService {
        return new \OwnPay\Service\Payment\GatewayApiService(
            $c->get(\OwnPay\Gateway\GatewayBridge::class),
            $c->get(\OwnPay\Repository\GatewayRepository::class),
            $c->get(\OwnPay\Repository\GatewayConfigRepository::class),
            $c->get(\OwnPay\Service\Payment\TransactionService::class),
            $c->get(\OwnPay\Service\Payment\FeeService::class),
            $c->get(\OwnPay\Service\Payment\LedgerService::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\RefundService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\RefundService {
        return new \OwnPay\Service\Payment\RefundService(
            $c->get(\OwnPay\Repository\RefundRepository::class),
            $c->get(\OwnPay\Repository\TransactionRepository::class),
            $c->get(\OwnPay\Gateway\GatewayBridge::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\DisputeService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\DisputeService {
        return new \OwnPay\Service\Payment\DisputeService(
            $c->get(\OwnPay\Repository\DisputeRepository::class),
            $c->get(\OwnPay\Event\EventManager::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\IdempotencyService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\IdempotencyService {
        return new \OwnPay\Service\Payment\IdempotencyService(
            $c->get(\OwnPay\Repository\IdempotencyRepository::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\IdempotencyBridge::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\IdempotencyBridge {
        return new \OwnPay\Service\Payment\IdempotencyBridge(
            $c->get(\OwnPay\Service\Payment\IdempotencyService::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\PaymentService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\PaymentService {
        return new \OwnPay\Service\Payment\PaymentService(
            $c->get(\OwnPay\Repository\PaymentIntentRepository::class),
            $c->get(\OwnPay\Event\EventManager::class)
        );
    });

    $c->singleton(\OwnPay\Service\Payment\CurrencyService::class, static function (\OwnPay\Container $c): \OwnPay\Service\Payment\CurrencyService {
        return new \OwnPay\Service\Payment\CurrencyService(
            $c->get(\OwnPay\Core\Database::class)
        );
    });

    // ─── System Services ──────────────────────────────────────────
    $c->singleton(\OwnPay\Service\System\AuditLogger::class, static function (\OwnPay\Container $c): \OwnPay\Service\System\AuditLogger {
        return new \OwnPay\Service\System\AuditLogger(
            $c->get(\OwnPay\Repository\AuditLogRepository::class)
        );
    });

    $c->singleton(\OwnPay\Service\System\PaginationService::class, static function (): \OwnPay\Service\System\PaginationService {
        return new \OwnPay\Service\System\PaginationService();
    });

    // ─── Webhook ──────────────────────────────────────────────────
    $c->singleton(\OwnPay\Gateway\WebhookInboundProcessor::class, static function (\OwnPay\Container $c): \OwnPay\Gateway\WebhookInboundProcessor {
        return new \OwnPay\Gateway\WebhookInboundProcessor(
            $c->get(\OwnPay\Gateway\GatewayBridge::class),
            $c->get(\OwnPay\Service\Payment\TransactionService::class),
            $c->get(\OwnPay\Service\System\AuditLogger::class),
            $c->get(\OwnPay\Repository\WebhookEventRepository::class),
            $c->get(\OwnPay\Repository\TransactionRepository::class)
        );
    });
};
