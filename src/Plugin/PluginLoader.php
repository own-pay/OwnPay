<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * PluginLoader scans, discovers, validates, and boots active plugins.
 *
 * Scans directories specified in application configuration (e.g., modules/gateways,
 * modules/themes, modules/addons). Ensures active plugins are properly validated,
 * recursively scanned for security issues, sandboxed, and loaded.
 */
final class PluginLoader
{
    private Container $container;
    private EventManager $events;
    private PluginRegistry $registry;

    /**
     * @var string[] Directories scanned for plugin modules.
     */
    private array $scanDirs;

    /**
     * PSR-4 map of namespace prefix (trailing "\") => plugin base directory, used to autoload the
     * additional classes a multi-file plugin ships from inside its own directory.
     *
     * @var array<string, string>
     */
    private array $pluginNamespaces = [];

    /**
     * Initialize the PluginLoader service.
     *
     * @param \OwnPay\Container $container The application's DI container.
     * @param \OwnPay\Event\EventManager $events Event manager for triggering action and filter hooks.
     * @param \OwnPay\Plugin\PluginRegistry $registry Registry for tracking registered plugins.
     */
    public function __construct(Container $container, EventManager $events, PluginRegistry $registry)
    {
        $this->container = $container;
        $this->events = $events;
        $this->registry = $registry;

        $configApp = $container->get('config.app');
        if (!is_array($configApp) || !isset($configApp['paths']) || !is_array($configApp['paths'])) {
            throw new \RuntimeException("config.app paths configuration missing or invalid");
        }
        $paths = $configApp['paths'];
        $modulesPath = is_string($paths['modules'] ?? null) ? $paths['modules'] : '';
        $this->scanDirs = [
            $modulesPath . '/gateways',
            $modulesPath . '/themes',
            $modulesPath . '/addons',
        ];

        // Enable multi-file plugins: classes a plugin ships (beyond its entrypoint) autoload from
        // the plugin directory via PSR-4. Every plugin file is still vetted by the load-time scanner.
        spl_autoload_register([$this, 'autoloadPluginClass']);
    }

    /**
     * Scan the filesystem to discover all plugins under modules paths.
     *
     * Parses the manifest.json file inside each plugin directory.
     *
     * @return \OwnPay\Plugin\PluginManifest[] Array of discovered plugin manifests keyed by slug.
     */
    public function discover(): array
    {
        $manifests = [];

        foreach ($this->scanDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $entries = scandir($dir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $pluginDir = $dir . '/' . $entry;
                if (!is_dir($pluginDir)) {
                    continue;
                }

                $manifest = PluginManifest::fromDirectory($pluginDir);
                if ($manifest !== null) {
                    $manifests[$manifest->slug] = $manifest;
                }
            }
        }

        return $manifests;
    }

    /**
     * Boot all active plugins (wrapper for loadActive).
     *
     * Executed by the application Kernel during the boot sequence.
     *
     * @return void
     */
    public function boot(): void
    {
        try {
            $db = $this->container->get(\OwnPay\Core\Database::class);
            if ($db instanceof \OwnPay\Core\Database) {
                $col = $db->fetchOne("SHOW COLUMNS FROM op_plugins LIKE 'status'");
                if (is_array($col) && isset($col['Type']) && is_string($col['Type']) && !str_contains($col['Type'], 'trashed')) {
                    $db->execute("ALTER TABLE op_plugins MODIFY COLUMN status ENUM('active','inactive','error','trashed') NOT NULL DEFAULT 'inactive'");
                }
            }
        } catch (\Throwable $e) {
            // Ignore if DB is not ready or setup yet (e.g. installer phase)
        }

        $this->loadActive();
    }

