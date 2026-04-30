<?php
declare(strict_types=1);

namespace OwnPay\View;

use OwnPay\Container;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Twig environment factory with plugin theme path injection.
 *
 * Build order:
 *   1. Core templates (templates/)
 *   2. Active theme templates (modules/themes/{active}/templates/) — override core
 *   3. Plugin view paths (modules/addons/{slug}/views/) — namespaced @slug
 *
 * Theme templates override core by sharing the same path namespace.
 * Plugin views are isolated under @plugin_slug namespace.
 */
final class TwigFactory
{
    /**
     * Build a configured Twig\Environment.
     */
    public static function create(Container $container): Environment
    {
        $config = $container->get('config.app');
        $paths  = $config['paths'];

        // ── Filesystem Loader ──────────────────────────────────
        $loader = new FilesystemLoader();

        // 1. Core templates (lowest priority — overridden by themes)
        $coreTemplates = $paths['templates'];
        if (is_dir($coreTemplates)) {
            $loader->addPath($coreTemplates);
        }

        // 2. Active theme templates (highest priority — overrides core)
        $activeTheme = self::resolveActiveTheme($container);
        if ($activeTheme !== null) {
            $themeDir = $paths['modules'] . '/themes/' . $activeTheme . '/templates';
            if (is_dir($themeDir)) {
                // Prepend so theme templates take priority over core
                $loader->prependPath($themeDir);
                // Also register under @theme namespace for explicit access
                $loader->addPath($themeDir, 'theme');
            }
        }

        // 3. Register all theme directories under their own namespace
        $themesDir = $paths['modules'] . '/themes';
        if (is_dir($themesDir)) {
            $themeDirs = glob($themesDir . '/*/templates');
            if (is_array($themeDirs)) {
                foreach ($themeDirs as $dir) {
                    $themeName = basename(dirname($dir));
                    $loader->addPath($dir, $themeName);
                }
            }
        }

        // 4. Register plugin/addon view paths under @slug namespace
        $addonsDir = $paths['modules'] . '/addons';
        if (is_dir($addonsDir)) {
            $addonViewDirs = glob($addonsDir . '/*/views');
            if (is_array($addonViewDirs)) {
                foreach ($addonViewDirs as $dir) {
                    $slug = basename(dirname($dir));
                    $loader->addPath($dir, $slug);
                }
            }
        }

        // Gateway plugin views
        $gatewaysDir = $paths['modules'] . '/gateways';
        if (is_dir($gatewaysDir)) {
            $gatewayViewDirs = glob($gatewaysDir . '/*/views');
            if (is_array($gatewayViewDirs)) {
                foreach ($gatewayViewDirs as $dir) {
                    $slug = basename(dirname($dir));
                    $loader->addPath($dir, 'gateway_' . $slug);
                }
            }
        }

        // ── Environment ────────────────────────────────────────
        $twig = new Environment($loader, [
            'cache'            => $paths['cache'] . '/twig',
            'auto_reload'      => $config['debug'],
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);

        // Register core extensions
        $twig->addExtension(new TwigExtensions($container));

        // Register global variables available in all templates
        $twig->addGlobal('app_name', $config['name'] ?? 'Own Pay');
        $twig->addGlobal('app_version', $config['version'] ?? '0.1.0');
        $twig->addGlobal('app_debug', $config['debug'] ?? false);

        return $twig;
    }

    /**
     * Resolve the currently active theme slug.
     *
     * Reads from: DB setting → .env fallback → 'own-pay' default.
     */
    private static function resolveActiveTheme(Container $container): ?string
    {
        // Phase E SettingsService will provide DB lookup.
        // For now: env var or default.
        $theme = getenv('ACTIVE_THEME') ?: 'own-pay';

        // Verify theme directory exists
        $paths = $container->get('config.app')['paths'];
        $themeDir = $paths['modules'] . '/themes/' . $theme;
        if (is_dir($themeDir)) {
            return $theme;
        }

        // Fall back to own-pay
        $defaultDir = $paths['modules'] . '/themes/own-pay';
        if (is_dir($defaultDir)) {
            return 'own-pay';
        }

        return null;
    }
}
