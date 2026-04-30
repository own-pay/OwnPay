<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
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
    private Container $container;
    private PluginManager $manager;
    private PluginRepository $repo;
    private PluginRegistry $registry;
    private EventManager $events;

    public function __construct(
        Container $container,
        PluginManager $manager,
        PluginRepository $repo,
        PluginRegistry $registry,
        EventManager $events
    ) {
        $this->container = $container;
        $this->manager = $manager;
        $this->repo = $repo;
        $this->registry = $registry;
        $this->events = $events;
    }

    public function index(Request $request): Response
    {
        $plugins = $this->repo->paginate(1, 200)['items'];

        return $this->render('admin/plugins/index.twig', [
            'plugins' => $plugins,
        ]);
    }

    public function installPage(Request $request): Response
    {
        $maxUpload = min(
            $this->parseSize(ini_get('upload_max_filesize') ?: '2M'),
            $this->parseSize(ini_get('post_max_size') ?: '8M')
        );

        return $this->render('admin/plugins/install.twig', [
            'max_upload_size' => $this->formatSize($maxUpload),
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

        $_SESSION['flash_success'] = "Plugin '{$result['slug']}' installed successfully!";
        return Response::redirect('/admin/plugins');
    }

    public function activate(Request $request, string $slug): Response
    {
        $result = $this->manager->activate($slug);

        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['error'] ?? 'Activation failed';
        } else {
            $_SESSION['flash_success'] = "Plugin '{$slug}' activated! ({$result['migrations_run']} migrations run)";
        }

        return Response::redirect('/admin/plugins');
    }

    public function deactivate(Request $request, string $slug): Response
    {
        $result = $this->manager->deactivate($slug);

        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['error'] ?? 'Deactivation failed';
        } else {
            $_SESSION['flash_success'] = "Plugin '{$slug}' deactivated.";
        }

        return Response::redirect('/admin/plugins');
    }

    public function uninstall(Request $request, string $slug): Response
    {
        $result = $this->manager->uninstall($slug);

        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['error'] ?? 'Uninstall failed';
        } else {
            $_SESSION['flash_success'] = "Plugin '{$slug}' uninstalled.";
        }

        return Response::redirect('/admin/plugins');
    }

    public function settings(Request $request, string $slug): Response
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            return Response::redirect('/admin/plugins');
        }

        $instance = $this->registry->get($slug);
        $settingsHtml = '';

        if ($instance !== null) {
            // Get saved settings
            $settingsRepo = $this->container->get(\OwnPay\Repository\SettingsRepository::class);
            $currentValues = $settingsRepo->getGroup("plugin.{$slug}");
            $action = "/admin/plugins/{$slug}/settings";
            $settingsHtml = SettingsRenderer::render($instance, $currentValues, $action);
        }

        return $this->render('admin/plugins/settings.twig', [
            'plugin' => $plugin,
            'settings_html' => $settingsHtml,
        ]);
    }

    public function saveSettings(Request $request, string $slug): Response
    {
        $settings = $request->post('settings') ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }

        /** @var \OwnPay\Repository\SettingsRepository $settingsRepo */
        $settingsRepo = $this->container->get(\OwnPay\Repository\SettingsRepository::class);
        $settingsRepo->bulkSet("plugin.{$slug}", $settings);

        $this->events->doAction('plugin.settings.saved', $slug, $settings);

        $_SESSION['flash_success'] = 'Settings saved.';
        return Response::redirect("/admin/plugins/{$slug}/settings");
    }

    private function render(string $template, array $data = []): Response
    {
        /** @var \Twig\Environment $twig */
        $twig = $this->container->get(\Twig\Environment::class);
        $data['app_name'] = $this->container->get('config.app')['name'] ?? 'Own Pay';
        $data['flash_success'] = $_SESSION['flash_success'] ?? null;
        $data['flash_error'] = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $html = $twig->render($template, $data);
        return Response::html($html);
    }

    private function redirectBack(Request $request, string $error): Response
    {
        $_SESSION['flash_error'] = $error;
        return Response::redirect($request->header('Referer') ?? '/admin/plugins/install');
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