    /**
     * Load, validate, sandbox, and register active plugins.
     *
     * Wires gateway plugins into the core GatewayBridge payment pipeline.
     *
     * @return void
     */
    public function loadActive(): void
    {
        $this->events->doAction('plugins.before_load');

        $activePlugins = $this->registry->getActive();

        foreach ($activePlugins as $pluginData) {
            $slug = is_string($pluginData['slug'] ?? null) ? $pluginData['slug'] : '';
            try {
                $this->loadPlugin($pluginData);
            } catch (\Throwable $e) {
                // Mark plugin as errored, don't crash app
                $this->registry->markError($slug, $e->getMessage());
                $this->events->doAction('plugin.load_error', $slug, $e);
            }
        }

        // Boot phase - all plugins registered, now boot
        foreach ($this->registry->getLoaded() as $slug => $instance) {
            try {
                $instance->boot($this->container);
            } catch (\Throwable $e) {
                $this->registry->markError($slug, 'Boot failed: ' . $e->getMessage());
                $this->events->doAction('plugin.boot_error', $slug, $e);
            }
        }

        // Auto-register gateway adapters with the central GatewayBridge.
        // Gateway plugins implement GatewayAdapterInterface but typically leave their boot() methods blank. We automatically register them here.
        if ($this->container->has(\OwnPay\Gateway\GatewayBridge::class)) {
            $bridge = $this->container->get(\OwnPay\Gateway\GatewayBridge::class);
            if ($bridge instanceof \OwnPay\Gateway\GatewayBridge) {
                foreach ($this->registry->getLoaded() as $slug => $instance) {
                    if ($instance instanceof \OwnPay\Gateway\GatewayAdapterInterface) {
                        $bridge->registerAdapter($instance);
                    }
                }
            }
        }

        $this->events->doAction('plugins.after_load');
    }

