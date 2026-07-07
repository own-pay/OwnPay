<?php

declare(strict_types=1);

namespace OwnPay\View\Theme;

use OwnPay\Plugin\PluginRegistry;
use OwnPay\Repository\SettingsRepository;

/**
 * Resolves which theme should render for a request, honoring a brand-scoped
 * active_theme setting and silently falling back to the global theme (then to
 * a hard-coded bundled default) when the chosen theme's plugin row is missing
 * or not active. A fallback sets ActiveTheme::$fellBack so the Appearance page
 * can surface a "your theme is no longer available" notice.
 */
final class ActiveThemeResolver
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PluginRegistry $registry,
        private readonly string $themesBaseDir,
        private readonly string $fallbackSlug = 'own-pay'
    ) {
    }

    public function resolve(?int $brandId): ActiveTheme
    {
        // 1. Brand-scoped pick (falls back to global inside getScoped()).
        if ($brandId !== null && $brandId > 0) {
            $slug = (string) $this->settings->getScoped('appearance', 'active_theme', $brandId, '');
            if ($slug !== '') {
                if ($this->isUsable($slug, $brandId)) {
                    return $this->build($slug, false);
                }
                // Brand pick was configured but is unusable -> try the global pick, flag as fallback.
                $global = (string) $this->settings->get('appearance', 'active_theme', '');
                if ($global !== '' && $this->isUsable($global, null)) {
                    return $this->build($global, true);
                }
                return $this->build($this->fallbackSlug, true);
            }
            // No brand-scoped override configured; resolve as a global request.
            $brandId = null;
        }

        // 2. Global request.
        $slug = (string) $this->settings->get('appearance', 'active_theme', '');
        if ($slug === '') {
            // No override configured at all -> using the bundled default is not a fallback.
            return $this->build($this->fallbackSlug, false);
        }
        if ($this->isUsable($slug, null)) {
            return $this->build($slug, false);
        }
        // A slug was configured but is unusable -> genuine fallback.
        return $this->build($this->fallbackSlug, true);
    }

    private function isUsable(string $slug, ?int $brandId): bool
    {
        return $this->registry->isPluginActive($slug, $brandId);
    }

    private function build(string $slug, bool $fellBack): ActiveTheme
    {
        $engine = '';
        $manifestPath = rtrim($this->themesBaseDir, '/\\') . '/' . $slug . '/manifest.json';
        if (is_file($manifestPath)) {
            $raw = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($raw) && isset($raw['engine']) && is_string($raw['engine'])) {
                $engine = $raw['engine'];
            }
        }
        $basePath = rtrim($this->themesBaseDir, '/\\') . '/' . $slug;
        return new ActiveTheme($slug, $engine, $basePath, $fellBack);
    }
}
