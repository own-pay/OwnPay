<?php

declare(strict_types=1);

namespace OwnPay\View\Theme;

use InvalidArgumentException;

/**
 * Maps a theme's declared engine name to its renderer. An empty/absent engine
 * name is treated as 'twig' for back-compat, since existing theme manifests
 * omit the engine field entirely.
 */
final class ThemeRendererRegistry
{
    /** @var array<string, ThemeRendererInterface> */
    private array $renderers;

    /** @param array<string, ThemeRendererInterface> $renderers */
    public function __construct(array $renderers)
    {
        $this->renderers = $renderers;
    }

    public function get(string $engine): ThemeRendererInterface
    {
        $name = $engine === '' ? 'twig' : $engine;
        if (!isset($this->renderers[$name])) {
            throw new InvalidArgumentException("No theme renderer registered for engine: {$name}");
        }
        return $this->renderers[$name];
    }
}
