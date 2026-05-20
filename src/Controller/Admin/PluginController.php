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
 * Plugin admin controller — list, install, activate, deactivate, uninstall, settings.
 */
final class PluginController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private PluginManager $manager;
    private PluginRepository $repo;
    private PluginRegistry $registry;
    private EventManager $events;

    public function __construct(Container $c, AdminSession $session,
        PluginManager $manager,
        PluginRepository $repo,
        PluginRegistry $registry,
        EventManager $events
    ) {
        $this->c = $c;
        $this->session = $session;
        $this->manager = $manager;
        $this->repo = $repo;
        $this->registry = $registry;
        $this->events = $events;
    }

    /** @phpstan-ignore-next-line */
    public function index(Request $request): Response
    {
        $plugins = $this->repo->paginate(1, 200)['items'];

        // Enrich DB rows with manifest name (DB may have slug as name)
        foreach ($plugins as &$p) {
            $m = json_decode($p['manifest'] ?? '{}', true);
            if (!empty($m['name'])) {
                $p['name'] = $m['name'];
            }
            $p['description'] = $p['description'] ?? ($m['description'] ?? '');
        }
        unset($p);

        // Discover filesystem plugins
        /** @var \OwnPay\Plugin\PluginLoader $loader */
        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        $discovered = $loader->discover();
        foreach ($discovered as $manifest) {
            if (in_array($manifest->type, ['addon', 'gateway', 'plugin'])) {
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
                        'logo_path'   => null,
                    ];
                }
            }
        }

        return $this->renderAdminPage('admin/plugins/index.twig', [
            'plugins'     => $plugins,
            'active_page' => 'plugins',
        ]);
    }

    public function installForm(Request $request): Response
    {
        $maxUpload = min(
            $this->parseSize(ini_get('upload_max_filesize') ?: '2M'),
            $this->parseSize(ini_get('post_max_size') ?: '8M')
        );

        return $this->renderAdminPage('admin/plugins/install.twig', [
            'max_upload_size' => $this->formatSize($maxUpload),
            'active_page'     => 'plugins',
        ]);
    }

    public function upload(Request $request): Response
    {
        $file = $request->file('plugin_zip');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return $this->redirectBack($request, 'No file uploaded or upload failed');
        }

        if (!str_ends_with(strtolower($file['name']), '.zip')) {
            return $this->redirectBack($request, 'Only .zip files are allowed');
        }

        $result = $this->manager->install($file['tmp_name']);

        if (!$result['success']) {
            return $this->redirectBack($request, $result['error'] ?? 'Installation failed');
        }

        $this->session->flashSuccess("Plugin '{$result['slug']}' installed successfully!");
        return Response::redirect('/admin/plugins');
    }

    public function activate(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $result = $this->manager->activate($slug);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Activation failed');
        } else {
            $this->session->flashSuccess("Plugin '{$slug}' activated! ({$result['migrations_run']} migrations run)");
        }

        return Response::redirect($this->redirectTarget($request));
    }

    public function deactivate(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $result = $this->manager->deactivate($slug);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Deactivation failed');
        } else {
            $this->session->flashSuccess("Plugin '{$slug}' deactivated.");
        }

        return Response::redirect($this->redirectTarget($request));
    }

    public function uninstall(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $result = $this->manager->uninstall($slug);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Uninstall failed');
        } else {
            $this->session->flashSuccess("Plugin '{$slug}' uninstalled.");
        }

        return Response::redirect($this->redirectTarget($request));
    }

    public function settings(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            return Response::redirect('/admin/plugins');
        }

        $manifestJson = json_decode($plugin['manifest'] ?? '{}', true);
        $plugin['author'] = $manifestJson['author'] ?? 'Unknown';
        $plugin['description'] = $manifestJson['description'] ?? '';

        $instance = $this->registry->get($slug);

        // Fallback: plugin active in DB but not booted in current request
        // → instantiate from filesystem so we can call fields()
        if ($instance === null) {
            $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
            $manifests = $loader->discover();
            $manifest = $manifests[$slug] ?? null;
            if ($manifest !== null) {
                $entrypointFile = $manifest->path . '/' . $manifest->entrypoint;
                if (file_exists($entrypointFile)) {
                    require_once $entrypointFile;
                    $rawManifest = json_decode((string) file_get_contents($manifest->path . '/manifest.json'), true);
                    if (!empty($rawManifest['namespace'])) {
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

        $settingsHtml = '';
        if ($instance !== null) {
            $settingsRepo = $this->c->get(\OwnPay\Repository\SettingsRepository::class);

            // AUD-G5: Use brand-scoped settings — each brand can have different plugin config
            $brandId = null;
            if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
                $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
                $brandId = $brandCtx->getActiveBrandId();
            }

            if ($brandId !== null && $brandId > 0) {
                $currentValues = $settingsRepo->getGroupScoped("plugin.{$slug}", $brandId);
            } else {
                $currentValues = $settingsRepo->getGroup("plugin.{$slug}");
            }

            $action = "/admin/plugins/{$slug}/settings";
            $settingsHtml = SettingsRenderer::render($instance, $currentValues, $action);
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

    public function saveSettings(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $settings = $request->post('settings') ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }

        /** @var \OwnPay\Repository\SettingsRepository $settingsRepo */
        $settingsRepo = $this->c->get(\OwnPay\Repository\SettingsRepository::class);

        // AUD-G5: Save brand-scoped plugin settings
        $brandId = null;
        if ($this->c->has(\OwnPay\Service\Brand\BrandContext::class)) {
            $brandCtx = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
            $brandId = $brandCtx->getActiveBrandId();
        }

        if ($brandId !== null && $brandId > 0) {
            $settingsRepo->bulkSetScoped("plugin.{$slug}", $settings, $brandId);
        } else {
            $settingsRepo->bulkSet("plugin.{$slug}", $settings);
        }

        $this->events->doAction('plugin.settings.saved', $slug, $settings, $brandId);

        $this->session->flashSuccess('Settings saved.');
        return Response::redirect("/admin/plugins/{$slug}/settings");
    }

    private function redirectBack(Request $request, string $error): Response
    {
        $this->session->flashError($error);
        $referer = $request->header('Referer') ?? '';
        // Prevent open redirect: only use Referer if it's a relative path
        $path = parse_url($referer, PHP_URL_PATH) ?: '/admin/plugins/install';
        if (!str_starts_with($path, '/admin/')) {
            $path = '/admin/plugins/install';
        }
        return Response::redirect($path);
    }

    /**
     * Smart redirect: return to the calling page (gateways, addons, themes, or plugins).
     */
    private function redirectTarget(Request $request): string
    {
        /** @phpstan-ignore nullCoalesce.expr */
        $referer = $request->header('Referer') ?? '';
        foreach (['/admin/gateways', '/admin/addons', '/admin/themes'] as $path) {
            if (str_contains($referer, $path)) {
                return $path;
            }
        }
        return '/admin/plugins';
    }

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

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
