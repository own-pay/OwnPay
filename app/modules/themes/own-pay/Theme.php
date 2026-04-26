<?php

declare(strict_types=1);

namespace OwnPayPlugin\OwnPay;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Event\EventManager;

/**
 * Own Pay native checkout theme.
 *
 * Implements the universal PluginInterface contract.
 * Settings are stored in op_env via get_env() / set_env() with `own-pay-` prefix.
 *
 * Architectural rules (per docs/security_audit/full_codebase_audit.md):
 *   - Brand color: regex-validated hex; insecure values fall back to #0D9488
 *   - Asset URLs: filemtime() cache-busting (no static ?v=x.y.z)
 *   - JS load order: op-fetch.js MUST precede checkout.js
 */
final class Theme implements PluginInterface
{
    public const SLUG = 'own-pay';
    public const DEFAULT_BRAND_COLOR = '#0D9488';

    public function register(EventManager $events): void
    {
        $events->addAction('admin.menu.register',  [$this, 'registerAdminMenu'], owner: self::SLUG);
        $events->addAction('checkout.head_assets', [$this, 'enqueueAssets'],     owner: self::SLUG);
        $events->addFilter('theme.checkout.render',     [$this, 'renderCheckout'],     owner: self::SLUG);
        $events->addFilter('theme.invoice.render',      [$this, 'renderInvoice'],      owner: self::SLUG);
        $events->addFilter('theme.payment_link.render', [$this, 'renderPaymentLink'],  owner: self::SLUG);
    }

    public function boot(): void {}

    public function activate(): void
    {
        // Seed defaults if absent. set_env signature: set_env($key, $value, 'both')
        $defaults = [
            'brand_color'         => self::DEFAULT_BRAND_COLOR,
            'logo_url'            => '',
            'dark_mode_default'   => 'auto',
            'show_dark_toggle'    => 'enabled',
            'support_email'       => '',
            'help_url'            => '',
            'footer_text'         => '',
            'custom_css'          => '',
            'custom_js'           => '',
            'timeout_minutes'     => '10',
            'timeout_enabled'     => 'enabled',
            'express_checkout'    => 'disabled',
            'show_security_badges'=> 'enabled',
        ];
        foreach ($defaults as $k => $v) {
            if (function_exists('get_env') && function_exists('set_env')) {
                $existing = get_env(self::SLUG . '-' . $k, 'both');
                if ($existing === '' || $existing === null) {
                    set_env(self::SLUG . '-' . $k, $v, 'both');
                }
            }
        }
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        // Settings rows are intentionally preserved on uninstall to allow
        // reinstall without losing configuration. Operator may purge via SQL.
    }

    public function info(): array
    {
        return [
            'title'       => 'Own Pay',
            'description' => 'Native Own Pay checkout theme — Tailwind 3 + Outfit, Cards/MFS/Bank tabs.',
            'version'     => '1.0.0',
        ];
    }

    public function fields(): array
    {
        return [
            ['name' => 'brand_color',        'label' => 'Brand Color',         'type' => 'color',    'value' => self::DEFAULT_BRAND_COLOR],
            ['name' => 'logo_url',           'label' => 'Custom Logo URL',     'type' => 'url'],
            ['name' => 'dark_mode_default',  'label' => 'Dark Mode',           'type' => 'select', 'options' => ['auto' => 'Auto (system)', 'light' => 'Light', 'dark' => 'Dark'], 'value' => 'auto'],
            ['name' => 'show_dark_toggle',   'label' => 'Show Dark Toggle',    'type' => 'select', 'options' => ['enabled' => 'Enabled', 'disabled' => 'Disabled'], 'value' => 'enabled'],
            ['name' => 'support_email',      'label' => 'Support Email',       'type' => 'email'],
            ['name' => 'help_url',           'label' => 'Help / Docs Link',    'type' => 'url'],
            ['name' => 'footer_text',        'label' => 'Footer Text',         'type' => 'text'],
            ['name' => 'custom_css',         'label' => 'Custom CSS',          'type' => 'textarea'],
            ['name' => 'custom_js',          'label' => 'Custom JS (advanced — XSS risk)', 'type' => 'textarea'],
            ['name' => 'timeout_minutes',    'label' => 'Session Timeout (minutes)', 'type' => 'number', 'value' => '10'],
            ['name' => 'timeout_enabled',    'label' => 'Enable Timeout',      'type' => 'select', 'options' => ['enabled' => 'Enabled', 'disabled' => 'Disabled'], 'value' => 'enabled'],
            ['name' => 'express_checkout',   'label' => 'Express Checkout (Apple/Google Pay UI)', 'type' => 'select', 'options' => ['enabled' => 'Enabled', 'disabled' => 'Disabled'], 'value' => 'disabled'],
            ['name' => 'show_security_badges','label' => 'Security Badges',    'type' => 'select', 'options' => ['enabled' => 'Enabled', 'disabled' => 'Disabled'], 'value' => 'enabled'],
        ];
    }

