<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Repository\PluginRepository;

/**
 * Plugin manager â€” high-level API for install/activate/deactivate/uninstall.
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
     * Activate plugin â€” run migrations, load it.
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
        $ran = $this->migrator->migrate($slug, $migrationsDir);

        // Activate in DB
        $this->repo->activate($slug);

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

        $this->events->doAction('plugin.deactivated', $slug);

        return ['success' => true];
    }

    /**
     * Uninstall plugin â€” deactivate, rollback migrations, remove files.
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
            'discovered' => $discovered,
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
}
