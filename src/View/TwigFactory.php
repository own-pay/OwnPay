<?php
declare(strict_types=1);

namespace OwnPay\View;

use OwnPay\Container;
use OwnPay\Support\Version;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;

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
            if (is_dir($themeDir)) {
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

        // VW-3: Enable a Twig sandbox with a strict SecurityPolicy. The
        // sandbox is registered in NON-global mode so it can be toggled
        // per-render via $twig->getExtension(SandboxExtension::class)
        // ->enableSandbox(). Trusted core/plugin-shipped templates are
        // rendered without the sandbox (full Twig features available);
        // untrusted user-editable templates (email templates, invoice
        // notes, brand-uploaded theme templates) are rendered with the
        // sandbox enabled so a malicious template author cannot exfiltrate
        // secrets via {{ env('DB_PASSWORD') }}, invoke arbitrary hooks via
        // {{ hook('db.query.before') }}, or escape the autoescape policy
        // via {{ _self }} introspection.
        //
        // The SecurityPolicy allow-lists only safe tags, filters, and the
        // functions/methods explicitly registered by the platform. Any
        // template that attempts to call a non-allowlisted function (e.g.
        // env(), setting(), hook()) throws a SecurityError when the
        // sandbox is enabled.
        $sandboxPolicy = new SecurityPolicy(
            // Allowed tags: only structural/display tags.
            ['if', 'for', 'set', 'block', 'extends', 'include', 'macro', 'import', 'from'],
            // Allowed filters: common formatting filters only.
            ['abs', 'capitalize', 'date', 'default', 'escape', 'first', 'format', 'join', 'json_encode', 'keys', 'last', 'length', 'lower', 'merge', 'nl2br', 'number_format', 'replace', 'reverse', 'round', 'slice', 'sort', 'split', 'striptags', 'title', 'trim', 'upper', 'url_encode'],
            // Allowed methods: none — templates cannot call object methods.
            [],
            // Allowed properties: none — templates cannot access object
            // properties via dot notation beyond what __get exposes.
            [],
            // Allowed functions: only safe built-ins. env(), setting(),
            // hook(), and hookFilter() are NOT in the allow-list.
            ['attribute', 'cycle', 'date', 'max', 'min', 'random', 'range', 'template_from_string']
        );
        $sandbox = new SandboxExtension($sandboxPolicy, /* globally */ false);
        $twig->addExtension($sandbox);

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
                    if (is_string($dbTheme) && $dbTheme !== '') {
                        $theme = $dbTheme;
                    }
                }
            }
        } catch (\Throwable) {
            // Bypass database resolution if database connection is unavailable during early bootstrap phase
        }

        if ($theme === null) {
            $envTheme = getenv('ACTIVE_THEME');
            $theme = is_string($envTheme) && $envTheme !== '' ? $envTheme : 'own-pay';
        }

        $config = $container->get('config.app');
        $paths = is_array($config) && isset($config['paths']) && is_array($config['paths']) ? $config['paths'] : [];
        $modulesPath = isset($paths['modules']) && is_string($paths['modules']) ? $paths['modules'] : '';

        if ($modulesPath !== '') {
            $themeDir = $modulesPath . '/themes/' . $theme;
            if (is_dir($themeDir)) {
                return $theme;
            }

            $defaultDir = $modulesPath . '/themes/own-pay';
            if (is_dir($defaultDir)) {
                return 'own-pay';
            }
        }

        return null;
    }
}

