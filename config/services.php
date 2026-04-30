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
            $c->get('config.app')['paths']['plugins'] . '/registry.json'
        );
    });

    $c->singleton(\OwnPay\Plugin\PluginLoader::class, static function (\OwnPay\Container $c): \OwnPay\Plugin\PluginLoader {
        return new \OwnPay\Plugin\PluginLoader(
            $c,
            $c->get(\OwnPay\Plugin\PluginRegistry::class),
            $c->get(\OwnPay\Event\EventManager::class),
            $c->get('config.app')['paths']['modules']
        );
    });

    // ─── Aliases ───────────────────────────────────────────────
    $c->alias('db', \PDO::class);
    $c->alias('events', \OwnPay\Event\EventManager::class);
    $c->alias('cache', \OwnPay\Cache\CacheInterface::class);
    $c->alias('queue', \OwnPay\Queue\QueueInterface::class);
    $c->alias('router', \OwnPay\Http\Router::class);
    $c->alias('twig', \Twig\Environment::class);
    $c->alias('logger', \OwnPay\Service\System\Logger::class);
};
