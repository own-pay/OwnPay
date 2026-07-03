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
 */
final class PlainPhpThemeRenderer implements ThemeRendererInterface
{
    public function render(string $templatePath, array $context): string
    {
        if (!is_file($templatePath)) {
            throw new RuntimeException("Theme template not found: {$templatePath}");
        }

        $renderInIsolation = static function () use ($templatePath, $context): string {
            /** @var callable $esc */
            $esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
