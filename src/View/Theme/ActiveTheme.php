<?php

declare(strict_types=1);

namespace OwnPay\View\Theme;

/**
 * Immutable description of the theme that should render for a given request:
 * its slug, its rendering engine, its base directory, and whether it was
 * reached via a fallback (chosen theme missing/inactive). resolveTemplate()
 * maps a logical template name to the identifier the theme's engine expects.
 * resolveTemplate() rejects logical names containing '..' or absolute-path
 * prefixes to prevent path traversal outside the theme's own template directory.
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
        if (str_contains($logicalName, '..') || preg_match('#^([a-zA-Z]:)?[/\\\\]#', $logicalName) === 1) {
            throw new \InvalidArgumentException("Invalid theme template name: {$logicalName}");
        }

        $base = preg_replace('/\.(twig|php|html)$/', '', $logicalName) ?? $logicalName;
        return match ($this->engine) {
            'plain-php' => rtrim($this->basePath, '/\\') . '/templates/' . $base . '.php',
            default     => $base . '.twig',
        };
    }
}
