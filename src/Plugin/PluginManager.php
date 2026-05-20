<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Repository\PluginRepository;
use OwnPay\Repository\GatewayRepository;

/**
 * Plugin manager — high-level API for install/activate/deactivate/uninstall.
 *
 * Orchestrates PluginInstaller, PluginMigrator, PluginRegistry.
 * Fires lifecycle hooks at each step for other plugins to react.
 */
final class PluginManager
{
    private Container $container;
    private EventManager $events;
    private PluginRepository $repo;
    private PluginInstaller $installer;
    private PluginMigrator $migrator;
    private PluginRegistry $registry;

    public function __construct(
        Container $container,
        EventManager $events,
        PluginRepository $repo,
        PluginInstaller $installer,
        PluginMigrator $migrator,
        PluginRegistry $registry
    ) {
        $this->container = $container;
        $this->events = $events;
        $this->repo = $repo;
        $this->installer = $installer;
        $this->migrator = $migrator;
        $this->registry = $registry;
    }

    /**
     * Install plugin from ZIP.
     * @return array{success: bool, slug?: string, error?: string}
     */
    public function install(string $zipPath): array
    {
        $this->events->doAction('plugin.before_install', $zipPath);

        $result = $this->installer->installFromZip($zipPath);
        if (!$result['success']) {
            return $result;
        }

        $slug = $result['slug'];

        // Discover manifest for DB record
        $loader = $this->container->get(PluginLoader::class);
        $manifests = $loader->discover();
        $manifest = $manifests[$slug] ?? null;

        if ($manifest === null) {
            return ['success' => false, 'error' => 'Plugin installed but manifest not found'];
        }

        // Register in DB
        $this->repo->create([
            'slug'         => $manifest->slug,
            'name'         => $manifest->name,
            'type'         => $manifest->type,
            'version'      => $manifest->version,
            'entrypoint'   => $manifest->entrypoint,
            'capabilities' => json_encode($manifest->capabilities),
            'manifest'     => json_encode($manifest->toArray()),
            'status'       => 'inactive',
        ]);

        $this->events->doAction('plugin.installed', $slug, $manifest);

        return ['success' => true, 'slug' => $slug];
    }

    /**
     * Activate plugin — run migrations, load it.
     */
    public function activate(string $slug): array
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            // Discover manifest for DB record
            $loader = $this->container->get(PluginLoader::class);
            $manifests = $loader->discover();
            $manifest = $manifests[$slug] ?? null;

            if ($manifest === null) {
                return ['success' => false, 'error' => 'Plugin not found'];
            }

            // Register in DB
            $this->repo->create([
                'slug'         => $manifest->slug,
                'name'         => $manifest->name,
                'type'         => $manifest->type,
                'version'      => $manifest->version,
                'entrypoint'   => $manifest->entrypoint,
                'capabilities' => json_encode($manifest->capabilities),
                'manifest'     => json_encode($manifest->toArray()),
                'status'       => 'inactive',
            ]);

