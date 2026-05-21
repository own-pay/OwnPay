<?php
declare(strict_types=1);

namespace OwnPay\View;

use OwnPay\Container;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Class TwigFactory
 *
 * Implements a factory layer to instantiate and bootstrap the Twig rendering environment.
 * Sets up the layered filesystem loading order to support white-labeled theme overlays,
 * where merchant-scoped or system-wide active themes can prepend templates to override core views
 * while isolating plugin and gateway views under dedicated Twig namespaces.
 *
 * @package OwnPay\View
 */
final class TwigFactory
{
    /**
     * Build and configure the Twig environment instance.
     *
     * Registers directory loaders in order of priority (Theme templates > Core templates),
     * configures caching parameters, binds utility extensions, and sets global application variables.
     *
     * @param \OwnPay\Container $container The PSR-11 dependency injection container.
     * @return \Twig\Environment The fully configured Twig environment instance.
     * @throws \Twig\Error\LoaderError If the filesystem path registration encounters structural issues.
     */
    public static function create(Container $container): Environment
    {
        $config = $container->get('config.app');
        $paths  = $config['paths'];

        $loader = new FilesystemLoader();

        $coreTemplates = $paths['templates'];
        if (is_dir($coreTemplates)) {
            $loader->addPath($coreTemplates);
        }

        $activeTheme = self::resolveActiveTheme($container);
        if ($activeTheme !== null) {
            $themeDir = $paths['modules'] . '/themes/' . $activeTheme . '/templates';
            if (is_dir($themeDir)) {
                $loader->prependPath($themeDir);
                $loader->addPath($themeDir, 'theme');
            }
        }

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

        $twig = new Environment($loader, [
            'cache'            => $paths['cache'] . '/twig',
            'auto_reload'      => $config['debug'],
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);

        $twig->addExtension(new TwigExtensions($container));

        $twig->addGlobal('app_name', $config['name'] ?? 'Own Pay');
        $twig->addGlobal('app_version', $config['version'] ?? '0.1.0');
        $twig->addGlobal('app_debug', $config['debug'] ?? false);

        return $twig;
    }

    /**
     * Resolve the active theme slug.
     *
     * Executes resolution hierarchy checks:
     * 1. Database-backed settings repository lookup (e.g. customized merchant-scoped visual theme setting)
     * 2. Environment variable fallback configuration (e.g. system-wide overrides)
     * 3. Fallback directory verification on disk
     *
     * @param \OwnPay\Container $container The dependency injection container.
     * @return string|null The resolved active theme slug name, or null if no valid directory is matched.
     */
    private static function resolveActiveTheme(Container $container): ?string
    {
        $theme = null;

        try {
            if ($container->has(\OwnPay\Repository\SettingsRepository::class)) {
                $settings = $container->get(\OwnPay\Repository\SettingsRepository::class);
                $dbTheme = $settings->get('appearance', 'active_theme', '');
                if ($dbTheme !== '') {
                    $theme = $dbTheme;
                }
            }
        } catch (\Throwable) {
            // Bypass database resolution if database connection is unavailable during early bootstrap phase
        }

        if ($theme === null) {
            $theme = getenv('ACTIVE_THEME') ?: 'own-pay';
        }

        $paths = $container->get('config.app')['paths'];
        $themeDir = $paths['modules'] . '/themes/' . $theme;
        if (is_dir($themeDir)) {
            return $theme;
        }

        $defaultDir = $paths['modules'] . '/themes/own-pay';
        if (is_dir($defaultDir)) {
            return 'own-pay';
        }

        return null;
    }
}

