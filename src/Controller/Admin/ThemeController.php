<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Plugin\PluginLoader;
use OwnPay\Plugin\PluginManager;
use OwnPay\Repository\PluginRepository;
use OwnPay\Repository\SettingsRepository;

/**
 * Theme admin controller for listing, activating, uploading, and uninstalling custom themes.
 */
final class ThemeController
{
    use AdminPageTrait;

    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * The admin session manager.
     */
    private AdminSession $session;

    /**
     * The plugin manager instance.
     */
    private PluginManager $manager;

    /**
     * The plugin repository.
     */
    private PluginRepository $repo;

    /**
     * The settings repository.
     */
    private SettingsRepository $settings;

    /**
     * ThemeController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param AdminSession $session The admin session manager.
     * @param PluginManager $manager The plugin manager instance.
     * @param PluginRepository $repo The plugin repository.
     * @param SettingsRepository $settings The settings repository.
     */
    public function __construct(Container $c, AdminSession $session,
        PluginManager $manager,
        PluginRepository $repo,
        SettingsRepository $settings
    ) {
        $this->c        = $c;
        $this->session  = $session;
        $this->manager  = $manager;
        $this->repo     = $repo;
        $this->settings = $settings;
    }

    /**
     * Display a list of all themes (both stored in database and on filesystem).
     *
     * @param Request $request The incoming HTTP request.
     * @return Response The HTTP response with the themes index page.
     * @throws \Exception If lookup or template rendering fails.
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

        $themes      = $this->repo->listByType('theme');
        $activeTheme = $this->settings->get('appearance', 'active_theme', 'default');

        // Enrich DB rows with manifest name (DB may store slug as name)
        foreach ($themes as &$t) {
            $manifestStr = is_string($t['manifest'] ?? null) ? $t['manifest'] : '{}';
            $m = json_decode($manifestStr, true) ?: [];
            $m = is_array($m) ? $m : [];
            if (!empty($m['name'])) {
                $t['name'] = $m['name'];
            }
            $t['description'] = $t['description'] ?? $m['description'] ?? '';
            $t['author']      = $t['author']      ?? $m['author']      ?? 'Unknown';

            // Local active/inactive status override if brand context is active
            if ($brandId !== null && $brandId > 0 && !in_array($t['status'], ['uninstalled', 'trashed'], true)) {
                $slug = is_string($t['slug'] ?? null) ? $t['slug'] : '';
                $t['status'] = $this->repo->isPluginActiveForBrand($slug, $brandId) ? 'active' : 'inactive';
            }
        }
        unset($t);

        // Also discover filesystem themes not yet in DB
        /** @var PluginLoader $loader */
        $loader     = $this->c->get(PluginLoader::class);
        $discovered = $loader->discover();

        // Build slug→manifest lookup from filesystem
        $manifestLookup = [];
        foreach ($discovered as $manifest) {
            if ($manifest->type === 'theme') {
                $manifestLookup[$manifest->slug] = $manifest;
            }
        }

        // Enrich DB-stored themes with filesystem manifest name
        foreach ($themes as &$t) {
            $tSlug = is_string($t['slug'] ?? null) ? $t['slug'] : '';
            if (isset($manifestLookup[$tSlug])) {
                $fsManifest = $manifestLookup[$tSlug];
                $t['name']        = $fsManifest->name;
                $t['description'] = $t['description'] ?: ($fsManifest->description ?? '');
                $t['author']      = $t['author']      ?: ($fsManifest->author      ?? 'Unknown');
            }
        }
        unset($t);

        // Add undiscovered themes (on filesystem but not in DB)
        foreach ($manifestLookup as $slug => $manifest) {
            $found = false;
            foreach ($themes as $t) {
                if ($t['slug'] === $slug) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $themes[] = [
                    'slug'        => $manifest->slug,
                    'name'        => $manifest->name,
                    'description' => $manifest->description,
                    'version'     => $manifest->version,
                    'status'      => 'uninstalled',
                    'author'      => $manifest->author,
                    'screenshot'  => null,
                ];
            }
        }

