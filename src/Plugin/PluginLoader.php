<?php

declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Event\EventManager;

/**
 * Discovers, loads, and boots all active plugins on every request.
 *
 * Boot sequence (called once per request from adapter.php):
 *   1. Load the active-plugin list from cache (JSON file) or database
 *   2. For each active plugin:  resolve path → require entrypoint → instantiate → register()
 *   3. After ALL plugins registered:  call boot() on each
 *   4. Fire the "system.boot" action hook
 *
 * The loader also provides static helpers for admin lifecycle operations
 * (activate, deactivate, uninstall) which are called by PluginController.
 */
final class PluginLoader
{
    /** Maps plugin type → base directory relative to project root */
    private const TYPE_DIRS = [
        'plugin'  => 'app/modules/plugins',
        'gateway' => 'app/modules/gateways',
        'theme'   => 'app/modules/themes',
    ];

    private static bool $booted = false;
    private static ?PluginRegistry $registry = null;

    // Prevent instantiation
    private function __construct() {}

    // ── Boot sequence ───────────────────────────────────────────────

    /**
     * Main boot method — call once per request after middleware runs.
     *
     * Safe to call multiple times (idempotent).
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        $registry = new PluginRegistry();
        $em       = EventManager::getInstance();
        $root     = self::getProjectRoot();

        // 1. Load the list of active plugins
        $activeList = $registry->loadActiveList();

        // 2. Load, instantiate, and register each plugin
        $loadedSlugs = [];
        foreach ($activeList as $record) {
            $slug = $record['slug'];

            try {
                $plugin = self::loadPlugin($root, $record, $em);
                if ($plugin === null) {
                    continue;
                }

                $manifest = self::resolveManifest($root, $record);
                if ($manifest === null) {
                    continue;
                }

                $registry->add($slug, $plugin, $manifest);
                $loadedSlugs[] = $slug;
            } catch (\Throwable $e) {
                error_log(
                    "[OwnPay][PluginLoader] Failed to load plugin '{$slug}': "
                    . $e->getMessage()
                );
            }
        }

        // 3. Boot all registered plugins (post-registration phase)
        foreach ($loadedSlugs as $slug) {
            $plugin = $registry->get($slug);
            if ($plugin === null) {
                continue;
            }

            try {
                $plugin->boot();
            } catch (\Throwable $e) {
                error_log(
                    "[OwnPay][PluginLoader] Plugin '{$slug}' boot() threw: "
                    . $e->getMessage()
                );
            }
        }

        // 4. Fire the global system boot action
        $em->doAction('system.boot');

        self::$registry = $registry;
        self::$booted   = true;
    }

    /**
     * Get the registry of loaded plugins.
     *
     * @throws \LogicException if called before boot()
     */
    public static function getRegistry(): PluginRegistry
    {
        if (self::$registry === null) {
            throw new \LogicException('PluginLoader::boot() has not been called yet.');
        }
        return self::$registry;
    }

    /**
     * Check whether the loader has completed booting.
     */
    public static function isBooted(): bool
    {
        return self::$booted;
    }

    /**
     * Reset the loader state (for testing only).
     * @internal
     */
    public static function reset(): void
    {
        self::$booted   = false;
        self::$registry = null;
    }

    // ── Admin lifecycle operations ──────────────────────────────────

    /**
     * Activate a plugin: run its activate() method and mark active in DB.
     *
     * @return array{success: bool, message: string}
     */
    public static function activatePlugin(string $slug): array
    {
        $root = self::getProjectRoot();
        $prefix = $_ENV['DB_PREFIX'] ?? 'op_';

        // 1. Find the plugin directory and load manifest
        $manifest = self::findManifest($root, $slug);
        if ($manifest === null) {
            return ['success' => false, 'message' => "Plugin '{$slug}' not found on disk."];
        }

        $errors = $manifest->validate();
        if ($errors !== []) {
            return ['success' => false, 'message' => 'Invalid manifest: ' . implode('; ', $errors)];
        }

        // 2. Verify entrypoint exists and class is valid
        $entrypointPath = self::resolveEntrypoint($root, $manifest);
        if ($entrypointPath === null) {
            return ['success' => false, 'message' => "Entrypoint file not found: {$manifest->entrypoint}"];
        }

        require_once $entrypointPath;

        $className = $manifest->getFullyQualifiedClassName();
        if (!class_exists($className)) {
            return ['success' => false, 'message' => "Class '{$className}' not found after requiring entrypoint."];
        }

        if (!is_subclass_of($className, PluginInterface::class)) {
            return ['success' => false, 'message' => "Class '{$className}' does not implement PluginInterface."];
        }

        // 3. Instantiate and call activate()
        try {
            /** @var PluginInterface $plugin */
            $plugin = new $className();
            $plugin->activate();
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => "activate() threw: {$e->getMessage()}"];
        }

