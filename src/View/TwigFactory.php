<?php
declare(strict_types=1);

namespace OwnPay\View;

use OwnPay\Container;
use OwnPay\Support\Version;
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
        if (!is_array($config)) {
            $config = [];
        }
        $paths = isset($config['paths']) && is_array($config['paths']) ? $config['paths'] : [];

        $loader = new FilesystemLoader();

        $coreTemplates = isset($paths['templates']) && is_string($paths['templates']) ? $paths['templates'] : '';
        if ($coreTemplates !== '' && is_dir($coreTemplates)) {
            $loader->addPath($coreTemplates);
        }

        $modulesPath = isset($paths['modules']) && is_string($paths['modules']) ? $paths['modules'] : '';

        $activeTheme = self::resolveActiveTheme($container);
        if ($activeTheme !== null && $modulesPath !== '') {
            $themeDir = $modulesPath . '/themes/' . $activeTheme . '/templates';
            // VW-4: defense-in-depth. resolveActiveTheme() already
            // validates the theme name against ^[a-zA-Z0-9_-]+$, but we
            // also verify the resolved real path is still inside the
            // themes/ directory in case any traversal slipped past the
            // regex (symlinks, encoding tricks, future code changes).
            if (is_dir($themeDir) && self::isWithinThemesDir($themeDir, $modulesPath)) {
                $loader->prependPath($themeDir);
                $loader->addPath($themeDir, 'theme');
            }
        }

        $themesDir = $modulesPath !== '' ? $modulesPath . '/themes' : '';
        if ($themesDir !== '' && is_dir($themesDir)) {
            $themeDirs = glob($themesDir . '/*/templates');
            if (is_array($themeDirs)) {
                foreach ($themeDirs as $dir) {
                    $themeName = basename(dirname($dir));
                    $loader->addPath($dir, $themeName);
                }
            }
        }

        $addonsDir = $modulesPath !== '' ? $modulesPath . '/addons' : '';
        if ($addonsDir !== '' && is_dir($addonsDir)) {
            $addonViewDirs = glob($addonsDir . '/*/views');
            if (is_array($addonViewDirs)) {
                foreach ($addonViewDirs as $dir) {
                    $slug = basename(dirname($dir));
                    $loader->addPath($dir, $slug);
                }
            }
        }

        $gatewaysDir = $modulesPath !== '' ? $modulesPath . '/gateways' : '';
        if ($gatewaysDir !== '' && is_dir($gatewaysDir)) {
            $gatewayViewDirs = glob($gatewaysDir . '/*/views');
            if (is_array($gatewayViewDirs)) {
                foreach ($gatewayViewDirs as $dir) {
                    $slug = basename(dirname($dir));
                    $loader->addPath($dir, 'gateway_' . $slug);
                }
            }
        }

        $cachePath = isset($paths['cache']) && is_string($paths['cache']) ? $paths['cache'] : '';
        $debug = (bool) ($config['debug'] ?? false);

        $twig = new Environment($loader, [
            'cache'            => $cachePath !== '' ? $cachePath . '/twig' : false,
            'auto_reload'      => $debug,
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);

        $twig->addExtension(new TwigExtensions($container));

        $appName = isset($config['name']) && is_string($config['name']) ? $config['name'] : 'OwnPay';
        $appVersion = isset($config['version']) && is_string($config['version']) ? $config['version'] : Version::CURRENT;

        $twig->addGlobal('app_name', $appName);
        $twig->addGlobal('app_version', $appVersion);
        $twig->addGlobal('app_debug', $debug);

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
                if ($settings instanceof \OwnPay\Repository\SettingsRepository) {
                    $dbTheme = $settings->get('appearance', 'active_theme', '');
                    // VW-4: validate the DB-stored theme name against a strict
                    // safe-character pattern before using it. Without this,
                    // an admin who sets appearance.active_theme to
                    // '../../../tmp/evil' can point the Twig loader at an
                    // arbitrary directory (local file read / RCE via
                    // {% include %} of attacker-crafted .twig files).
                    if (is_string($dbTheme) && self::isSafeThemeName($dbTheme)) {
                        $theme = $dbTheme;
                    }
                }
            }
        } catch (\Throwable) {
            // Bypass database resolution if database connection is unavailable during early bootstrap phase
        }

        if ($theme === null) {
            $envTheme = getenv('ACTIVE_THEME');
            // VW-4: same validation for the env-var source.
            $theme = (is_string($envTheme) && self::isSafeThemeName($envTheme)) ? $envTheme : 'own-pay';
        }

        $config = $container->get('config.app');
        $paths = is_array($config) && isset($config['paths']) && is_array($config['paths']) ? $config['paths'] : [];
        $modulesPath = isset($paths['modules']) && is_string($paths['modules']) ? $paths['modules'] : '';

        if ($modulesPath !== '') {
            $themeDir = $modulesPath . '/themes/' . $theme;
            // VW-4: realpath containment check - rejects any traversal that
            // slipped past the regex (symlinks, encoded '.', etc.).
            if (is_dir($themeDir) && self::isWithinThemesBase($themeDir, $modulesPath)) {
                return $theme;
            }

            $defaultDir = $modulesPath . '/themes/own-pay';
            if (is_dir($defaultDir) && self::isWithinThemesBase($defaultDir, $modulesPath)) {
                return 'own-pay';
            }
        }

        return null;
    }

    /**
     * Validates a theme slug against the safe-character pattern (VW-4).
     *
     * Accepts only [a-zA-Z0-9_-], 1-64 chars. Rejects anything containing
     * path separators (/ \), dots (which would enable .. traversal),
     * NUL bytes, whitespace, or URL-encoded sequences.
     *
     * @param string $name The candidate theme slug.
     * @return bool True if the slug is structurally safe to use in a path.
     */
    private static function isSafeThemeName(string $name): bool
    {
        if ($name === '' || strlen($name) > 64) {
            return false;
        }
        return preg_match('/^[a-zA-Z0-9_-]+$/', $name) === 1;
    }

    /**
     * Verifies a theme directory resolves to a path inside the themes/
     * base directory (VW-4).
     *
     * Used by resolveActiveTheme() (path without /templates suffix) and by
     * create() (path WITH /templates suffix). The check normalizes via
     * realpath() and ensures the resolved path starts with the themes/
     * base directory followed by a separator, so symlink-based escapes and
     // encoded traversal sequences are rejected.
     *
     * @param string $themeDir The candidate theme directory (with or without /templates suffix).
     * @param string $modulesPath The configured modules path.
     * @return bool True if $themeDir resolves inside $modulesPath/themes/.
     */
    private static function isWithinThemesBase(string $themeDir, string $modulesPath): bool
    {
        if ($modulesPath === '') {
            return false;
        }
        $themesBase = realpath($modulesPath . '/themes');
        if ($themesBase === false) {
            return false;
        }
        $realTheme = realpath($themeDir);
        if ($realTheme === false) {
            return false;
        }
        $themesBaseWithSep = $themesBase . DIRECTORY_SEPARATOR;
        return $realTheme === $themesBase || str_starts_with($realTheme, $themesBaseWithSep);
    }

    /**
     * Verifies a /templates-qualified theme directory is inside the themes/
     * base directory (VW-4). Used by create() which prepends a /templates
     * suffix to the theme slug before calling the loader.
     *
     * @param string $themeDir The candidate theme templates directory (with /templates suffix).
     * @param string $modulesPath The configured modules path.
     * @return bool True if $themeDir resolves inside $modulesPath/themes/.
     */
    private static function isWithinThemesDir(string $themeDir, string $modulesPath): bool
    {
        return self::isWithinThemesBase($themeDir, $modulesPath);
    }
}

