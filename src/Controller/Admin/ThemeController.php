<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
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
    private Container $container;
    private PluginManager $manager;
    private PluginRepository $repo;
    private SettingsRepository $settings;

    public function __construct(
        Container $container,
        PluginManager $manager,
        PluginRepository $repo,
        SettingsRepository $settings
    ) {
        $this->container = $container;
        $this->manager = $manager;
        $this->repo = $repo;
        $this->settings = $settings;
    }

    public function index(Request $request): Response
    {
        $themes = $this->repo->listByType('theme');
        $activeTheme = $this->settings->get('appearance', 'active_theme', 'default');

        // Also discover filesystem themes not yet in DB
        /** @var PluginLoader $loader */
        $loader = $this->container->get(PluginLoader::class);
        $discovered = $loader->discover();
        foreach ($discovered as $manifest) {
            if ($manifest->type === 'theme' && !isset($themes[array_search($manifest->slug, array_column($themes, 'slug'))])) {
                // Filesystem-only theme, not installed in DB yet
            }
        }

        return $this->render('admin/themes/index.twig', [
            'themes' => $themes,
            'active_theme' => $activeTheme,
        ]);
    }

    public function activate(Request $request, string $slug): Response
    {
        $plugin = $this->repo->findBySlug($slug);
        if ($plugin === null) {
            $_SESSION['flash_error'] = 'Theme not found';
            return Response::redirect('/admin/themes');
        }

        // Activate plugin if not already
        if ($plugin['status'] !== 'active') {
            $this->manager->activate($slug);
        }

        // Set as active theme
        $this->settings->set('appearance', 'active_theme', $slug);

        $_SESSION['flash_success'] = "Theme '{$plugin['name']}' activated!";
        return Response::redirect('/admin/themes');
    }

    public function uninstall(Request $request, string $slug): Response
    {
        $activeTheme = $this->settings->get('appearance', 'active_theme', 'default');
        if ($slug === $activeTheme) {
            $_SESSION['flash_error'] = 'Cannot uninstall the active theme. Switch to another theme first.';
            return Response::redirect('/admin/themes');
        }

        $result = $this->manager->uninstall($slug);
        if ($result['success']) {
            $_SESSION['flash_success'] = 'Theme removed.';
        } else {
            $_SESSION['flash_error'] = $result['error'] ?? 'Failed to remove theme';
        }

        return Response::redirect('/admin/themes');
    }

    public function customize(Request $request, string $slug): Response
    {
        // Redirect to plugin settings page (themes use same settings system)
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
        return Response::html($twig->render($template, $data));
    }
}
