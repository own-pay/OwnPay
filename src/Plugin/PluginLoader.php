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

        // Boot phase — all plugins registered, now boot
        foreach ($this->registry->getLoaded() as $slug => $instance) {
            try {
                $instance->boot($this->container);
            } catch (\Throwable $e) {
                $this->registry->markError($slug, 'Boot failed: ' . $e->getMessage());
                $this->events->doAction('plugin.boot_error', $slug, $e);
            }
        }

        // Auto-register gateway adapters with the central GatewayBridge.
        // Gateway plugins implement GatewayAdapterInterface but typically leave
        // their boot() methods blank. We automatically register them here.
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

        // AUD-A4, AUD-G8: Scan all plugin PHP files recursively for security-restricted functions.
        // Checks tokens using PluginSandbox::isDangerousFunction() as the canonical rule list.
        $phpFiles = $this->findPhpFiles($manifest->path);
        foreach ($phpFiles as $phpFile) {
            $content = (string) file_get_contents($phpFile);
            // Extract function calls using token_get_all for accurate detection
            $tokens = @token_get_all($content);
            for ($i = 0, $count = count($tokens); $i < $count; $i++) {
                // Block dangerous language constructs (eval, include, require)
                if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                    $relPath = str_replace($manifest->path . '/', '', $phpFile);
                    throw new \RuntimeException(
                        "Plugin {$slug} blocked: {$relPath} contains restricted language construct: " . $tokens[$i][1]
                    );
                }

                // Block dangerous imports inside T_USE statements
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_USE) {
                    $next = $i + 1;
                    while ($next < $count && $tokens[$next] !== ';') {
                        $nextToken = $tokens[$next];
                        if (is_array($nextToken) && in_array($nextToken[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                            $importRaw = $nextToken[1];
                            $importBase = strtolower(ltrim(strrchr($importRaw, '\\') ?: $importRaw, '\\'));
                            
                            if (str_starts_with($importBase, 'reflection') || in_array($importBase, ['pdo', 'mysqli'], true)) {
                                $relPath = str_replace($manifest->path . '/', '', $phpFile);
                                throw new \RuntimeException(
                                    "Plugin {$slug} blocked: {$relPath} imports restricted reference: {$importRaw}"
                                );
                            }

                            if (PluginSandbox::isDangerousFunction($importBase)) {
                                $relPath = str_replace($manifest->path . '/', '', $phpFile);
                                throw new \RuntimeException(
                                    "Plugin {$slug} blocked: {$relPath} imports dangerous function: {$importRaw}"
                                );
                            }
                        }
                        $next++;
                    }
                }

                if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                    $rawName = $tokens[$i][1];
                    $funcName = ltrim($rawName, '\\');
                    $lowerFuncName = strtolower($funcName);

                    // Block banned classes and reflection references directly (no parenthetical check required)
                    if (str_starts_with($lowerFuncName, 'reflection') || in_array($lowerFuncName, ['pdo', 'mysqli'], true)) {
                        $relPath = str_replace($manifest->path . '/', '', $phpFile);
                        throw new \RuntimeException(
                            "Plugin {$slug} blocked: {$relPath} contains restricted reference: {$rawName}"
                        );
                    }

                    if (PluginSandbox::isDangerousFunction($funcName)) {
                        // Skip if the function identifier is part of an object or class declaration context.
                        $prev = $i - 1;
                        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                            $prev--;
                        }
                        $isOOPOrDecl = false;
                        if ($prev >= 0) {
                            $prevToken = $tokens[$prev];
                            if (is_array($prevToken)) {
                                $prevType = $prevToken[0];
                                if ($prevType === T_OBJECT_OPERATOR || 
                                    $prevType === T_DOUBLE_COLON || 
                                    $prevType === T_FUNCTION || 
                                    (defined('T_NULLSAFE_OBJECT_OPERATOR') && $prevType === T_NULLSAFE_OBJECT_OPERATOR)) {
                                    $isOOPOrDecl = true;
                                }
                            }
                        }
                        if ($isOOPOrDecl) {
                            continue;
                        }

                        // Confirm it is a function execution check by verifying it is followed by an opening parenthesis.
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

                // Block variable function calls: $func(...)
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE) {
                    $next = $i + 1;
                    while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                        $next++;
                    }
                    if ($next < $count && $tokens[$next] === '(') {
                        $relPath = str_replace($manifest->path . '/', '', $phpFile);
                        throw new \RuntimeException(
                            "Plugin {$slug} blocked: {$relPath} contains dynamic/variable function call: " . $tokens[$i][1] . "()"
                        );
                    }
                }

                // Block wrapped variable calls: ($func)(...)
                if ($tokens[$i] === ')') {
                    $next = $i + 1;
                    while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                        $next++;
                    }
                    if ($next < $count && $tokens[$next] === '(') {
                        $prev = $i - 1;
                        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                            $prev--;
                        }
                        if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_VARIABLE) {
                            $prev2 = $prev - 1;
                            while ($prev2 >= 0 && is_array($tokens[$prev2]) && $tokens[$prev2][0] === T_WHITESPACE) {
                                $prev2--;
                            }
                            if ($prev2 >= 0 && $tokens[$prev2] === '(') {
                                $relPath = str_replace($manifest->path . '/', '', $phpFile);
                                throw new \RuntimeException(
                                    "Plugin {$slug} blocked: {$relPath} contains dynamic/variable function call."
                                );
                            }
                        }
                    }
                }

                // Block dynamic class instantiations: new $class(...)
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_NEW) {
                    $next = $i + 1;
                    while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                        $next++;
                    }
                    if ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_VARIABLE) {
                        $relPath = str_replace($manifest->path . '/', '', $phpFile);
                        throw new \RuntimeException(
                            "Plugin {$slug} blocked: {$relPath} contains dynamic class instantiation: new " . $tokens[$next][1]
                        );
                    }
                }
            }
        }

        require_once $entrypointFile;

        // Resolves the entry class name according to PSR-4 standards or manifest naming.
        $className = $this->resolveClassName($manifest);
        if (!class_exists($className)) {
            throw new \RuntimeException("Plugin class not found: {$className}");
        }

        if (!is_subclass_of($className, PluginInterface::class)) {
            throw new \RuntimeException("Plugin {$slug} must implement PluginInterface");
        }

        // AUD-G8: Establish plugin sandbox instance based on capabilities declared in its manifest.
        $capabilities = $manifest->capabilities ?? [];
        $sandbox = new PluginSandbox($manifest->path, $capabilities);

        /** @var PluginInterface $instance */
        $instance = new $className();
        $this->events->pushOwner($slug);
        try {
            $instance->register($this->events, $this->container);
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
     * Recursively find all PHP source files in a directory.
     *
     * AUD-A4: Essential for executing the static security scanner over the entire plugin codebase.
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
