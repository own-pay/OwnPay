<?php

declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Lightweight WordPress-style asset enqueueing: plugins register a CSS/JS handle once
 * (deduped, with a flat deps list for print ordering) during their register() call;
 * templates print the accumulated set via a dedicated Twig function that builds tags
 * from this validated data rather than echoing arbitrary plugin-authored HTML - see
 * docs/superpowers/specs/2026-07-06-asset-enqueueing-design.md for why the existing
 * checkout.head/checkout.footer/admin.head/admin.footer action hooks cannot be reused
 * for this (their sanitizer strips link/script/meta tags unconditionally).
 */
final class AssetManager
{
    /** @var array<string, array{url: string, deps: array<int, string>, version: string|null}> */
    private array $styles = [];

    /** @var array<string, array{url: string, deps: array<int, string>, version: string|null}> */
    private array $scripts = [];

    public function __construct(private readonly Logger $logger)
    {
    }

    /**
     * @param array<int, string> $deps
     */
    public function enqueueStyle(string $handle, string $url, array $deps = [], ?string $version = null): void
    {
        $this->enqueue($this->styles, $handle, $url, $deps, $version);
    }

    /**
     * @param array<int, string> $deps
     */
    public function enqueueScript(string $handle, string $url, array $deps = [], ?string $version = null): void
    {
        $this->enqueue($this->scripts, $handle, $url, $deps, $version);
    }

    public function renderStyles(): string
    {
        return $this->render($this->styles, 'link');
    }

    public function renderScripts(): string
    {
        return $this->render($this->scripts, 'script');
    }

    /**
     * @param array<string, array{url: string, deps: array<int, string>, version: string|null}> $bucket
     * @param array<int, string> $deps
     */
    private function enqueue(array &$bucket, string $handle, string $url, array $deps, ?string $version): void
    {
        if (isset($bucket[$handle])) {
            return;
        }
        if (!$this->isValidUrl($url)) {
            $this->logger->warning("AssetManager: rejected invalid asset URL for handle '{$handle}': {$url}");
            return;
        }
        $bucket[$handle] = ['url' => $url, 'deps' => $deps, 'version' => $version];
    }

    private function isValidUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_contains($url, '<') || str_contains($url, '>')) {
            return false;
        }
        if (stripos($url, 'javascript:') === 0) {
            return false;
        }
        if ($url[0] === '/') {
            return true;
        }
        return stripos($url, 'http://') === 0 || stripos($url, 'https://') === 0;
    }

    /**
     * @param array<string, array{url: string, deps: array<int, string>, version: string|null}> $bucket
     */
    private function render(array $bucket, string $tag): string
    {
        $html = '';
        foreach ($this->topoSort($bucket) as $handle) {
            $asset = $bucket[$handle];
            $url = htmlspecialchars($asset['url'], ENT_QUOTES, 'UTF-8');
            if ($asset['version'] !== null) {
                $separator = str_contains($url, '?') ? '&amp;' : '?';
                $url .= $separator . 'v=' . htmlspecialchars($asset['version'], ENT_QUOTES, 'UTF-8');
            }
            $html .= $tag === 'link'
                ? "<link rel=\"stylesheet\" href=\"{$url}\">\n"
                : "<script src=\"{$url}\"></script>\n";
        }
        return $html;
    }

    /**
     * @param array<string, array{url: string, deps: array<int, string>, version: string|null}> $bucket
     * @return array<int, string> Handles in dependency-safe print order.
     */
    private function topoSort(array $bucket): array
    {
        $visited = [];
        $visiting = [];
        $order = [];

        $visit = function (string $handle) use (&$visit, &$visited, &$visiting, &$order, $bucket): void {
            if (isset($visited[$handle])) {
                return;
            }
            if (isset($visiting[$handle])) {
                $this->logger->warning("AssetManager: circular dependency detected involving handle '{$handle}'; breaking cycle.");
                return;
            }
            $visiting[$handle] = true;
            foreach ($bucket[$handle]['deps'] as $dep) {
                if (isset($bucket[$dep])) {
                    $visit($dep);
                }
            }
            unset($visiting[$handle]);
            $visited[$handle] = true;
            $order[] = $handle;
        };

        foreach (array_keys($bucket) as $handle) {
            $visit($handle);
        }

        return $order;
    }
}
