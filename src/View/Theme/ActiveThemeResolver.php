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
        // Defense-in-depth (audit THM-2): the slug is sourced from
        // SettingsRepository and is normally validated upstream by
        // ThemeController::activate()/saveBrandTheme() against the plugins
        // table. However, a compromised plugin row with a traversal slug
        // (e.g. '../attacker-theme') — inserted via zip-slip during plugin
        // install, a corrupted manifest, or direct DB access — would flow
        // straight into filesystem path interpolation here, enabling LFI
        // via PlainPhpThemeRenderer::include. Reject any slug that is not a
        // strict identifier; also verify realpath() containment of $basePath
        // inside $themesBaseDir so symlinks cannot escape the themes dir.
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
            throw new \InvalidArgumentException("Invalid theme slug: {$slug}");
        }

        $themesBase = rtrim($this->themesBaseDir, '/\\');
        $engine = '';
        $manifestPath = $themesBase . '/' . $slug . '/manifest.json';
        if (is_file($manifestPath)) {
            $raw = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($raw) && isset($raw['engine']) && is_string($raw['engine'])) {
                $engine = $raw['engine'];
            }
        }
        $basePath = $themesBase . '/' . $slug;
        $realBase = realpath($basePath);
        $realThemesBase = realpath($themesBase);
        if ($realBase === false || $realThemesBase === false
            || !str_starts_with($realBase . DIRECTORY_SEPARATOR, $realThemesBase . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException("Theme base path escapes themes directory: {$basePath}");
        }
        return new ActiveTheme($slug, $engine, $basePath, $fellBack);
    }
}