    /**
     * Register the admin sidebar entry via the universal hook bus.
     */
    public function registerAdminMenu(): void
    {
        EventManager::getInstance()->doAction('admin.sidebar.append', [
            'parent'     => 'settings',
            'title'      => 'Own Pay Theme',
            'href'       => '?page=settings/themes-setting&theme=' . self::SLUG,
            'icon'       => 'palette',
            'permission' => 'manage_themes',
        ]);
    }

    /**
     * Enqueue assets in the correct order:
     *   1. core op-fetch.js (provides global `opFetch`)
     *   2. theme checkout.css
     *   3. theme checkout.js (depends on opFetch)
     */
    public function enqueueAssets(): void
    {
        global $site_url, $csp_nonce;
        $siteUrl = $site_url ?? '';
        $nonce   = $csp_nonce ?? '';

        $coreJs   = __DIR__ . '/../../../../assets/js/op-fetch.js';
        $themeCss = __DIR__ . '/assets/css/checkout.css';
        $themeJs  = __DIR__ . '/assets/js/checkout.js';

        $coreJsVer   = file_exists($coreJs)   ? (string) filemtime($coreJs)   : '1';
        $themeCssVer = file_exists($themeCss) ? (string) filemtime($themeCss) : '1';
        $themeJsVer  = file_exists($themeJs)  ? (string) filemtime($themeJs)  : '1';

        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        echo '<link rel="stylesheet" href="' . $esc($siteUrl . 'app/modules/themes/own-pay/assets/css/checkout.css?v=' . $themeCssVer) . '">' . "\n";
        echo '<script nonce="' . $esc($nonce) . '" src="' . $esc($siteUrl . 'assets/js/op-fetch.js?v=' . $coreJsVer) . '"></script>' . "\n";
        echo '<script nonce="' . $esc($nonce) . '" src="' . $esc($siteUrl . 'app/modules/themes/own-pay/assets/js/checkout.js?v=' . $themeJsVer) . '" defer></script>' . "\n";
    }

    public function renderCheckout(array $data): string    { return $this->loadView('checkout', $data); }
    public function renderInvoice(array $data): string     { return $this->loadView('invoice', $data); }
    public function renderPaymentLink(array $data): string { return $this->loadView('payment-link', $data); }

    private function loadView(string $name, array $data): string
    {
        $path = __DIR__ . '/' . $name . '.php';
        if (!file_exists($path)) {
            return '';
        }
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }

    /**
     * Resolve a brand-color setting to a safe hex value.
     * Implements rule §1.1 — strict regex validation, insecure-fallback to default.
     */
    public static function safeBrandColor(): string
    {
        $color = function_exists('get_env')
            ? (string) (get_env(self::SLUG . '-brand_color', 'both') ?: self::DEFAULT_BRAND_COLOR)
            : self::DEFAULT_BRAND_COLOR;
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return self::DEFAULT_BRAND_COLOR;
        }
        return $color;
    }

    /**
     * Resolve a generic theme setting with an optional default.
     */
    public static function setting(string $key, string $default = ''): string
    {
        if (!function_exists('get_env')) {
            return $default;
        }
        $v = (string) (get_env(self::SLUG . '-' . $key, 'both') ?? '');
        return $v !== '' ? $v : $default;
    }
}
