<?php

declare(strict_types=1);

namespace OwnPay\View\Theme;

/**
 * Contract for a theme rendering engine. An engine turns a resolved template
 * path plus a context array into an HTML string. Implementations are chosen
 * per active theme via ThemeRendererRegistry based on the theme's declared
 * engine name (see PluginManifest::$engine).
 */
interface ThemeRendererInterface
{
    /**
     * @param string               $templatePath Engine-appropriate template identifier
     *                                            (a Twig loader name for Twig, an absolute
     *                                            filesystem path for plain-PHP).
     * @param array<string, mixed> $context      Template variables.
     */
    public function render(string $templatePath, array $context): string;
}
