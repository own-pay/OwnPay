<?php
declare(strict_types=1);

namespace OwnPay\Service\Brand;

use OwnPay\Core\Database;
use OwnPay\Repository\SettingsRepository;

/**
 * OwnPay Brand Theme Service.
 *
 * Resolves per-brand visual customizations for payment checkouts under custom domains.
 * Applies hierarchical settings resolution logic: brand-specific settings (merchant_id scoped
 * in database) > merchant metadata JSON configurations > system-wide fallback defaults.
 *
 * @package OwnPay\Service\Brand
 */
final class BrandThemeService
{
    /**
     * @var Database The database execution wrapper.
     */
    private Database $db;

    /**
     * @var SettingsRepository The repository for system-wide configuration settings.
     */
    private SettingsRepository $settings;

    /**
     * BrandThemeService constructor.
     *
     * @param Database $db The database engine.
     * @param SettingsRepository $settings System settings repository.
     */
    public function __construct(Database $db, SettingsRepository $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    /**
     * Retrieves the complete aggregated brand visual data for checkout interface rendering.
     *
     * Integrates layout colors, logotypes, icons, custom styling blocks (CSS/JS),
     * support routing addresses, and branding footers.
     *
     * @param int $merchantId The primary identifier of the brand/merchant context.
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
        // 1. Resolve merchant core profile configuration
        $merchant = $this->db->fetchOne(
            "SELECT name, slug, logo_path, settings FROM op_merchants WHERE id = :id",
            ['id' => $merchantId]
        );

        // Fallback safely to generic details if the target merchant entity does not exist
        if ($merchant === null) {
            return [
                'name'           => 'OwnPay',
                'logo'           => '',
                'favicon'        => '',
                'color'          => '#0D9488',
                'accent_color'   => '#0F766E',
                'support_email'  => '',
                'custom_css'     => '',
                'custom_js'      => '',
                'footer_text'    => 'Secured by OwnPay · 256-bit encryption',
                'show_powered_by'=> true,
            ];
        }

        // 2. Load brand-specific settings from the database system settings table
        $brandSettings = $this->getBrandSettings($merchantId);

        // 3. Load global fallback system configurations
        $globalSettings = $this->settings->getGroup('general');
        $themeSettings = $this->settings->getGroup('theme');
        $brandingSettings = $this->settings->getGroup('branding');

        $fallbackLogo = $brandingSettings['site_logo'] ?? '';
        $fallbackFavicon = $brandingSettings['site_favicon'] ?? '';

        // Unpack merchant-specific JSON metadata settings overrides
        $settingsStr = $merchant['settings'] ?? '{}';
        $settingsStr = is_string($settingsStr) ? $settingsStr : '{}';
        $merchantJsonSettings = json_decode($settingsStr, true);
        $merchantJsonSettings = is_array($merchantJsonSettings) ? $merchantJsonSettings : [];

        $merchantLogo = !empty($merchant['logo_path']) && is_string($merchant['logo_path']) ? $merchant['logo_path'] : $fallbackLogo;

        return [
            'name'           => is_scalar($merchant['name'] ?? null) ? (string) $merchant['name'] : ($globalSettings['app_name'] ?? 'OwnPay'),
            'logo'           => $this->resolveVal($brandSettings, $merchantJsonSettings, 'logo', $merchantLogo),
            'favicon'        => $this->resolveVal($brandSettings, $merchantJsonSettings, 'favicon', $fallbackFavicon),
            'color'          => $this->resolveVal($brandSettings, $merchantJsonSettings, 'primary_color', (string) ($themeSettings['primary_color'] ?? '#0D9488')),
            'accent_color'   => $this->resolveVal($brandSettings, $merchantJsonSettings, 'accent_color', (string) ($themeSettings['accent_color'] ?? '#0F766E')),
            'support_email'  => $this->resolveVal($brandSettings, $merchantJsonSettings, 'support_email', (string) ($globalSettings['support_email'] ?? '')),
            'custom_css'     => $this->resolveVal($brandSettings, $merchantJsonSettings, 'custom_css', ''),
            'custom_js'      => $this->resolveVal($brandSettings, $merchantJsonSettings, 'custom_js', ''),
            'footer_text'    => $this->resolveVal($brandSettings, $merchantJsonSettings, 'footer_text', 'Secured by ' . (is_scalar($merchant['name'] ?? null) ? (string) $merchant['name'] : 'OwnPay') . ' · 256-bit encryption'),
            'show_powered_by'=> (bool) ($brandSettings['show_powered_by'] ?? $merchantJsonSettings['show_powered_by'] ?? true),
            'language'       => $this->resolveVal($brandSettings, $merchantJsonSettings, 'language', ''),
            'checkout_success_msg' => $this->resolveVal($brandSettings, $merchantJsonSettings, 'checkout_success_msg', ''),
            'checkout_pending_msg' => $this->resolveVal($brandSettings, $merchantJsonSettings, 'checkout_pending_msg', ''),
            'checkout_failed_msg'  => $this->resolveVal($brandSettings, $merchantJsonSettings, 'checkout_failed_msg', ''),
        ];
    }

    /**
     * Retrieves brand-scoped overrides from the system settings database table.
     *
     * @param int $merchantId The merchant primary identifier.
     * @return array<string, string> Associative index of setting keys and values.
     */
    private function getBrandSettings(int $merchantId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT key_name, value FROM op_system_settings WHERE group_name = 'theme' AND merchant_id = :mid",
            ['mid' => $merchantId]
        );

        $settings = [];
        foreach ($rows as $row) {
            $k = $row['key_name'] ?? '';
            $v = $row['value'] ?? '';
            if (is_string($k) && is_scalar($v)) {
                $settings[$k] = (string) $v;
            }
        }
        return $settings;
    }

    /**
     * Resolves a key value by prioritizing brand overrides over fallback defaults.
     *
     * Priority:
     * 1. Brand settings overrides.
     * 2. Merchant settings overrides.
     * 3. Fallback default value.
     *
     * @param array<string, string> $brandSettings Loaded brand settings overrides.
     * @param array<string, mixed> $merchantSettings Loaded merchant metadata overrides.
     * @param string $key Settings key name.
     * @param string $fallback Fallback value.
     * @return string Resolved configuration value.
     */
    private function resolveVal(array $brandSettings, array $merchantSettings, string $key, string $fallback): string
    {
        if (!empty($brandSettings[$key])) {
            return $brandSettings[$key];
        }
        $val = $merchantSettings[$key] ?? null;
        if (is_scalar($val) && $val !== '') {
            return (string) $val;
        }
        return $fallback;
    }
}
