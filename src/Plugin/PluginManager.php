<?php
declare(strict_types=1);

namespace OwnPay\Plugin;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Repository\PluginRepository;
use OwnPay\Repository\GatewayRepository;

/**
 * High-level orchestration manager for the OwnPay plugin ecosystem.
 *
 * Provides a unified API to handle plugin installation, activation, deactivation,
 * and uninstallation. Coordinates operations between PluginInstaller, PluginMigrator,
 * and PluginRegistry while triggering lifecycle actions that allow external
 * modules to hook into core state changes.
 *
 * @category Plugin
 * @package  OwnPay\Plugin
 */
final class PluginManager
{
    /**
     * The PSR-11 compatible dependency injection container.
     *
     * @var \OwnPay\Container
     */
    private Container $container;

    /**
     * The application event and hook manager.
     *
     * @var \OwnPay\Event\EventManager
     */
    private EventManager $events;

    /**
     * Database repository for plugin records.
     *
     * @var \OwnPay\Repository\PluginRepository
     */
    private PluginRepository $repo;

    /**
     * File system ZIP installer and validator service.
     *
     * @var \OwnPay\Plugin\PluginInstaller
     */
    private PluginInstaller $installer;

    /**
     * Database migrations manager for plugins.
     *
     * @var \OwnPay\Plugin\PluginMigrator
     */
    private PluginMigrator $migrator;

    /**
     * Active plugin runtime instance registry.
     *
     * @var \OwnPay\Plugin\PluginRegistry
     */
    private PluginRegistry $registry;

    /**
     * PluginManager constructor.
     *
     * @param \OwnPay\Container                 $container Dependency injection container.
     * @param \OwnPay\Event\EventManager        $events    Central event/hook manager.
     * @param \OwnPay\Repository\PluginRepository $repo      Repository for persisting plugin state.
     * @param \OwnPay\Plugin\PluginInstaller    $installer Installer for handling ZIP archives.
     * @param \OwnPay\Plugin\PluginMigrator     $migrator  Migrator for running schema changes.
     * @param \OwnPay\Plugin\PluginRegistry     $registry  Registry storing instantiated plugins.
     */
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
     * Installs a plugin from a local ZIP file.
     *
     * Fires pre and post installation hooks and registers the plugin record in the DB.
     *
     * @param string $zipPath Absolute path to the plugin ZIP archive.
     * @return array{success: bool, error?: string, slug?: string, code?: string, existing_version?: string, new_version?: string, has_migrations?: bool} Status of the installation.
     */
    public function install(string $zipPath): array
    {
        $this->events->doAction('plugin.before_install', $zipPath);

        $result = $this->installer->installFromZip($zipPath);
        if (!$result['success']) {
            return $result;
        }

        $slug = $result['slug'] ?? '';
        if ($slug === '') {
            return ['success' => false, 'error' => 'Installation failed: missing slug.'];
        }

        $loader = $this->container->get(PluginLoader::class);
        if (!$loader instanceof PluginLoader) {
            return ['success' => false, 'error' => 'PluginLoader not found'];
        }
        $manifests = $loader->discover();
        $manifest = $manifests[$slug] ?? null;

        if ($manifest === null) {
            return ['success' => false, 'error' => 'Plugin installed but manifest not found'];
        }

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
     * Activates an installed plugin, executing database migrations and loading its instance.
     * Supports brand-scoped activation if brand ID context is provided.
     *
     * @param string   $slug    Unique plugin identifier.
     * @param int|null $brandId The brand ID context.
     * @return array{success: bool, message?: string, error?: string, migrations_run?: int} Activation outcome.
     */
    public function activate(string $slug, ?int $brandId = null): array
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            $loader = $this->container->get(PluginLoader::class);
            if (!$loader instanceof PluginLoader) {
                return ['success' => false, 'error' => 'PluginLoader not found'];
            }
            $manifests = $loader->discover();
            $manifest = $manifests[$slug] ?? null;

            if ($manifest === null) {
                return ['success' => false, 'error' => 'Plugin not found'];
            }

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

        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin record not found in database'];
        }

