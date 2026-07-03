<?php

declare(strict_types=1);

namespace OwnPay\View\Theme;

/**
 * Immutable description of the theme that should render for a given request:
 * its slug, its rendering engine, its base directory, and whether it was
 * reached via a fallback (chosen theme missing/inactive). resolveTemplate()
 * maps a logical template name to the identifier the theme's engine expects.
 */
final class ActiveTheme
{
    public function __construct(
        public readonly string $slug,
        public readonly string $engine,
        public readonly string $basePath,
        public readonly bool $fellBack
    ) {
    }

    public function resolveTemplate(string $logicalName): string
    {
        $base = preg_replace('/\.(twig|php|html)$/', '', $logicalName) ?? $logicalName;
        return match ($this->engine) {
            'plain-php' => rtrim($this->basePath, '/\\') . '/templates/' . $base . '.php',
            default     => $base . '.twig',
        };
    }
}