    /**
     * Validate, inspect, require, instantiate, and sandbox a single plugin.
     *
     * Performs a deep token scanner scan on all PHP files within the plugin to block
     * unapproved execution of dangerous system-level PHP functions.
     *
     * @param array<string, mixed> $pluginData Plugin registration database record data.
     * @return void
     * @throws \RuntimeException If the manifest, entrypoint, or PHP source security scanning fails.
     */
    private function loadPlugin(array $pluginData): void
    {
        $slug = is_string($pluginData['slug'] ?? null) ? $pluginData['slug'] : '';
        $manifest = PluginManifest::fromDirectory($this->resolvePluginPath($pluginData));

        if ($manifest === null) {
            throw new \RuntimeException("Plugin manifest not found for: {$slug}");
        }

        $errors = $manifest->validate();
        if (!empty($errors)) {
            throw new \RuntimeException("Invalid manifest for {$slug}: " . implode(', ', $errors));
        }

        // Version compatibility check
        $configApp = $this->container->get('config.app');
        $coreVersion = '0.1.0';
        if (is_array($configApp) && isset($configApp['version']) && is_string($configApp['version'])) {
            $coreVersion = $configApp['version'];
        }
        if (!$manifest->isCompatible($coreVersion)) {
            $requiredCore = is_string($manifest->requires['core'] ?? null) ? $manifest->requires['core'] : '';
            throw new \RuntimeException("Plugin {$slug} requires core {$requiredCore} (current: {$coreVersion})");
        }

        // Load entrypoint
        $entrypointFile = $manifest->path . '/' . $manifest->entrypoint;
        if (!file_exists($entrypointFile)) {
            throw new \RuntimeException("Entrypoint not found: {$entrypointFile}");
        }

        $phpFiles = $this->findPhpFiles($manifest->path);
        foreach ($phpFiles as $phpFile) {
            $content = (string) file_get_contents($phpFile);
            $tokens = @token_get_all($content);
            for ($i = 0, $count = count($tokens); $i < $count; $i++) {
                $token = $tokens[$i];
                if (!is_array($token)) {
                    continue;
                }

                // T_EVAL is a language construct (dynamic code evaluation), not a callable function.
                if ($token[0] === T_EVAL) {
                    $relPath = str_replace($manifest->path . '/', '', $phpFile);
                    throw new \RuntimeException(
                        "Plugin {$slug} blocked: {$relPath} contains restricted language construct: " . $token[1]
                    );
                }

                // Direct calls to OS-command / process-control primitives.
                if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                    $funcName = ltrim($token[1], '\\');
                    if (!PluginSandbox::isDangerousFunction($funcName)) {
                        continue;
                    }

                    // Skip method calls ($x->system()), static calls (X::system()), and function declarations (function system()) of a same-named symbol.
                    $prev = $i - 1;
                    while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                        $prev--;
                    }
                    if ($prev >= 0 && is_array($tokens[$prev])) {
                        $prevType = $tokens[$prev][0];
                        if ($prevType === T_OBJECT_OPERATOR
                            || $prevType === T_DOUBLE_COLON
                            || $prevType === T_FUNCTION
                            || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $prevType === T_NULLSAFE_OBJECT_OPERATOR)) {
                            continue;
                        }
                    }

                    // Only an actual call (followed by "(") is flagged; a bare reference is not.
                    $next = $i + 1;
                    while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                        $next++;
                    }
                    if ($next < $count && $tokens[$next] === '(') {
                        $relPath = str_replace($manifest->path . '/', '', $phpFile);
                        throw new \RuntimeException(
                            "Plugin {$slug} blocked: {$relPath} contains dangerous function call: {$funcName}()"
                        );
                    }
                }
            }
        }

        // Register this plugin's namespace so additional classes it ships (beyond the entrypoint)
        // autoload from its own directory - enabling real multi-file plugins.
        $this->registerPluginNamespace($manifest);

        require_once $entrypointFile;

        // Resolves the entry class name according to PSR-4 standards or manifest naming.
        $className = $this->resolveClassName($manifest);
        if (!class_exists($className)) {
            throw new \RuntimeException("Plugin class not found: {$className}");
        }

        if (!is_subclass_of($className, PluginInterface::class)) {
            throw new \RuntimeException("Plugin {$slug} must implement PluginInterface");
        }

        $capabilities = $manifest->capabilities ?? [];
        $sandbox = new PluginSandbox($manifest->path, $capabilities);

        /** @var PluginInterface $instance */
        $instance = new $className();
        $this->events->pushOwner($slug);
        try {
            $instance->register($this->events, $this->container);
            $this->registerManifestAdminMenu($manifest);
        } finally {
            $this->events->popOwner();
        }

        $this->registry->registerLoaded($slug, $instance, $manifest, $sandbox);
        $this->container->instance($className, $instance);
    }

    /**
     * Resolve the absolute path of a plugin folder on the server filesystem.
     *
     * @param array<string, mixed> $pluginData Plugin registry metadata.
     * @return string Absolute filesystem path to the plugin.
     */
    private function resolvePluginPath(array $pluginData): string
    {
        $configApp = $this->container->get('config.app');
        $modulesPath = '';
        if (is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths'])) {
            $modulesPath = is_string($configApp['paths']['modules'] ?? null) ? $configApp['paths']['modules'] : '';
        }
        $type = $pluginData['type'] ?? 'addon';

        $typeDir = match ($type) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };

        $slug = is_string($pluginData['slug'] ?? null) ? $pluginData['slug'] : '';
        return $modulesPath . '/' . $typeDir . '/' . $slug;
    }

    /**
     * Resolve the entrypoint class name associated with the plugin manifest.
     *
     * Checks the namespace declared in manifest.json first, falling back to a PSR-4 convention.
     *
     * @param \OwnPay\Plugin\PluginManifest $manifest The plugin's loaded manifest.
     * @return string Fully qualified class name.
     */
    private function resolveClassName(PluginManifest $manifest): string
    {
        // 1) Try manifest.json "namespace" field (most reliable)
        $manifestPath = $manifest->path . '/manifest.json';
        if (file_exists($manifestPath)) {
            $raw = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($raw) && !empty($raw['namespace']) && is_string($raw['namespace'])) {
                // Entry class name from entrypoint filename (e.g., Plugin.php → Plugin)
                $className = pathinfo($manifest->entrypoint, PATHINFO_FILENAME);
                return rtrim($raw['namespace'], '\\') . '\\' . $className;
            }
        }

        // 2) Fallback: convention-based PSR-4
        $pascal = str_replace('-', '', ucwords($manifest->slug, '-'));
        $entryClass = pathinfo($manifest->entrypoint, PATHINFO_FILENAME);
        return "OwnPay\\Plugins\\{$pascal}\\{$entryClass}";
    }

    /**
     * Registers a plugin's declared namespace for PSR-4 autoloading from its own directory.
     *
     * Mirrors resolveClassName()'s namespace resolution: the manifest "namespace" field if present,
     * otherwise the convention OwnPay\Plugins\{PascalSlug}. The namespace root maps to the plugin
     * directory, so e.g. {NS}\Service\Foo resolves to <pluginDir>/Service/Foo.php.
     *
     * @param \OwnPay\Plugin\PluginManifest $manifest The plugin manifest.
     * @return void
     */
    private function registerPluginNamespace(PluginManifest $manifest): void
    {
        $ns = $manifest->namespace;
        if ($ns === '') {
            $pascal = str_replace('-', '', ucwords($manifest->slug, '-'));
            $ns = "OwnPay\\Plugins\\{$pascal}";
        }
        $prefix = trim($ns, '\\') . '\\';
        $this->pluginNamespaces[$prefix] = rtrim($manifest->path, '/\\');
    }

    /**
     * Bridges a plugin's declarative manifest "admin_menu" to the admin.menu.register hook.
     *
     * Each item ({label, url}) renders as an escaped, internal-only (path-relative) sidebar link.
     * Registered under the active plugin owner, so it respects per-brand activation. Imperative menu
     * injection via the admin.menu.register hook remains available for richer needs.
     *
     * Expected manifest shape:
     *   "admin_menu": [ { "label": "My Plugin", "url": "/admin/plugins/my-plugin/settings" } ]
     *
     * @param \OwnPay\Plugin\PluginManifest $manifest The plugin manifest.
     * @return void
     */
    private function registerManifestAdminMenu(PluginManifest $manifest): void
    {
        if (empty($manifest->adminMenu)) {
            return;
        }

        $links = '';
        foreach ($manifest->adminMenu as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = is_string($item['label'] ?? null) ? trim($item['label']) : '';
            $url = is_string($item['url'] ?? null) ? trim($item['url']) : '';
            // Internal admin links only - never emit off-site or javascript: targets.
            if ($label === '' || !str_starts_with($url, '/')) {
                continue;
            }
            $links .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="op-nav-link"><span>'
                . htmlspecialchars($label, ENT_QUOTES) . '</span></a>';
        }

        if ($links === '') {
            return;
        }

        $this->events->addAction('admin.menu.register', static function () use ($links): void {
            echo $links;
        });
    }

    /**
     * PSR-4 autoloader for classes shipped by installed plugins.
     *
     * Strictly constrained to the registered plugin directory (realpath containment) so a crafted
     * class name can never load a file outside the plugin tree.
     *
     * @param string $class Fully qualified class name being resolved.
     * @return void
     */
    public function autoloadPluginClass(string $class): void
    {
        foreach ($this->pluginNamespaces as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';

            $realBase = realpath($baseDir);
            $realFile = realpath($file);
            if ($realBase === false || $realFile === false) {
                continue;
            }
            // Containment: only ever load files inside the plugin directory.
            if ($realFile !== $realBase && !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
                continue;
            }
            require $file;
            return;
        }
    }

    /**
     * Recursively find all PHP source files in a directory.
     *
     * Essential for executing the load-time plugin scanner over the entire plugin codebase.
     *
     * @param string $directory Absolute folder path to scan.
     * @return string[] Array of absolute file paths to PHP files.
     */
    private function findPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