        return $this->renderAdminPage('admin/themes/index.twig', [
            'themes'         => $themes,
            'active_theme'   => $activeTheme,
            'active_page'    => 'themes',
            'is_global_view' => $this->isGlobalBrandView(),
        ]);
    }

    /**
     * Show theme upload / installation form.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response The HTTP response with the installation page.
     * @throws \Exception If layout template rendering fails.
     */
    public function installForm(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/themes', 'upload a theme')) {
            return $guard;
        }

        $maxUpload = min(
            $this->parseSize(ini_get('upload_max_filesize') ?: '2M'),
            $this->parseSize(ini_get('post_max_size') ?: '8M')
        );

        return $this->renderAdminPage('admin/themes/install.twig', [
            'max_upload_size' => $this->formatSize($maxUpload),
            'active_page'     => 'themes',
        ]);
    }

    /**
     * Activate a specific theme by slug.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If theme activation fails.
     */
    public function activate(Request $request): Response
    {
        $slug   = (string) $request->param('slug');
        $plugin = $this->repo->findBySlug($slug);

        if ($plugin === null) {
            // Theme not in DB - try to register from filesystem
            /** @var PluginLoader $loader */
            $loader     = $this->c->get(PluginLoader::class);
            $discovered = $loader->discover();
            $found      = false;
            foreach ($discovered as $m) {
                if ($m->slug === $slug && $m->type === 'theme') {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->session->flashError('Theme not found');
                return Response::redirect('/admin/themes');
            }
            $result = $this->manager->activate($slug);
            if (!$result['success']) {
                $this->session->flashError($result['error'] ?? 'Failed to activate theme');
                return Response::redirect('/admin/themes');
            }
        }

        if ($plugin === null) {
            $this->session->flashError('Failed to activate theme');
            return Response::redirect('/admin/themes');
        }

        if ($plugin['status'] !== 'active') {
            $this->manager->activate($slug);
        }

        $this->settings->set('appearance', 'active_theme', $slug);
        $pluginName = is_string($plugin['name'] ?? null) ? $plugin['name'] : 'Unknown';
        $this->session->flashSuccess("Theme '{$pluginName}' activated!");
        return Response::redirect('/admin/themes');
    }

    /**
     * Process uploading and installing a theme .zip archive.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response The HTTP redirect response.
     */
    public function upload(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/themes', 'upload a theme')) {
            return $guard;
        }

        $file = $request->file('theme_zip');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $this->session->flashError('No file uploaded or upload failed');
            return Response::redirect('/admin/themes');
        }

        $fileName = is_string($file['name'] ?? null) ? $file['name'] : '';
        if (!str_ends_with(strtolower($fileName), '.zip')) {
            $this->session->flashError('Only .zip files are allowed');
            return Response::redirect('/admin/themes');
        }

        $tmpName = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $result = $this->manager->install($tmpName);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Installation failed');
            return Response::redirect('/admin/themes');
        }

        $slug = $result['slug'] ?? 'unknown';
        $this->session->flashSuccess("Theme '{$slug}' installed successfully!");
        return Response::redirect('/admin/themes');
    }

    /**
     * Uninstall a theme by slug.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If uninstallation checks fail.
     */
    public function uninstall(Request $request): Response
    {
        if ($guard = $this->requireGlobalView('/admin/themes', 'uninstall a theme')) {
            return $guard;
        }

        $slug        = (string) $request->param('slug');
        $activeTheme = $this->settings->get('appearance', 'active_theme', 'default');

        if ($slug === $activeTheme) {
            $this->session->flashError('Cannot uninstall the active theme. Switch to another theme first.');
            return Response::redirect('/admin/themes');
        }

        try {
            $result = $this->manager->uninstall($slug);
            if ($result['success']) {
                $this->session->flashSuccess('Theme removed.');
            } else {
                $this->session->flashError($result['error'] ?? 'Failed to remove theme');
            }
        } catch (\OwnPay\Plugin\Exception\PluginInUseException $e) {
            $this->session->flashError($e->getMessage());
        }

        return Response::redirect('/admin/themes');
    }

    /**
     * Helper to parse size string into bytes.
     *
     * @param string $size The size string (e.g. '2M', '8M').
     * @return int The equivalent byte count.
     */
    private function parseSize(string $size): int
    {
        $unit  = strtolower(substr($size, -1));
        $value = (int) $size;
        return match ($unit) {
            'g'     => $value * 1073741824,
            'm'     => $value * 1048576,
            'k'     => $value * 1024,
            default => $value,
        };
    }

    /**
     * Helper to format a byte count as human readable string.
     *
     * @param int $bytes The number of bytes.
     * @return string Human-friendly size.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
