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
 * Theme admin controller — list, activate, customize, uninstall.
 */
final class ThemeController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private PluginManager $manager;
    private PluginRepository $repo;
    private SettingsRepository $settings;

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

    public function index(Request $request): Response
    {
        $themes      = $this->repo->listByType('theme');
        $activeTheme = $this->settings->get('appearance', 'active_theme', 'default');

        // Enrich DB rows with manifest name (DB may store slug as name)
        foreach ($themes as &$t) {
            $m = json_decode($t['manifest'] ?? '{}', true) ?: [];
            if (!empty($m['name'])) {
                $t['name'] = $m['name'];
            }
            $t['description'] = $t['description'] ?? $m['description'] ?? '';
            $t['author']      = $t['author']      ?? $m['author']      ?? 'Unknown';
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
            if (isset($manifestLookup[$t['slug']])) {
                $fsManifest = $manifestLookup[$t['slug']];
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
            'themes'       => $themes,
            'active_theme' => $activeTheme,
            'active_page'  => 'themes',
        ]);
    }

    public function installForm(Request $request): Response
    {
        $maxUpload = min(
            $this->parseSize(ini_get('upload_max_filesize') ?: '2M'),
            $this->parseSize(ini_get('post_max_size') ?: '8M')
        );

        return $this->renderAdminPage('admin/themes/install.twig', [
            'max_upload_size' => $this->formatSize($maxUpload),
            'active_page'     => 'themes',
        ]);
    }

    public function activate(Request $request): Response
    {
        $slug   = (string) $request->param('slug');
        $plugin = $this->repo->findBySlug($slug);

        if ($plugin === null) {
            // Theme not in DB — try to register from filesystem
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
            if (!($result['success'] ?? false)) {
                $this->session->flashError($result['error'] ?? 'Failed to activate theme');
                return Response::redirect('/admin/themes');
            }
            $plugin = $this->repo->findBySlug($slug);
        }

        if ($plugin['status'] !== 'active') {
            $this->manager->activate($slug);
        }

        $this->settings->set('appearance', 'active_theme', $slug);
        $this->session->flashSuccess("Theme '{$plugin['name']}' activated!");
        return Response::redirect('/admin/themes');
    }

    public function upload(Request $request): Response
    {
        $file = $request->file('theme_zip');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $this->session->flashError('No file uploaded or upload failed');
            return Response::redirect('/admin/themes');
        }

        if (!str_ends_with(strtolower($file['name']), '.zip')) {
            $this->session->flashError('Only .zip files are allowed');
            return Response::redirect('/admin/themes');
        }

        $result = $this->manager->install($file['tmp_name']);

        if (!$result['success']) {
            $this->session->flashError($result['error'] ?? 'Installation failed');
            return Response::redirect('/admin/themes');
        }

        $this->session->flashSuccess("Theme '{$result['slug']}' installed successfully!");
        return Response::redirect('/admin/themes');
    }

    public function uninstall(Request $request): Response
    {
        $slug        = (string) $request->param('slug');
        $activeTheme = $this->settings->get('appearance', 'active_theme', 'default');

        if ($slug === $activeTheme) {
            $this->session->flashError('Cannot uninstall the active theme. Switch to another theme first.');
            return Response::redirect('/admin/themes');
        }

        $result = $this->manager->uninstall($slug);
        if ($result['success']) {
            $this->session->flashSuccess('Theme removed.');
        } else {
            $this->session->flashError($result['error'] ?? 'Failed to remove theme');
        }

        return Response::redirect('/admin/themes');
    }

    public function customize(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        return Response::redirect("/admin/plugins/{$slug}/settings");
    }

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

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
