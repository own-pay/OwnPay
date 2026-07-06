<?php

declare(strict_types=1);

namespace OwnPay\View\Theme;

use RuntimeException;

/**
 * Renders plain-PHP theme templates. Each template is included inside an
 * isolated closure so it cannot touch the renderer instance ($this) or leak
 * variables into global scope. Context keys become local variables. Plain PHP
 * has no auto-escaping, so an $esc() helper is exposed for templates to call
 * explicitly (documented for theme authors).
 *
 * SECURITY: this renderer executes theme template PHP files with full trust -
 * there is no sandboxing. `include` provides scope isolation only, not a
 * security boundary: a template can call any global function, read
 * superglobals, and touch the filesystem. Only install themes from trusted
 * sources; a malicious template has the same capabilities as any other
 * server-side PHP code.
 */
final class PlainPhpThemeRenderer implements ThemeRendererInterface
{
    public function render(string $templatePath, array $context): string
    {
        if (!is_file($templatePath)) {
            throw new RuntimeException("Theme template not found: {$templatePath}");
        }

        $renderInIsolation = static function () use ($templatePath, $context): string {
            $esc = static function (mixed $value): string {
                $str = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
                return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
            };
            extract($context, EXTR_SKIP);
            ob_start();
            try {
                include $templatePath;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        };

        return $renderInIsolation();
    }
}
