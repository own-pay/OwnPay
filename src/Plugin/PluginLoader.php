<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * Plugin loader â€” discovers, validates, and loads active plugins.
 *
 * Scan order: modules/gateways/, modules/themes/, modules/addons/
 * Each plugin dir must contain manifest.json + entrypoint class.
 */
final class PluginLoader
{
    private Container $container;
    private EventManager $events;
    private PluginRegistry $registry;

    /** @var string[] Plugin base directories */
    private array $scanDirs;

    public function __construct(Container $container, EventManager $events, PluginRegistry $registry)
    {
        $this->container = $container;
        $this->events = $events;
        $this->registry = $registry;

        $paths = $container->get('config.app')['paths'];
        $this->scanDirs = [
            $paths['modules'] . '/gateways',
            $paths['modules'] . '/themes',
            $paths['modules'] . '/addons',
        ];
    }

    /**
     * Discover all plugins from filesystem.
     * @return PluginManifest[]
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
     * Load and register all active plugins.
     * Called during Kernel boot.
     */
    public function loadActive(): void
    {
        $this->events->doAction('plugins.before_load');

        $activePlugins = $this->registry->getActive();

        foreach ($activePlugins as $pluginData) {
            try {
                $this->loadPlugin($pluginData);
            } catch (\Throwable $e) {
                // Mark plugin as errored, don't crash app
                $this->registry->markError($pluginData['slug'], $e->getMessage());
                $this->events->doAction('plugin.load_error', $pluginData['slug'], $e);
            }
        }

        // Boot phase â€” all plugins registered, now boot
        foreach ($this->registry->getLoaded() as $slug => $instance) {
            try {
                $instance->boot($this->container);
            } catch (\Throwable $e) {
                $this->registry->markError($slug, 'Boot failed: ' . $e->getMessage());
                $this->events->doAction('plugin.boot_error', $slug, $e);
            }
        }

        $this->events->doAction('plugins.after_load');
    }

    /**
     * Load single plugin: validate â†’ require â†’ instantiate â†’ register.
     */
    private function loadPlugin(array $pluginData): void
    {
        $slug = $pluginData['slug'];
        $manifest = PluginManifest::fromDirectory($this->resolvePluginPath($pluginData));

        if ($manifest === null) {
            throw new \RuntimeException("Plugin manifest not found for: {$slug}");
        }

        $errors = $manifest->validate();
        if (!empty($errors)) {
            throw new \RuntimeException("Invalid manifest for {$slug}: " . implode(', ', $errors));
        }

        // Version compatibility check
        $coreVersion = $this->container->get('config.app')['version'] ?? '0.1.0';
        if (!$manifest->isCompatible($coreVersion)) {
            throw new \RuntimeException("Plugin {$slug} requires core {$manifest->requires['core']} (current: {$coreVersion})");
        }

        // Load entrypoint
        $entrypointFile = $manifest->path . '/' . $manifest->entrypoint;
        if (!file_exists($entrypointFile)) {
            throw new \RuntimeException("Entrypoint not found: {$entrypointFile}");
        }

        require_once $entrypointFile;

        // Resolve class name (PSR-4 or declared in manifest)
        $className = $this->resolveClassName($manifest);
        if (!class_exists($className)) {
            throw new \RuntimeException("Plugin class not found: {$className}");
        }

        if (!is_subclass_of($className, PluginInterface::class)) {
            throw new \RuntimeException("Plugin {$slug} must implement PluginInterface");
        }

        /** @var PluginInterface $instance */
        $instance = new $className();
        $instance->register($this->events, $this->container);

        $this->registry->registerLoaded($slug, $instance, $manifest);
    }

    private function resolvePluginPath(array $pluginData): string
    {
        $paths = $this->container->get('config.app')['paths'];
        $type = $pluginData['type'] ?? 'addon';

        $typeDir = match ($type) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };

        return $paths['modules'] . '/' . $typeDir . '/' . $pluginData['slug'];
    }

    private function resolveClassName(PluginManifest $manifest): string
    {
        // 1) Try manifest.json "namespace" field (most reliable)
        $manifestPath = $manifest->path . '/manifest.json';
        if (file_exists($manifestPath)) {
            $raw = json_decode((string) file_get_contents($manifestPath), true);
            if (!empty($raw['namespace'])) {
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
}
