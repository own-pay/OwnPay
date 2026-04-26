<?php
/**
 * Own Pay Theme — legacy admin-UI compatibility shim.
 *
 * The universal-plugin contract lives in `Theme.php` (namespaced
 * `OwnPayPlugin\OwnPay\Theme` implementing `PluginInterface`).
 *
 * This file exists to satisfy the legacy admin UI at
 * `app/admin/dashboard/settings/themes-setting.php`, which expects:
 *   - filename: `class.php`
 *   - class:    `{Slug}Theme` (e.g. `OwnPayTheme`) at the global namespace
 *   - methods:  `fields()`, `supported_languages()`
 *
 * `OwnPayTheme` extends the universal-contract class so all behavior is
 * sourced from a single place — only the legacy method `supported_languages()`
 * is added here.
 */
declare(strict_types=1);

if (!defined('OWNPAY_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

require_once __DIR__ . '/Theme.php';

/**
 * Legacy global-namespace alias for the admin UI.
 */
final class OwnPayTheme extends \OwnPayPlugin\OwnPay\Theme
{
    /**
     * Languages supported by this theme — used by the legacy admin UI.
     * Reads from the existing get_env language store; falls back to en + bn.
     *
     * @return array<int, array{code:string,name:string}>
     */
    public function supported_languages(): array
    {
        return [
            ['code' => 'en', 'name' => 'English'],
            ['code' => 'bn', 'name' => 'বাংলা'],
        ];
    }
}