        // 4. Upsert into op_plugins table
        try {
            $db = \OwnPay\Core\Database::getInstance();
            $existing = $db->fetchOne(
                "SELECT id FROM `{$prefix}plugins` WHERE slug = :slug",
                ['slug' => $slug],
            );

            if ($existing !== null) {
                $db->execute(
                    "UPDATE `{$prefix}plugins`
                        SET status = 'active',
                            version = :version,
                            name = :name,
                            type = :type,
                            entrypoint = :entrypoint,
                            capabilities = :capabilities,
                            manifest_hash = :hash,
                            activated_at = NOW(),
                            updated_at = NOW()
                      WHERE slug = :slug",
                    [
                        'version'      => $manifest->version,
                        'name'         => $manifest->name,
                        'type'         => $manifest->type,
                        'entrypoint'   => $manifest->entrypoint,
                        'capabilities' => json_encode($manifest->capabilities),
                        'hash'         => $manifest->computeHash(),
                        'slug'         => $slug,
                    ],
                );
            } else {
                $db->execute(
                    "INSERT INTO `{$prefix}plugins`
                        (slug, name, type, version, status, entrypoint, capabilities, manifest_hash, load_order, activated_at, installed_at)
                     VALUES
                        (:slug, :name, :type, :version, 'active', :entrypoint, :capabilities, :hash, 100, NOW(), NOW())",
                    [
                        'slug'         => $slug,
                        'name'         => $manifest->name,
                        'type'         => $manifest->type,
                        'version'      => $manifest->version,
                        'entrypoint'   => $manifest->entrypoint,
                        'capabilities' => json_encode($manifest->capabilities),
                        'hash'         => $manifest->computeHash(),
                    ],
                );
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => "Database error: {$e->getMessage()}"];
        }

        // 5. Rebuild cache
        (new PluginRegistry())->rebuildCache();

        // 6. Fire hook
        EventManager::getInstance()->doAction('plugin.activated', $slug, $manifest);

