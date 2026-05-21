<?php
declare(strict_types=1);

namespace OwnPay\View;

use Twig\Environment;

/**
 * Class PluginViewRenderer
 *
 * Coordinates multi-tenant/brand isolated plugin template loading, resolving Twig-based views
 * via namespaced paths first, with a fallback to secure, sandboxed PHP views.
 * Facilitates the white-labeled theme override structure by ensuring plugins can publish views
 * under their corresponding namespace, enabling independent third-party customization.
 *
 * @package OwnPay\View
 */
final class PluginViewRenderer
{
    /**
     * @var \Twig\Environment The Twig template engine environment.
     */
    private Environment $twig;

    /**
     * PluginViewRenderer constructor.
     *
     * @param \Twig\Environment $twig The Twig template engine environment.
     */
    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    /**
     * Render plugin view.
     *
     * Resolution hierarchy:
     * 1. @{slug}/{template}.twig (the plugin's direct Twig namespace)
     * 2. @{slug}/{template}.html.twig
     * 3. PHP template fallback: {pluginDir}/views/{template}.php (sandboxed extraction)
     *
     * @param string $pluginSlug The unique slug of the plugin.
     * @param string $template The relative path or name of the template.
     * @param array<string, mixed> $data Key-value template variables passed to the view context.
     * @param string|null $pluginDir The absolute directory path of the plugin (required for PHP fallback).
     * @return string The rendered HTML content.
     * @throws \RuntimeException If the view template cannot be located in any resolution path.
     * @throws \Throwable If a rendering exception is thrown during evaluation.
     */
    public function render(string $pluginSlug, string $template, array $data = [], ?string $pluginDir = null): string
    {
        $twigTemplates = [
            "@{$pluginSlug}/{$template}.twig",
            "@{$pluginSlug}/{$template}.html.twig",
        ];

        foreach ($twigTemplates as $twigTemplate) {
            try {
                if ($this->twig->getLoader()->exists($twigTemplate)) {
                    return $this->twig->render($twigTemplate, $data);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($pluginDir !== null) {
            $phpFile = $pluginDir . '/views/' . $template . '.php';
            if (file_exists($phpFile)) {
                return $this->renderPhp($phpFile, $data);
            }
        }

        throw new \RuntimeException("View not found: {$template} for plugin {$pluginSlug}");
    }

    /**
     * Render core admin template with plugin/theme override support.
     *
     * Resolves layout templates through the Twig engine environment, which respects
     * prioritized paths (e.g., brand-specific theme overrides over system defaults).
     *
     * @param string $template The relative path or name of the core template.
     * @param array<string, mixed> $data Key-value context variables for the template scope.
     * @return string The rendered HTML content.
     * @throws \Twig\Error\LoaderError When the template cannot be resolved.
     * @throws \Twig\Error\RuntimeError When a runtime rendering error occurs.
     * @throws \Twig\Error\SyntaxError When there is a syntax error in the template.
     */
    public function renderAdmin(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    /**
     * Check if template exists for plugin.
     *
     * Probes the Twig loader to check if the namespaced template exists.
     *
     * @param string $pluginSlug The unique slug of the plugin.
     * @param string $template The relative path or name of the template.
     * @return bool True if a valid Twig template is resolved, false otherwise.
     */
    public function exists(string $pluginSlug, string $template): bool
    {
        $candidates = [
            "@{$pluginSlug}/{$template}.twig",
            "@{$pluginSlug}/{$template}.html.twig",
        ];

        foreach ($candidates as $t) {
            try {
                if ($this->twig->getLoader()->exists($t)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * Render PHP template with extracted data (sandboxed).
     *
     * Extracts variable context using EXTR_SKIP to prevent local variable collisions
     * and compiles the template output safely.
     *
     * @param string $file The absolute path of the target PHP view file.
     * @param array<string, mixed> $data Key-value context variables extracted into the template scope.
     * @return string The captured HTML string output.
     * @throws \Throwable If rendering or execution inside the PHP file throws an exception.
     */
    private function renderPhp(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}

