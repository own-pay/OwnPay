<?php
declare(strict_types=1);

namespace OwnPay\Service\Brand;

use OwnPay\Core\Database;
use OwnPay\Repository\SettingsRepository;

/**
 * Brand theme service — resolves per-brand visual customization.
 *
 * Reads brand-scoped settings from op_system_settings (merchant_id scoped),
 * active theme configuration, and custom CSS/JS overrides.
 *
 * White-label pipeline: Every checkout page under a custom domain
 * renders with the brand's unique visual identity.
 */
final class BrandThemeService
{
    private Database $db;
    private SettingsRepository $settings;

    public function __construct(Database $db, SettingsRepository $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    /**
     * Get complete brand visual data for checkout rendering.
     *
     * @return array{
     *     name: string,
     *     logo: string,
     *     favicon: string,
     *     color: string,
     *     accent_color: string,
     *     support_email: string,
     *     custom_css: string,
     *     custom_js: string,
     *     footer_text: string,
     *     show_powered_by: bool
     * }
     */
    public function getBrandTheme(int $merchantId): array
    {
        // 1. Merchant record (name, logo)
        $merchant = $this->db->fetchOne(
            "SELECT name, slug, logo_path, settings FROM op_merchants WHERE id = :id",
            ['id' => $merchantId]
        );

        // BUG-28 FIX: Return sensible defaults if merchant doesn't exist.
        // Without this, null array access causes TypeError crashes.
        if ($merchant === null || $merchant === false) {
            return [
                'name'           => 'Own Pay',
                'logo'           => '',
                'favicon'        => '',
                'color'          => '#0D9488',
                'accent_color'   => '#0F766E',
                'support_email'  => '',
                'custom_css'     => '',
                'custom_js'      => '',
                'footer_text'    => 'Secured by Own Pay · 256-bit encryption',
                'show_powered_by'=> true,
            ];
        }

        // 2. Brand-scoped settings override (uses merchant_id-aware uk_group_key_merchant)
        $brandSettings = $this->getBrandSettings($merchantId);

        // 3. Global fallback settings
        $globalSettings = $this->settings->getGroup('general');
        $themeSettings = $this->settings->getGroup('theme');

        // Merge: brand-scoped > merchant JSON settings > global settings
        $merchantJsonSettings = json_decode($merchant['settings'] ?? '{}', true) ?: [];

        return [
            'name'           => $merchant['name'] ?? $globalSettings['app_name'] ?? 'Own Pay',
            'logo'           => $this->resolveVal($brandSettings, $merchantJsonSettings, 'logo', $merchant['logo_path'] ?? ''),
            'favicon'        => $this->resolveVal($brandSettings, $merchantJsonSettings, 'favicon', ''),
            'color'          => $this->resolveVal($brandSettings, $merchantJsonSettings, 'primary_color', $themeSettings['primary_color'] ?? '#0D9488'),
            'accent_color'   => $this->resolveVal($brandSettings, $merchantJsonSettings, 'accent_color', $themeSettings['accent_color'] ?? '#0F766E'),
            'support_email'  => $this->resolveVal($brandSettings, $merchantJsonSettings, 'support_email', $globalSettings['support_email'] ?? ''),
            'custom_css'     => $brandSettings['custom_css'] ?? $merchantJsonSettings['custom_css'] ?? '',
            'custom_js'      => $brandSettings['custom_js'] ?? $merchantJsonSettings['custom_js'] ?? '',
            'footer_text'    => $this->resolveVal($brandSettings, $merchantJsonSettings, 'footer_text', 'Secured by ' . ($merchant['name'] ?? 'Own Pay') . ' · 256-bit encryption'),
            'show_powered_by'=> (bool) ($brandSettings['show_powered_by'] ?? $merchantJsonSettings['show_powered_by'] ?? true),
        ];
    }

    /**
     * Get brand-scoped settings from op_system_settings.
     *
     * @return array<string, string>
     */
    private function getBrandSettings(int $merchantId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT key_name, value FROM op_system_settings WHERE group_name = 'theme' AND merchant_id = :mid",
            ['mid' => $merchantId]
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key_name']] = $row['value'];
        }
        return $settings;
    }

    /**
     * Resolve value: brand-scoped > merchant JSON > fallback
     */
    private function resolveVal(array $brandSettings, array $merchantSettings, string $key, string $fallback): string
    {
        if (!empty($brandSettings[$key])) {
            return $brandSettings[$key];
        }
        if (!empty($merchantSettings[$key])) {
            return $merchantSettings[$key];
        }
        return $fallback;
    }
}