        if ($brandId !== null && $brandId > 0) {
            if ($this->repo->isPluginActiveForBrand($slug, $brandId)) {
                return ['success' => true, 'message' => 'Already active for this brand'];
            }
        } else {
            if ($plugin['status'] === 'active') {
                return ['success' => true, 'message' => 'Already active'];
            }
        }

        $this->events->doAction('plugin.before_activate', $slug, $brandId);

        $migrationsDir = $this->resolveDir($plugin) . '/migrations';
        try {
            $ran = $this->migrator->migrate($slug, $migrationsDir);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Migration failed: ' . $e->getMessage()];
        }

        if ($brandId !== null && $brandId > 0) {
            $this->repo->setBrandPluginStatus($slug, $brandId, 'active');
            $this->registry->clearBrandActiveCache($brandId);
        } else {
            $this->repo->activate($slug);
        }

        try {
            $loader = $this->container->get(PluginLoader::class);
            if ($loader instanceof PluginLoader) {
                $manifest = $loader->discover()[$slug] ?? null;
                if ($manifest !== null) {
                    $entrypointFile = $manifest->path . '/' . $manifest->entrypoint;
                    if (file_exists($entrypointFile)) {
                        $errors = $manifest->validate();
                        if (!empty($errors)) {
                            throw new \RuntimeException('Invalid manifest: ' . implode(', ', $errors));
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($brandId !== null && $brandId > 0) {
                $this->repo->setBrandPluginStatus($slug, $brandId, 'inactive');
                $this->registry->clearBrandActiveCache($brandId);
            } else {
                $this->repo->deactivate($slug);
            }
            return ['success' => false, 'error' => 'Plugin activation failed: ' . $e->getMessage()];
        }

        if ($plugin['type'] === 'gateway') {
            $this->registerGatewayDefinition($slug, $plugin);

            // Synchronize with op_gateway_configs if activated for a brand context
            if ($brandId !== null && $brandId > 0) {
                $gwRepo = $this->container->get(GatewayRepository::class);
                if ($gwRepo instanceof GatewayRepository) {
                    $gw = $gwRepo->findBySlug($slug);
                    if ($gw !== null) {
                        $gwId = is_numeric($gw['id'] ?? null) ? (int) $gw['id'] : 0;
                        if ($gwId > 0) {
                            $gwConfigRepo = $this->container->get(\OwnPay\Repository\GatewayConfigRepository::class);
                            if ($gwConfigRepo instanceof \OwnPay\Repository\GatewayConfigRepository) {
                                $scopedConfigRepo = $gwConfigRepo->forTenant($brandId);
                                $existing = $scopedConfigRepo->findForGateway($gwId);
                                if ($existing !== null) {
                                    $configId = is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;
                                    $scopedConfigRepo->updateScoped($configId, [
                                        'status' => 'active',
                                    ]);
                                } else {
                                    $scopedConfigRepo->createScoped([
                                        'merchant_id' => $brandId,
                                        'gateway_id'  => $gwId,
                                        'status'      => 'active',
                                        'mode'        => 'sandbox',
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->events->doAction('plugin.activated', $slug, $ran, $brandId);

        return ['success' => true, 'migrations_run' => count($ran)];
    }

    /**
     * Deactivates an active plugin, disabling its hooks and gateway configurations.
     * Supports brand-scoped deactivation if brand ID context is provided.
     *
     * @param string   $slug    Unique plugin identifier.
     * @param int|null $brandId The brand ID context.
     * @return array{success: bool, error?: string} Deactivation outcome.
     */
    public function deactivate(string $slug, ?int $brandId = null): array
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        $this->events->doAction('plugin.before_deactivate', $slug, $brandId);

        if ($brandId === null || $brandId <= 0) {
            $instance = $this->registry->get($slug);
            if ($instance !== null) {
                $instance->deactivate($this->container);
            }

            $this->repo->deactivate($slug);

            if ($plugin['type'] === 'gateway') {
                $gwRepo = $this->container->get(GatewayRepository::class);
                if ($gwRepo instanceof GatewayRepository) {
                    $gw = $gwRepo->findBySlug($slug);
                    if (is_array($gw) && isset($gw['id'])) {
                        $gwId = is_numeric($gw['id']) ? (int) $gw['id'] : 0;
                        $gwRepo->update($gwId, ['status' => 'inactive']);
                    }
                }
            }
        } else {
            $this->repo->setBrandPluginStatus($slug, $brandId, 'inactive');
            $this->registry->clearBrandActiveCache($brandId);

            // Synchronize with op_gateway_configs if this plugin is a gateway
            if (($plugin['type'] ?? '') === 'gateway') {
                $gwRepo = $this->container->get(GatewayRepository::class);
                if ($gwRepo instanceof GatewayRepository) {
                    $gw = $gwRepo->findBySlug($slug);
                    if ($gw !== null) {
                        $gwId = is_numeric($gw['id'] ?? null) ? (int) $gw['id'] : 0;
                        if ($gwId > 0) {
                            $gwConfigRepo = $this->container->get(\OwnPay\Repository\GatewayConfigRepository::class);
                            if ($gwConfigRepo instanceof \OwnPay\Repository\GatewayConfigRepository) {
                                $scopedConfigRepo = $gwConfigRepo->forTenant($brandId);
                                $existing = $scopedConfigRepo->findForGateway($gwId);
                                if ($existing !== null) {
                                    $configId = is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;
                                    $scopedConfigRepo->updateScoped($configId, [
                                        'status' => 'inactive',
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->events->doAction('plugin.deactivated', $slug, $brandId);

        return ['success' => true];
    }

    /**
     * Uninstalls a plugin by removing database records, rolling back migrations, and deleting files.
     * Stops and throws if the plugin is currently active on one or more brands.
     *
     * @param string $slug Unique plugin identifier.
     * @return array{success: bool, error?: string} Uninstallation outcome.
     * @throws \OwnPay\Plugin\Exception\PluginInUseException
     */
    public function uninstall(string $slug): array
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        $activeCount = $this->repo->countActiveBrandInstances($slug);
        if ($activeCount > 0) {
            throw new \OwnPay\Plugin\Exception\PluginInUseException(
                "Plugin '{$slug}' cannot be uninstalled because it is currently active on one or more brands."
            );
        }

        $this->events->doAction('plugin.before_uninstall', $slug);

        if ($plugin['status'] === 'active') {
            $this->deactivate($slug);
        }

        $instance = $this->registry->get($slug);
        if ($instance !== null) {
            $instance->uninstall($this->container);
        }

        $migrationsDir = $this->resolveDir($plugin) . '/migrations';
        while (!empty($this->migrator->rollback($slug, $migrationsDir))) {
            // Roll back in batches.
        }

        $pluginType = is_string($plugin['type'] ?? null) ? $plugin['type'] : 'addon';
        $this->installer->uninstall($slug, $pluginType);

        $pluginId = is_numeric($plugin['id'] ?? null) ? (int) $plugin['id'] : 0;
        $this->repo->delete($pluginId);

        $this->events->doAction('plugin.uninstalled', $slug);

        return ['success' => true];
    }

    /**
     * Compiles a list of both active/installed and available plugins.
     *
     * @return array{installed: array<string, array<string, mixed>>, available: array<string, \OwnPay\Plugin\PluginManifest>} Map of plugins.
     */
    public function listAll(): array
    {
        $loader = $this->container->get(PluginLoader::class);
        $discovered = [];
        if ($loader instanceof PluginLoader) {
            $discovered = $loader->discover();
        }
        $installed = [];

        $dbPlugins = $this->repo->paginate(1, 100);
        foreach ($dbPlugins['items'] as $p) {
            $slug = is_string($p['slug'] ?? null) ? $p['slug'] : '';
            if ($slug !== '') {
                $installed[$slug] = $p;
            }
        }

        return [
            'installed'  => $installed,
            'available' => $discovered,
        ];
    }

    /**
     * Updates an already installed plugin from a local ZIP file.
     * Overwrites filesystem files, runs pending migrations, and updates the database record.
     *
     * @param string $zipPath Absolute path to the plugin ZIP archive.
     * @return array{success: bool, error?: string, slug?: string, code?: string, existing_version?: string, new_version?: string, has_migrations?: bool} Status of the update.
     */
    public function update(string $zipPath): array
    {
        $this->events->doAction('plugin.before_update', $zipPath);

        // Perform overwrite installation
        $result = $this->installer->installFromZip($zipPath, true);
        if (!$result['success']) {
            return $result;
        }

        $slug = $result['slug'] ?? '';
        if ($slug === '') {
            return ['success' => false, 'error' => 'Update failed: missing slug.'];
        }

        $loader = $this->container->get(PluginLoader::class);
        if (!$loader instanceof PluginLoader) {
            return ['success' => false, 'error' => 'PluginLoader not found'];
        }
        $manifests = $loader->discover();
        $manifest = $manifests[$slug] ?? null;

        if ($manifest === null) {
            return ['success' => false, 'error' => 'Plugin updated but manifest not found'];
        }

        // Run migrations
        $migrationsDir = $manifest->path . '/migrations';
        $ran = [];
        try {
            $ran = $this->migrator->migrate($slug, $migrationsDir);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Migration failed: ' . $e->getMessage()];
        }

        // Update database record
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin !== null) {
            $pluginId = is_numeric($plugin['id'] ?? null) ? (int) $plugin['id'] : 0;
            $this->repo->update($pluginId, [
                'name'         => $manifest->name,
                'version'      => $manifest->version,
                'entrypoint'   => $manifest->entrypoint,
                'capabilities' => json_encode($manifest->capabilities),
                'manifest'     => json_encode($manifest->toArray()),
            ]);
        } else {
            // Fallback: create if missing in DB
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
        }

        // If it's a gateway, update gateway definition (e.g. logo path)
        if ($manifest->type === 'gateway') {
            $pluginRecord = $this->repo->findBySlug($slug);
            if ($pluginRecord !== null) {
                $this->registerGatewayDefinition($slug, $pluginRecord);
            }
        }

        $this->events->doAction('plugin.updated', $slug, $manifest, $ran);

        return ['success' => true, 'slug' => $slug];
    }

    /**
     * Resolves the absolute directory path of a plugin.
     *
     * @param array<string, mixed> $plugin DB plugin representation.
     * @return string Absolute file path.
     */
    private function resolveDir(array $plugin): string
    {
        $configApp = $this->container->get('config.app');
        $paths = is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths']) ? $configApp['paths'] : [];
        $storagePath = is_string($paths['storage'] ?? null) ? $paths['storage'] : '';
        $modulesPath = is_string($paths['modules'] ?? null) ? $paths['modules'] : '';

        $type = is_string($plugin['type'] ?? null) ? $plugin['type'] : 'addon';
        $typeDir = match ($type) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };
        $slug = is_string($plugin['slug'] ?? null) ? $plugin['slug'] : '';
        if (($plugin['status'] ?? '') === 'trashed') {
            return $storagePath . '/trash/plugins/' . $typeDir . '/' . $slug;
        }
        return $modulesPath . '/' . $typeDir . '/' . $slug;
    }

    /**
     * Moves a plugin to the trash storage folder and updates its status to 'trashed'.
     *
     * @param string $slug Unique plugin identifier.
     * @return array{success: bool, error?: string} Trashing outcome.
     */
    public function trash(string $slug): array
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        if (($plugin['status'] ?? '') === 'trashed') {
            return ['success' => true];
        }

        // Deactivate plugin first if it is active to safely remove hooks and references
        if ($plugin['status'] === 'active') {
            $this->deactivate($slug);
            // Refresh plugin record
            $plugin = $this->repo->findBySlug($slug);
            if ($plugin === null) {
                return ['success' => false, 'error' => 'Plugin not found after deactivation'];
            }
        }

        $configApp = $this->container->get('config.app');
        $paths = is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths']) ? $configApp['paths'] : [];
        $storagePath = is_string($paths['storage'] ?? null) ? $paths['storage'] : '';
        $modulesPath = is_string($paths['modules'] ?? null) ? $paths['modules'] : '';

        $type = is_string($plugin['type'] ?? null) ? $plugin['type'] : 'addon';
        $typeDir = match ($type) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };

        $slugStr = is_string($plugin['slug'] ?? null) ? $plugin['slug'] : '';
        $srcDir = $modulesPath . '/' . $typeDir . '/' . $slugStr;
        $destDir = $storagePath . '/trash/plugins/' . $typeDir . '/' . $slugStr;

        if (!is_dir($srcDir)) {
            return ['success' => false, 'error' => 'Plugin files not found in modules path'];
        }

        // Move the files to the trash folder
        $parentDir = dirname($destDir);
        if (!is_dir($parentDir)) {
            @mkdir($parentDir, 0755, true);
        }

        // Use rename or copy fallback
        if (!@rename($srcDir, $destDir)) {
            $this->copyDir($srcDir, $destDir);
            $this->removeDir($srcDir);
        }

        // Update database status to trashed
        $pluginId = is_numeric($plugin['id'] ?? null) ? (int) $plugin['id'] : 0;
        $this->repo->update($pluginId, ['status' => 'trashed']);

        $this->events->doAction('plugin.trashed', $slug);

        return ['success' => true];
    }

    /**
     * Restores a trashed plugin back to the live modules tree.
     *
     * @param string $slug Unique plugin identifier.
     * @return array{success: bool, error?: string} Restoration outcome.
     */
    public function restore(string $slug): array
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            return ['success' => false, 'error' => 'Plugin not found'];
        }

        if (($plugin['status'] ?? '') !== 'trashed') {
            return ['success' => false, 'error' => 'Plugin is not in the trash'];
        }

        $configApp = $this->container->get('config.app');
        $paths = is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths']) ? $configApp['paths'] : [];
        $storagePath = is_string($paths['storage'] ?? null) ? $paths['storage'] : '';
        $modulesPath = is_string($paths['modules'] ?? null) ? $paths['modules'] : '';

        $type = is_string($plugin['type'] ?? null) ? $plugin['type'] : 'addon';
        $typeDir = match ($type) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };

        $slugStr = is_string($plugin['slug'] ?? null) ? $plugin['slug'] : '';
        $srcDir = $storagePath . '/trash/plugins/' . $typeDir . '/' . $slugStr;
        $destDir = $modulesPath . '/' . $typeDir . '/' . $slugStr;

        if (!is_dir($srcDir)) {
            return ['success' => false, 'error' => 'Plugin files not found in trash path'];
        }

        // Restore files to the active modules folder
        $parentDir = dirname($destDir);
        if (!is_dir($parentDir)) {
            @mkdir($parentDir, 0755, true);
        }

        if (is_dir($destDir)) {
            return ['success' => false, 'error' => 'Plugin directory already exists in live modules. Please delete it first.'];
        }

        // Use rename or copy fallback
        if (!@rename($srcDir, $destDir)) {
            $this->copyDir($srcDir, $destDir);
            $this->removeDir($srcDir);
        }

        // Update database status to inactive
        $pluginId = is_numeric($plugin['id'] ?? null) ? (int) $plugin['id'] : 0;
        $this->repo->update($pluginId, ['status' => 'inactive']);

        $this->events->doAction('plugin.restored', $slug);

        return ['success' => true];
    }

    /**
     * Recursively removes a directory and its nested contents.
     *
     * @param string $dir Absolute directory path.
     * @return bool True if successfully deleted.
     */
    private function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                if ($item instanceof \SplFileInfo) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
            }
            return @rmdir($dir);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Recursively copies a directory to a target location.
     *
     * @param string $src Source directory path.
     * @param string $dst Destination directory path.
     * @return void
     */
    private function copyDir(string $src, string $dst): void
    {
        @mkdir($dst, 0755, true);
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($items as $item) {
                if ($item instanceof \SplFileInfo) {
                    $target = $dst . '/' . $items->getSubPathname();
                    $item->isDir() ? @mkdir($target, 0755) : @copy($item->getPathname(), $target);
                }
            }
        } catch (\Throwable $e) {
            // Ignore copying errors, fallback gracefully
        }
    }


    /**
     * Registers or updates a gateway plugin in the `op_gateways` table.
     *
     * Maps the plugin metadata to the gateway fields required for checkout configurations.
     *
     * @param string               $slug   Unique plugin identifier.
     * @param array<string, mixed> $plugin DB plugin representation.
     * @return void
     */
    private function registerGatewayDefinition(string $slug, array $plugin): void
    {
        $gwRepo = $this->container->get(GatewayRepository::class);
        if (!$gwRepo instanceof GatewayRepository) {
            return;
        }
        $existing = $gwRepo->findBySlug($slug);

        $loader = $this->container->get(PluginLoader::class);
        $manifest = null;
        if ($loader instanceof PluginLoader) {
            $manifest = $loader->discover()[$slug] ?? null;
        }

        $logoPath = $this->resolveIconPath($slug, $plugin, $manifest);

        if ($existing !== null) {
            $gwId = is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;
            $gwRepo->update($gwId, [
                'status'    => 'active',
                'logo_path' => $logoPath,
            ]);
        } else {
            $manifestName = $manifest ? $manifest->name : null;
            $pluginName = is_string($plugin['name'] ?? null) ? $plugin['name'] : '';
            $gwRepo->create([
                'slug'       => $slug,
                'name'       => $manifestName ?? ($pluginName !== '' ? $pluginName : $slug),
                'type'       => 'api',
                'logo_path'  => $logoPath,
                'is_builtin' => 0,
                'sort_order'  => 0,
                'status'     => 'active',
            ]);
        }
    }

    /**
     * Resolves the gateway icon path and deploys it to the public directory.
     *
     * Copies the asset from the plugin folder to public/assets/img/gateways/ to allow web browsers to load it.
     *
     * @param string                       $slug     Unique plugin identifier.
     * @param array<string, mixed>         $plugin   DB plugin representation.
     * @param \OwnPay\Plugin\PluginManifest|null $manifest The plugin manifest schema wrapper.
     * @return string|null Path to the public-accessible asset, or null if no icon exists.
     */
    public function resolveIconPath(string $slug, array $plugin, ?PluginManifest $manifest): ?string
    {
        $iconFile = $manifest ? $manifest->icon : '';
        if ($iconFile === '') {
            return null;
        }

        $configApp = $this->container->get('config.app');
        $paths = is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths']) ? $configApp['paths'] : [];
        $modulesPath = is_string($paths['modules'] ?? null) ? $paths['modules'] : '';
        $publicPath = is_string($paths['public'] ?? null) ? $paths['public'] : '';

        $type = is_string($plugin['type'] ?? null) ? $plugin['type'] : 'gateway';
        $typeDir = match ($type) {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            default   => 'addons',
        };
        $srcPath = $modulesPath . '/' . $typeDir . '/' . $slug . '/' . $iconFile;
        if (!file_exists($srcPath)) {
            return null;
        }

        $ext = strtolower(pathinfo($iconFile, PATHINFO_EXTENSION));
        $allowedExt = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico'];
        if (!in_array($ext, $allowedExt, true)) {
            return null;
        }

        $destDir = $publicPath . '/assets/img/gateways';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $destFile = $slug . '.' . $ext;
        copy($srcPath, $destDir . '/' . $destFile);

        return '/assets/img/gateways/' . $destFile;
    }
}
