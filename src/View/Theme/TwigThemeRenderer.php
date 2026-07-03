<?php

declare(strict_types=1);

namespace OwnPay\View\Theme;

use Twig\Environment;

/**
 * Renders through the existing configured Twig environment. Behavior is
 * identical to the pre-abstraction direct $twig->render() calls, so the
 * default 'own-pay' Twig theme is unaffected.
 */
final class TwigThemeRenderer implements ThemeRendererInterface
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function render(string $templatePath, array $context): string
    {
        return $this->twig->render($templatePath, $context);
    }
}
