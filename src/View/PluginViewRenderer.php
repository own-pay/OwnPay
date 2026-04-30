<?php
declare(strict_types=1);

namespace OwnPay\View;

use Twig\Environment;

/**
 * Plugin view renderer — resolves .twig first, .php fallback.
 *
 * Plugins provide views under @slug namespace.
 * Core admin templates can also be overridden by active theme.
 */
final class PluginViewRenderer
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    /**
     * Render plugin view.
     *
     * Resolution order:
     *   1. @{slug}/{template}.twig (plugin's own namespace)
     *   2. @{slug}/{template}.html.twig
     *   3. PHP fallback: {pluginDir}/views/{template}.php
     *
     * @return string Rendered HTML
     */
    public function render(string $pluginSlug, string $template, array $data = [], ?string $pluginDir = null): string
    {
        // Try Twig namespaced template
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

        // PHP fallback
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
     * Resolution: active theme → core templates
     * (TwigFactory already handles this via path priority)
     */
    public function renderAdmin(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    /**
     * Check if template exists for plugin.
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
