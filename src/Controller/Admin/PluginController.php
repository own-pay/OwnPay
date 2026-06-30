<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Plugin\PluginManager;
use OwnPay\Plugin\PluginRegistry;
use OwnPay\Repository\PluginRepository;
use OwnPay\View\SettingsRenderer;

/**
 * Class PluginController
 *
 * Administrative portal controller managing plugins (addons, gateways, and themes),
 * providing interfaces for discovery, upload/installation, activation/deactivation,
 * uninstallation, and brand-scoped custom configurations.
 *
 * @package OwnPay\Controller\Admin
 */
final class PluginController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * @var PluginManager The core plugin manager.
     */
    private PluginManager $manager;

    /**
     * @var PluginRepository The database repository for installed plugins.
     */
    private PluginRepository $repo;

    /**
     * @var PluginRegistry The runtime registry holding active plugin instances.
     */
    private PluginRegistry $registry;

    /**
     * @var EventManager The hooks and actions event manager.
     */
    private EventManager $events;

    /**
     * PluginController constructor.
     *
     * @param Container        $c        The dependency injection container.
     * @param AdminSession     $session  The administrative session service.
     * @param PluginManager    $manager  The core plugin manager.
     * @param PluginRepository $repo     The database repository for installed plugins.
     * @param PluginRegistry   $registry The runtime registry holding active plugin instances.
     * @param EventManager     $events   The hooks and actions event manager.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        PluginManager $manager,
        PluginRepository $repo,
        PluginRegistry $registry,
        EventManager $events
    ) {
        $this->c        = $c;
        $this->session  = $session;
        $this->manager  = $manager;
        $this->repo     = $repo;
        $this->registry = $registry;
        $this->events   = $events;
    }

    /**
     * Renders a list of all plugins, combining database records with discovered filesystem plugins.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The plugin dashboard overview page.
     *
     * @phpstan-ignore-next-line
     */
    public function index(Request $request): Response
    {
        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }

        $plugins = $this->repo->paginate(1, 200)['items'];

        // Discover filesystem plugins
        /** @var \OwnPay\Plugin\PluginLoader $loader */
        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        $discovered = $loader->discover();

        // Enrich DB rows with manifest name (DB may have slug as name)
        foreach ($plugins as &$p) {
            $slugVal = $p['slug'] ?? '';
            $slug = is_string($slugVal) ? $slugVal : '';

            $manifestVal = $p['manifest'] ?? '{}';
            $m = json_decode(is_string($manifestVal) ? $manifestVal : '{}', true);
            $m = is_array($m) ? $m : [];
            if (!empty($m['name'])) {
                $p['name'] = $m['name'];
            }
            $p['description'] = $p['description'] ?? ($m['description'] ?? '');

            // Second-pass: Align with discovered filesystem manifest attributes (prefer FS names)
            if ($slug !== '' && isset($discovered[$slug])) {
                $fsManifest = $discovered[$slug];
                $p['name']        = $fsManifest->name;
                $p['description'] = $p['description'] ?: ($fsManifest->description ?? '');
                $p['author']      = $p['author'] ?? ($fsManifest->author ?? 'Unknown');
                $p['version']     = $fsManifest->version;
                
                $p['logo_path'] = $this->manager->resolveIconPath($slug, $p, $fsManifest);
            } else {
                $p['logo_path'] = null;
            }

            // Local active/inactive status override if brand context is active
            if ($brandId !== null && $brandId > 0 && !in_array($p['status'], ['uninstalled', 'trashed'], true)) {
                if ($slug !== '') {
                    $p['status'] = $this->repo->isPluginActiveForBrand($slug, $brandId) ? 'active' : 'inactive';
                }
            }
        }
        unset($p);

        foreach ($discovered as $manifest) {
            if (in_array($manifest->type, ['addon', 'gateway', 'plugin', 'theme'], true)) {
                $found = false;
                foreach ($plugins as $p) {
                    if ($p['slug'] === $manifest->slug) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $plugins[] = [
                        'slug'        => $manifest->slug,
                        'name'        => $manifest->name,
                        'description' => $manifest->description,
                        'version'     => $manifest->version,
                        'status'      => 'uninstalled',
                        'author'      => $manifest->author,
                        'type'        => $manifest->type,
                        'logo_path'   => $this->manager->resolveIconPath($manifest->slug, ['type' => $manifest->type], $manifest),
                    ];
                }
            }
        }

        return $this->renderAdminPage('admin/plugins/index.twig', [
            'plugins'        => $plugins,
            'active_page'    => 'plugins',
            'is_global_view' => $this->isGlobalBrandView(),
        ]);
    }

    /**
     * Renders the ZIP installation upload form page.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The plugin upload form page.
     */
    public function installForm(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'upload a plugin')) {
            return $guard;
        }

        $maxUpload = min(
            $this->parseSize(ini_get('upload_max_filesize') ?: '2M'),
            $this->parseSize(ini_get('post_max_size') ?: '8M')
        );

        return $this->renderAdminPage('admin/plugins/install.twig', [
            'max_upload_size' => $this->formatSize($maxUpload),
            'active_page'     => 'plugins',
        ]);
    }

    /**
     * Handles processing uploaded plugin ZIP files, installing them to the modules folder.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The redirect response back to index or error.
     */
    public function upload(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'upload a plugin')) {
            return $guard;
        }

        // 1. Check if this is a confirmed update request
        $confirmUpdatePost = $request->post('confirm_update');
        $confirmUpdate = is_scalar($confirmUpdatePost) ? (int) $confirmUpdatePost : 0;
        $tempZipPost = $request->post('temp_zip');
        $tempZip = is_string($tempZipPost) ? $tempZipPost : '';

        if ($confirmUpdate === 1 && $tempZip !== '') {
            $configApp = $this->c->get('config.app');
            $paths = is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths']) ? $configApp['paths'] : [];
            $storagePath = is_string($paths['storage'] ?? null) ? $paths['storage'] : '';
            $tempUploadsDir = realpath($storagePath . '/temp_uploads');
            $realTempZip = realpath($tempZip);

            if ($tempUploadsDir === false || $realTempZip === false || !str_starts_with($realTempZip, $tempUploadsDir)) {
                return $this->redirectBack($request, 'Invalid temporary ZIP path');
            }

            $result = $this->manager->update($realTempZip);
            @unlink($realTempZip);

            if (!$result['success']) {
                return $this->redirectBack($request, $result['error'] ?? 'Update failed');
            }

            $slug = $result['slug'] ?? 'unknown';
            $this->session->flashSuccess("Plugin '{$slug}' updated successfully!");
            return Response::redirect('/admin/plugins');
        }

        // 2. Standard upload flow
        $file = $request->file('plugin_zip');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return $this->redirectBack($request, 'No file uploaded or upload failed');
        }

        $fileName = $file['name'] ?? '';
        if (!is_string($fileName) || !str_ends_with(strtolower($fileName), '.zip')) {
            return $this->redirectBack($request, 'Only .zip files are allowed');
        }

        $tmpName = $file['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '') {
            return $this->redirectBack($request, 'Upload failed');
        }
        $result = $this->manager->install($tmpName);

        if (!$result['success']) {
            if (isset($result['code']) && $result['code'] === 'already_installed') {
                $configApp = $this->c->get('config.app');
                $paths = is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths']) ? $configApp['paths'] : [];
                $storagePath = is_string($paths['storage'] ?? null) ? $paths['storage'] : '';
                $tempUploadsDir = $storagePath . '/temp_uploads';
                if (!is_dir($tempUploadsDir)) {
                    @mkdir($tempUploadsDir, 0755, true);
                }
                $slug = is_string($result['slug'] ?? null) ? $result['slug'] : 'unknown';
                $tempZipName = $slug . '_' . bin2hex(random_bytes(8)) . '.zip';
                $persistentTempZip = $tempUploadsDir . '/' . $tempZipName;
                copy($tmpName, $persistentTempZip);

                $newVersion = is_string($result['new_version'] ?? null) ? $result['new_version'] : '0.0.0';
                $existingVersion = is_string($result['existing_version'] ?? null) ? $result['existing_version'] : '0.0.0';
                $compare = version_compare($newVersion, $existingVersion);
                $versionRelation = 'new';
                if ($compare === 0) {
                    $versionRelation = 'same';
                } elseif ($compare < 0) {
                    $versionRelation = 'older';
                }

                return $this->renderAdminPage('admin/plugins/confirm_update.twig', [
                    'slug'             => $slug,
                    'existing_version' => $existingVersion,
                    'new_version'      => $newVersion,
                    'version_relation' => $versionRelation,
                    'has_migrations'   => !empty($result['has_migrations']),
                    'temp_zip'         => $persistentTempZip,
                    'active_page'      => 'plugins',
                ]);
            }
            return $this->redirectBack($request, $result['error'] ?? 'Installation failed');
        }

        $slug = $result['slug'] ?? 'unknown';
        $this->session->flashSuccess("Plugin '{$slug}' installed successfully!");
        return Response::redirect('/admin/plugins');
    }

    /**
     * Cancels the update flow and deletes the temporary ZIP file.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response The redirect response.
     */
    public function cancelUpload(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'upload a plugin')) {
            return $guard;
        }

        $tempZipPost = $request->post('temp_zip');
        $tempZip = is_string($tempZipPost) ? $tempZipPost : '';
        if ($tempZip !== '') {
            $configApp = $this->c->get('config.app');
            $paths = is_array($configApp) && isset($configApp['paths']) && is_array($configApp['paths']) ? $configApp['paths'] : [];
            $storagePath = is_string($paths['storage'] ?? null) ? $paths['storage'] : '';
            $tempUploadsDir = realpath($storagePath . '/temp_uploads');
            $realTempZip = realpath($tempZip);

            if ($tempUploadsDir !== false && $realTempZip !== false && str_starts_with($realTempZip, $tempUploadsDir)) {
                @unlink($realTempZip);
            }
        }
        return Response::redirect('/admin/plugins');
    }

    /**
     * Activates an installed plugin and executes its migrations.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function activate(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }
        $result = $this->manager->activate($slug, $brandId);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Activation failed');
        } else {
            $msg = ($brandId !== null && $brandId > 0)
                ? "Plugin '{$slug}' activated for this brand!"
                : "Plugin '{$slug}' activated! (" . ($result['migrations_run'] ?? 0) . " migrations run)";
            $this->session->flashSuccess($msg);
        }

        return Response::redirect($this->redirectTarget($request));
    }

    /**
     * Deactivates an active plugin without purging its data records.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function deactivate(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }
        $result = $this->manager->deactivate($slug, $brandId);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Deactivation failed');
        } else {
            $msg = ($brandId !== null && $brandId > 0)
                ? "Plugin '{$slug}' deactivated for this brand."
                : "Plugin '{$slug}' deactivated.";
            $this->session->flashSuccess($msg);
        }

        return Response::redirect($this->redirectTarget($request));
    }

    /**
     * Deactivates and completely uninstalls a plugin, purging its files and database traces.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function uninstall(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'uninstall a plugin')) {
            return $guard;
        }

        $slug = (string) $request->param('slug');
        try {
            $result = $this->manager->uninstall($slug);

            if (!$result['success']) {
                $this->session->flashError($result['error'] ?? 'Uninstall failed');
            } else {
                $this->session->flashSuccess("Plugin '{$slug}' uninstalled.");
            }
        } catch (\OwnPay\Plugin\Exception\PluginInUseException $e) {
            $this->session->flashError($e->getMessage());
        }

        return Response::redirect($this->redirectTarget($request));
    }

    /**
     * Moves an inactive plugin to the trash folder.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function trash(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'manage plugin files')) {
            return $guard;
        }

        $slug = (string) $request->param('slug');
        $result = $this->manager->trash($slug);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Failed to move plugin to trash');
        } else {
            $this->session->flashSuccess("Plugin '{$slug}' moved to trash.");
        }

        return Response::redirect($this->redirectTarget($request));
    }

    /**
     * Restores a trashed plugin back to the modules directory.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function restore(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/plugins', 'manage plugin files')) {
            return $guard;
        }

        $slug = (string) $request->param('slug');
        $result = $this->manager->restore($slug);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Failed to restore plugin');
        } else {
            $this->session->flashSuccess("Plugin '{$slug}' restored successfully.");
        }

        return Response::redirect($this->redirectTarget($request));
    }

    /**
     * Renders settings fields form provided by the plugin, supporting brand-level configuration scoping.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The plugin configuration page response.
     */
    public function settings(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            return Response::redirect('/admin/plugins');
        }

        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }

        if ($brandId !== null && $brandId > 0) {
            if (!$this->repo->isPluginActiveForBrand($slug, $brandId)) {
                $this->session->flashError('This plugin is not active for the current brand.');
                return Response::redirect('/admin/plugins');
            }
        }

        $manifestVal = $plugin['manifest'] ?? '{}';
        $manifestJson = json_decode(is_string($manifestVal) ? $manifestVal : '{}', true);
        $manifestJson = is_array($manifestJson) ? $manifestJson : [];
        $plugin['author'] = $manifestJson['author'] ?? 'Unknown';
        $plugin['description'] = $manifestJson['description'] ?? '';

        $instance = $this->registry->get($slug);

        // Fallback: plugin active in DB but not booted in current request
        // → instantiate from filesystem so we can call fields()
        if ($instance === null) {
            $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
            if ($loader instanceof \OwnPay\Plugin\PluginLoader) {
                $manifests = $loader->discover();
                $manifest = $manifests[$slug] ?? null;
                if ($manifest !== null) {
                    $entrypointFile = $manifest->path . '/' . $manifest->entrypoint;
                    if (file_exists($entrypointFile)) {
                        require_once $entrypointFile;
                        $rawManifestJson = file_get_contents($manifest->path . '/manifest.json');
                        $rawManifest = json_decode(is_string($rawManifestJson) ? $rawManifestJson : '{}', true);
                        $rawManifest = is_array($rawManifest) ? $rawManifest : [];
                        if (!empty($rawManifest['namespace']) && is_string($rawManifest['namespace'])) {
                            $className = rtrim($rawManifest['namespace'], '\\') . '\\' . pathinfo($manifest->entrypoint, PATHINFO_FILENAME);
                        } else {
                            $pascal = str_replace('-', '', ucwords($manifest->slug, '-'));
                            $className = "OwnPay\\Plugins\\{$pascal}\\" . pathinfo($manifest->entrypoint, PATHINFO_FILENAME);
                        }
                        if (class_exists($className) && is_subclass_of($className, \OwnPay\Plugin\PluginInterface::class)) {
                            $instance = new $className();
                        }
                    }
                }
            }
        }

        $settingsHtml = '';
        if ($instance !== null) {
            $settingsRepo = $this->c->get(\OwnPay\Repository\SettingsRepository::class);



            if ($settingsRepo instanceof \OwnPay\Repository\SettingsRepository) {
                if ($brandId !== null && $brandId > 0) {
                    $currentValues = $settingsRepo->getGroupScoped("plugin.{$slug}", $brandId);
                } else {
                    $currentValues = $settingsRepo->getGroup("plugin.{$slug}");
                }
                $action = "/admin/plugins/{$slug}/settings";
                $settingsHtml = SettingsRenderer::render($instance, $currentValues, $action);
            }
        }

        $activePage = match ($plugin['type'] ?? 'plugin') {
            'gateway' => 'gateways',
            'theme'   => 'themes',
            'addon'   => 'addons',
            default   => 'plugins',
        };

        return $this->renderAdminPage('admin/plugins/settings.twig', [
            'plugin'        => $plugin,
            'settings_html' => $settingsHtml,
            'active_page'   => $activePage,
        ]);
    }

    /**
     * Saves configuration parameters for the plugin, scoped optionally by brand ID context.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
    public function saveSettings(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $settings = $request->post('settings') ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }

        /** @var \OwnPay\Repository\SettingsRepository $settingsRepo */
        $settingsRepo = $this->c->get(\OwnPay\Repository\SettingsRepository::class);

        // Save brand-scoped plugin settings
        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            if ($brandCtx instanceof \OwnPay\Service\Brand\BrandContext) {
                $brandCtx->resolveFromRequest($request);
                $brandId = $brandCtx->getActiveBrandId();
            }
        }

        if ($brandId !== null && $brandId > 0) {
            $settingsRepo->bulkSetScoped("plugin.{$slug}", $settings, $brandId);

            // Synchronize with op_gateway_configs if this plugin is a gateway
            $plugin = $this->repo->findBySlug($slug);
            if ($plugin !== null && ($plugin['type'] ?? '') === 'gateway') {
                $gwRepo = $this->c->get(\OwnPay\Repository\GatewayRepository::class);
                if ($gwRepo instanceof \OwnPay\Repository\GatewayRepository) {
                    $gw = $gwRepo->findBySlug($slug);
                    if ($gw !== null) {
                        $gwId = is_numeric($gw['id'] ?? null) ? (int) $gw['id'] : 0;
                        if ($gwId > 0) {
                            $gwConfigRepo = $this->c->get(\OwnPay\Repository\GatewayConfigRepository::class);
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
        } else {
            $settingsRepo->bulkSet("plugin.{$slug}", $settings);
        }

        $this->events->doAction('plugin.settings.saved', $slug, $settings, $brandId);

        $this->session->flashSuccess('Settings saved.');
        return Response::redirect("/admin/plugins/{$slug}/settings");
    }

    /**
     * Safe internal helper redirecting users back to their previous page with error context.
     *
     * @param Request $request The incoming HTTP request.
     * @param string  $error   The flash error message to register in the session.
     *
     * @return Response The HTTP redirect response.
     */
    private function redirectBack(Request $request, string $error): Response
    {
        $this->session->flashError($error);
        $referer = $request->header('Referer');
        // Prevent open redirect: only use Referer if it's a relative path
        $path = parse_url($referer, PHP_URL_PATH) ?: '/admin/plugins/install';
        if (!str_starts_with($path, '/admin/')) {
            $path = '/admin/plugins/install';
        }
        return Response::redirect($path);
    }

    /**
     * Smart redirect helper determining the original category page (gateways, addons, etc.).
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return string Redirect landing page target string.
     */
    private function redirectTarget(Request $request): string
    {
        $referer = $request->header('Referer');
        foreach (['/admin/gateways', '/admin/themes'] as $path) {
            if (str_contains($referer, $path)) {
                return $path;
            }
        }
        return '/admin/plugins';
    }

    /**
     * Parses byte size representations (e.g. '8M') to integer bytes values.
     *
     * @param string $size Format size descriptor string.
     *
     * @return int Resolved bytes representation.
     */
    private function parseSize(string $size): int
    {
        $unit = strtolower(substr($size, -1));
        $value = (int) $size;
        return match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Format size integer bytes values to readable representations (e.g. '8 MB').
     *
     * @param int $bytes Integer bytes count.
     *
     * @return string Formatted representation.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