        return ['success' => true, 'message' => "Plugin '{$manifest->name}' activated successfully."];
    }

    /**
     * Deactivate a plugin: run its deactivate() method and mark inactive in DB.
     *
     * @return array{success: bool, message: string}
     */
    public static function deactivatePlugin(string $slug): array
    {
        $prefix = $_ENV['DB_PREFIX'] ?? 'op_';

        // Run deactivate() if the plugin is currently loaded
        if (self::$registry !== null && self::$registry->has($slug)) {
            $plugin = self::$registry->get($slug);
            try {
                $plugin->deactivate();
            } catch (\Throwable $e) {
                error_log("[OwnPay][PluginLoader] deactivate() for '{$slug}' threw: {$e->getMessage()}");
            }

            // Remove all hooks registered by this plugin
            EventManager::getInstance()->removeAllByOwner($slug);
        }

        // Update DB
        try {
            $db = \OwnPay\Core\Database::getInstance();
            $db->execute(
                "UPDATE `{$prefix}plugins` SET status = 'inactive', updated_at = NOW() WHERE slug = :slug",
                ['slug' => $slug],
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => "Database error: {$e->getMessage()}"];
        }

        // Rebuild cache
        (new PluginRegistry())->rebuildCache();

        EventManager::getInstance()->doAction('plugin.deactivated', $slug);

        return ['success' => true, 'message' => "Plugin '{$slug}' deactivated."];
    }

    /**
     * Uninstall a plugin: run uninstall(), remove DB record, delete files.
     *
     * @return array{success: bool, message: string}
     */
    public static function uninstallPlugin(string $slug): array
    {
        $root = self::getProjectRoot();
        $prefix = $_ENV['DB_PREFIX'] ?? 'op_';

        // Deactivate first if active
        self::deactivatePlugin($slug);

        // Find and run uninstall()
        $manifest = self::findManifest($root, $slug);
        if ($manifest !== null) {
            $entrypointPath = self::resolveEntrypoint($root, $manifest);
            if ($entrypointPath !== null && is_file($entrypointPath)) {
                require_once $entrypointPath;
                $className = $manifest->getFullyQualifiedClassName();
                if (class_exists($className) && is_subclass_of($className, PluginInterface::class)) {
                    try {
                        (new $className())->uninstall();
                    } catch (\Throwable $e) {
                        error_log("[OwnPay][PluginLoader] uninstall() for '{$slug}' threw: {$e->getMessage()}");
                    }
                }
            }
        }

        // Remove DB record
        try {
            $db = \OwnPay\Core\Database::getInstance();
            $db->execute("DELETE FROM `{$prefix}plugins` WHERE slug = :slug", ['slug' => $slug]);
        } catch (\Throwable $e) {
            // Non-fatal — table might not exist
        }

        // Rebuild cache
        (new PluginRegistry())->rebuildCache();

        return ['success' => true, 'message' => "Plugin '{$slug}' uninstalled."];
    }

    // ── Internal helpers ────────────────────────────────────────────

    /**
     * Load a single plugin: require its entrypoint, instantiate, call register().
     */
    private static function loadPlugin(
        string $root,
        array $record,
        EventManager $em,
    ): ?PluginInterface {
        $slug       = $record['slug'];
        $type       = $record['type'];
        $entrypoint = $record['entrypoint'];

        $baseDir = self::TYPE_DIRS[$type] ?? null;
        if ($baseDir === null) {
            error_log("[OwnPay][PluginLoader] Unknown plugin type '{$type}' for '{$slug}'");
            return null;
        }

        // Resolve and validate the entrypoint path
        $fullPath = self::safeResolvePath($root, $baseDir, $slug, $entrypoint);
        if ($fullPath === null) {
            error_log("[OwnPay][PluginLoader] Entrypoint not found for plugin '{$slug}'");
            return null;
        }

        require_once $fullPath;

        // Resolve the manifest to get the FQCN
        $manifest = self::resolveManifest($root, $record);
        if ($manifest === null) {
            error_log("[OwnPay][PluginLoader] Manifest not found for plugin '{$slug}'");
            return null;
        }

        $className = $manifest->getFullyQualifiedClassName();
        if (!class_exists($className)) {
            error_log("[OwnPay][PluginLoader] Class '{$className}' not found for plugin '{$slug}'");
            return null;
        }

        $plugin = new $className();
        if (!$plugin instanceof PluginInterface) {
            error_log("[OwnPay][PluginLoader] '{$className}' does not implement PluginInterface");
            return null;
        }

        $plugin->register($em);
        return $plugin;
    }

    /**
     * Resolve and parse a plugin's manifest.json.
     */
    private static function resolveManifest(string $root, array $record): ?PluginManifest
    {
        $baseDir = self::TYPE_DIRS[$record['type']] ?? null;
        if ($baseDir === null) {
            return null;
        }

        $manifestPath = $root . '/' . $baseDir . '/' . $record['slug'] . '/manifest.json';
        if (!is_file($manifestPath)) {
            return null;
        }

        try {
            return PluginManifest::fromFile($manifestPath);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Find a manifest by scanning all type directories for a given slug.
     */
    private static function findManifest(string $root, string $slug): ?PluginManifest
    {
        foreach (self::TYPE_DIRS as $type => $dir) {
            $path = $root . '/' . $dir . '/' . $slug . '/manifest.json';
            if (is_file($path)) {
                try {
                    return PluginManifest::fromFile($path);
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        return null;
    }

    /**
     * Resolve a plugin entrypoint path from its manifest.
     */
    private static function resolveEntrypoint(string $root, PluginManifest $manifest): ?string
    {
        $baseDir = self::TYPE_DIRS[$manifest->type] ?? null;
        if ($baseDir === null) {
            return null;
        }

        return self::safeResolvePath($root, $baseDir, $manifest->slug, $manifest->entrypoint);
    }

    /**
     * Safely resolve a plugin file path with containment validation.
     *
     * Ensures the resolved absolute path is within the expected base directory,
     * preventing any path traversal attacks.
     */
    private static function safeResolvePath(
        string $root,
        string $baseDir,
        string $slug,
        string $filename,
    ): ?string {
        // Validate slug format (defense in depth — manifest already checks)
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/', $slug) && !preg_match('/^[a-z0-9]$/', $slug)) {
            return null;
        }

        // Validate filename has no path separators
        if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            return null;
        }

        $targetPath = realpath($root . '/' . $baseDir . '/' . $slug . '/' . $filename);
        $basePath   = realpath($root . '/' . $baseDir);

        if ($targetPath === false || $basePath === false) {
            return null;
        }

        // Containment check
        if (!str_starts_with($targetPath, $basePath . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $targetPath;
    }

    private static function getProjectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