            $plugin = $this->repo->findBySlug($slug);
        }

        if ($plugin['status'] === 'active') {
            return ['success' => true, 'message' => 'Already active'];
        }

        $this->events->doAction('plugin.before_activate', $slug);

        // Run migrations
        $migrationsDir = $this->resolveDir($plugin) . '/migrations';
        try {
            $ran = $this->migrator->migrate($slug, $migrationsDir);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Migration failed: ' . $e->getMessage()];
        }

        // Activate in DB
        $this->repo->activate($slug);

        // Verify plugin can actually boot — if it can't, revert
        try {
            $loader = $this->container->get(PluginLoader::class);
            $manifest = $loader->discover()[$slug] ?? null;
            if ($manifest !== null) {
                $entrypointFile = $manifest->path . '/' . $manifest->entrypoint;
                if (file_exists($entrypointFile)) {
                    // Validate the entrypoint is loadable
                    $errors = $manifest->validate();
                    if (!empty($errors)) {
                        throw new \RuntimeException('Invalid manifest: ' . implode(', ', $errors));
                    }
                }
            }
        } catch (\Throwable $e) {
            // Boot will fail — revert activation
            $this->repo->deactivate($slug);
            return ['success' => false, 'error' => 'Plugin activation failed: ' . $e->getMessage()];
        }

        // Auto-register gateway definition in op_gateways (required for checkout visibility)
        if ($plugin['type'] === 'gateway') {
            $this->registerGatewayDefinition($slug, $plugin);
        }

        $this->events->doAction('plugin.activated', $slug, $ran);

        return ['success' => true, 'migrations_run' => count($ran)];
    }

    /**
     * Deactivate plugin.
     */
    public function deactivate(string $slug): array
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        $this->events->doAction('plugin.before_deactivate', $slug);

        // Call deactivate on instance if loaded
        $instance = $this->registry->get($slug);
        if ($instance !== null) {
            $instance->deactivate($this->container);
        }

        $this->repo->deactivate($slug);

        // Deactivate gateway definition if this is a gateway plugin
        if ($plugin['type'] === 'gateway') {
            $gwRepo = $this->container->get(GatewayRepository::class);
            $gw = $gwRepo->findBySlug($slug);
            if ($gw !== null) {
                $gwRepo->update((int) $gw['id'], ['status' => 'inactive']);
            }
        }

        $this->events->doAction('plugin.deactivated', $slug);

        return ['success' => true];
    }

    /**
     * Uninstall plugin — deactivate, rollback migrations, remove files.
     */
    public function uninstall(string $slug): array
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        $this->events->doAction('plugin.before_uninstall', $slug);

        // Deactivate first
        if ($plugin['status'] === 'active') {
            $this->deactivate($slug);
        }

        // Call uninstall on instance if available
        $instance = $this->registry->get($slug);
        if ($instance !== null) {
            $instance->uninstall($this->container);
        }

        // Rollback all migrations
        $migrationsDir = $this->resolveDir($plugin) . '/migrations';
        while (!empty($this->migrator->rollback($slug, $migrationsDir))) {
            // Keep rolling back batches
        }

        // Remove files
        $this->installer->uninstall($slug, $plugin['type']);

        // Remove DB record
        $this->repo->delete((int) $plugin['id']);

        $this->events->doAction('plugin.uninstalled', $slug);

        return ['success' => true];
    }

    /**
     * Get plugin info for admin UI.
     * @return array{installed: array, available: array}
     */
    public function listAll(): array
    {
        $loader = $this->container->get(PluginLoader::class);
        $discovered = $loader->discover();
        $installed = [];

        $dbPlugins = $this->repo->paginate(1, 100);
        foreach ($dbPlugins['items'] as $p) {
            $installed[$p['slug']] = $p;
        }

        return [
            'installed'  => $installed,
            'available' => $discovered,
        ];
    }

    private function resolveDir(array $plugin): string
    {
        $paths = $this->container->get('config.app')['paths'];
        $typeDir = match ($plugin['type']) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };
        return $paths['modules'] . '/' . $typeDir . '/' . $plugin['slug'];
    }

    /**
     * Register gateway plugin in op_gateways table (upsert).
     * Required so checkout can discover API gateways via GatewayConfigRepository JOIN.
     */
    private function registerGatewayDefinition(string $slug, array $plugin): void
    {
        $gwRepo = $this->container->get(GatewayRepository::class);
        $existing = $gwRepo->findBySlug($slug);

        if ($existing !== null) {
            // Re-activate existing definition + update logo
            $loader = $this->container->get(PluginLoader::class);
            $manifest = $loader->discover()[$slug] ?? null;
            $logoPath = $this->resolveIconPath($slug, $plugin, $manifest);
            $gwRepo->update((int) $existing['id'], [
                'status'    => 'active',
                'logo_path' => $logoPath,
            ]);
        } else {
            // Create new gateway definition from plugin metadata
            $loader = $this->container->get(PluginLoader::class);
            $manifest = $loader->discover()[$slug] ?? null;
            $logoPath = $this->resolveIconPath($slug, $plugin, $manifest);

            $manifestName = $manifest ? $manifest->name : null;
            $gwRepo->create([
                'slug'       => $slug,
                'name'       => $manifestName ?? $plugin['name'] ?? $slug,
                'type'       => 'api',
                'logo_path'  => $logoPath,
                'is_builtin' => 0,
                'sort_order'  => 0,
                'status'     => 'active',
            ]);
        }
    }

    /**
     * Resolve gateway icon to a public-accessible path.
     * Copies icon from module dir to public/assets/img/gateways/ if it exists.
     */
    private function resolveIconPath(string $slug, array $plugin, ?PluginManifest $manifest): ?string
    {
        $iconFile = $manifest ? $manifest->icon : '';
        if ($iconFile === '') {
            return null;
        }

        $paths = $this->container->get('config.app')['paths'];
        $typeDir = match ($plugin['type'] ?? 'gateway') {
            'gateway' => 'gateways',
            default   => 'addons',
        };
        $srcPath = $paths['modules'] . '/' . $typeDir . '/' . $slug . '/' . $iconFile;
        if (!file_exists($srcPath)) {
            return null;
        }

        // Copy to public assets
        $destDir = $paths['public'] . '/assets/img/gateways';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $ext = pathinfo($iconFile, PATHINFO_EXTENSION);
        $destFile = $slug . '.' . $ext;
        copy($srcPath, $destDir . '/' . $destFile);

        return '/assets/img/gateways/' . $destFile;
    }
}
