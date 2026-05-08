<?php
declare(strict_types=1);

/**
 * Web routes — admin panel, checkout, pages, cron.
 *
 * Called by Router::loadRoutes(). Each route maps:
 *   method, pattern, handler (Controller@method), middleware group
 *
 * @param \OwnPay\Http\Router $router
 */

return static function (\OwnPay\Http\Router $router): void {

    // ─── Public Pages ──────────────────────────────────────────
    $router->get('/', 'Page\\LandingController@index', 'web');
    $router->get('/login', 'Admin\\AuthController@loginForm', 'web');
    $router->post('/login', 'Admin\\AuthController@login', 'web');
    $router->get('/logout', 'Admin\\AuthController@logout', 'web');
    $router->post('/logout', 'Admin\\AuthController@logout', 'web');
    $router->post('/admin/logout', 'Admin\\AuthController@logout', 'admin');
    $router->get('/forgot-password', 'Admin\\AuthController@forgotForm', 'web');
    $router->post('/forgot-password', 'Admin\\AuthController@forgotSubmit', 'web');
    $router->get('/2fa', 'Admin\\AuthController@twoFactorForm', 'web');
    $router->post('/2fa', 'Admin\\AuthController@twoFactorVerify', 'web');

    // ─── Checkout (public, minimal middleware) ─────────────────
    $router->get('/checkout/{token}', 'Checkout\\CheckoutController@show', 'checkout');
    $router->post('/checkout/{token}/pay', 'Checkout\\CheckoutController@pay', 'checkout');
    $router->post('/checkout/{token}/cancel', 'Checkout\\CheckoutController@cancel', 'checkout');
    $router->get('/checkout/{token}/status', 'Checkout\\CheckoutController@status', 'checkout');
    $router->post('/checkout/{token}/manual-verify', 'Checkout\\CheckoutController@manualVerify', 'checkout');

    // Invoice checkout
    $router->get('/invoice/{token}', 'Checkout\\InvoiceCheckoutController@show', 'checkout');

    // Payment link checkout
    $router->get('/pay/{slug}', 'Checkout\\PaymentLinkCheckoutController@show', 'checkout');
    $router->post('/pay/{slug}/submit', 'Checkout\\PaymentLinkCheckoutController@submit', 'checkout');

    // ─── Admin Panel (SPA-style AJAX fragments) ────────────────
    $router->get('/admin', 'Admin\\DashboardController@index', 'admin');

    // Admin content fragments (loaded via AJAX into SPA shell)
    $router->get('/admin/fragment/{page}', 'Admin\\DashboardController@fragment', 'admin');

    // Transactions
    $router->get('/admin/transactions', 'Admin\\TransactionController@index', 'admin');
    $router->get('/admin/transactions/{id}', 'Admin\\TransactionController@show', 'admin');
    $router->post('/admin/transactions/{id}/update', 'Admin\\TransactionController@updateStatus', 'admin');

    // Invoices
    $router->get('/admin/invoices', 'Admin\\InvoiceController@index', 'admin');
    $router->get('/admin/invoices/create', 'Admin\\InvoiceController@create', 'admin');
    $router->post('/admin/invoices/store', 'Admin\\InvoiceController@store', 'admin');
    $router->get('/admin/invoices/{id}', 'Admin\\InvoiceController@show', 'admin');
    $router->post('/admin/invoices/{id}/update', 'Admin\\InvoiceController@update', 'admin');

    // Payment Links
    $router->get('/admin/payment-links', 'Admin\\PaymentLinkController@index', 'admin');
    $router->get('/admin/payment-links/create', 'Admin\\PaymentLinkController@create', 'admin');
    $router->post('/admin/payment-links/store', 'Admin\\PaymentLinkController@store', 'admin');
    $router->get('/admin/payment-links/{id}', 'Admin\\PaymentLinkController@show', 'admin');
    $router->post('/admin/payment-links/{id}/update', 'Admin\\PaymentLinkController@update', 'admin');

    // Customers
    $router->get('/admin/customers', 'Admin\\CustomerController@index', 'admin');
    $router->get('/admin/customers/create', 'Admin\\CustomerController@create', 'admin');
    $router->post('/admin/customers/store', 'Admin\\CustomerController@store', 'admin');
    $router->get('/admin/customers/{id}', 'Admin\\CustomerController@show', 'admin');

    // Brands (formerly Merchants)
    $router->get('/admin/brands', 'Admin\\BrandController@index', 'admin');
    $router->get('/admin/brands/create', 'Admin\\BrandController@create', 'admin');
    $router->post('/admin/brands/store', 'Admin\\BrandController@store', 'admin');
    $router->post('/admin/brands/switch', 'Admin\\BrandController@switchBrand', 'admin');
    $router->get('/admin/brands/{id}', 'Admin\\BrandController@show', 'admin');
    $router->get('/admin/brands/{id}/edit', 'Admin\\BrandController@show', 'admin');
    $router->post('/admin/brands/{id}/update', 'Admin\\BrandController@update', 'admin');

    // Staff
    $router->get('/admin/staff', 'Admin\\StaffController@index', 'admin');
    $router->get('/admin/staff/create', 'Admin\\StaffController@create', 'admin');
    $router->post('/admin/staff/store', 'Admin\\StaffController@store', 'admin');
    $router->get('/admin/staff/{id}', 'Admin\\StaffController@show', 'admin');
    $router->post('/admin/staff/{id}/update', 'Admin\\StaffController@update', 'admin');
    $router->post('/admin/staff/{id}/delete', 'Admin\\StaffController@delete', 'admin');

    // Gateways
    $router->get('/admin/gateways', 'Admin\\GatewayController@index', 'admin');
    $router->get('/admin/gateways/create-manual', 'Admin\\GatewayController@createManual', 'admin');
    $router->post('/admin/gateways/store-manual', 'Admin\\GatewayController@storeManual', 'admin');
    $router->get('/admin/gateways/{id}/edit', 'Admin\\GatewayController@editManual', 'admin');
    $router->post('/admin/gateways/{id}/update', 'Admin\\GatewayController@updateManual', 'admin');
    $router->post('/admin/gateways/{id}/toggle', 'Admin\\GatewayController@toggle', 'admin');
    $router->post('/admin/gateways/{id}/delete', 'Admin\\GatewayController@delete', 'admin');

    // Domains
    $router->get('/admin/domains', 'Admin\\DomainController@index', 'admin');
    $router->post('/admin/domains/store', 'Admin\\DomainController@store', 'admin');
    $router->post('/admin/domains/{id}/verify', 'Admin\\DomainController@verify', 'admin');
    $router->post('/admin/domains/{id}/delete', 'Admin\\DomainController@delete', 'admin');

    // Settings
    $router->get('/admin/settings', 'Admin\\SettingsController@index', 'admin');
    $router->get('/admin/settings/{tab}', 'Admin\\SettingsController@tab', 'admin');
    $router->post('/admin/settings/save', 'Admin\\SettingsController@save', 'admin');

    // Currencies
    $router->get('/admin/currencies', 'Admin\\CurrencyController@index', 'admin');
    $router->post('/admin/currencies/update', 'Admin\\CurrencyController@update', 'admin');

    // SMS Center
    $router->get('/admin/sms-center', 'Admin\\SmsTemplateAdminController@index', 'admin');
    $router->get('/admin/sms-center/templates', 'Admin\\SmsTemplateAdminController@templates', 'admin');
    $router->post('/admin/sms-center/templates/save', 'Admin\\SmsTemplateAdminController@save', 'admin');
    $router->get('/admin/sms-center/queue', 'Admin\\SmsTemplateAdminController@queue', 'admin');
    $router->get('/admin/sms-center/{id}/edit', 'Admin\\SmsTemplateAdminController@edit', 'admin');
    $router->post('/admin/sms-center/{id}/edit', 'Admin\\SmsTemplateAdminController@edit', 'admin');
    $router->get('/admin/sms-data', 'Admin\\SmsDataController@index', 'admin');

    // Devices
    $router->get('/admin/devices', 'Admin\\DeviceController@index', 'admin');
    $router->post('/admin/devices/{id}/revoke', 'Admin\\DeviceController@revoke', 'admin');
    $router->post('/admin/devices/bulk-revoke', 'Admin\\DeviceController@bulkRevoke', 'admin');

    // API Keys
    $router->get('/admin/api-keys', 'Admin\\ApiKeyController@index', 'admin');
    $router->post('/admin/api-keys/generate', 'Admin\\ApiKeyController@generate', 'admin');
    $router->post('/admin/api-keys/{id}/revoke', 'Admin\\ApiKeyController@revoke', 'admin');

    // Reports & Activities
    $router->get('/admin/ledger', 'Admin\\LedgerController@index', 'admin');
    $router->get('/admin/reports', 'Admin\\DashboardController@reports', 'admin');
    $router->get('/admin/reports/export', 'Admin\\DashboardController@exportCsv', 'admin');
    $router->get('/admin/activities', 'Admin\\DashboardController@activities', 'admin');

    // My Account
    $router->get('/admin/my-account', 'Admin\\DashboardController@myAccount', 'admin');
    $router->post('/admin/my-account/update', 'Admin\\DashboardController@updateAccount', 'admin');
    $router->get('/admin/my-account/2fa', 'Admin\\TwoFactorSetupController@index', 'admin');
    $router->post('/admin/my-account/2fa/enable', 'Admin\\TwoFactorSetupController@enable', 'admin');
    $router->post('/admin/my-account/2fa/disable', 'Admin\\TwoFactorSetupController@disable', 'admin');

    // FAQ
    $router->get('/admin/faq', 'Admin\\FaqController@index', 'admin');
    $router->post('/admin/faq/save', 'Admin\\FaqController@save', 'admin');

    // Plugins, Themes, Addons
    $router->get('/admin/plugins', 'Admin\\PluginController@index', 'admin');
    $router->get('/admin/plugins/install', 'Admin\\PluginController@installForm', 'admin');
    $router->post('/admin/plugins/upload', 'Admin\\PluginController@upload', 'admin');
    $router->post('/admin/plugins/{slug}/activate', 'Admin\\PluginController@activate', 'admin');
    $router->post('/admin/plugins/{slug}/deactivate', 'Admin\\PluginController@deactivate', 'admin');
    $router->post('/admin/plugins/{slug}/uninstall', 'Admin\\PluginController@uninstall', 'admin');
    $router->get('/admin/plugins/{slug}/settings', 'Admin\\PluginController@settings', 'admin');
    $router->post('/admin/plugins/{slug}/settings', 'Admin\\PluginController@saveSettings', 'admin');

    $router->get('/admin/themes', 'Admin\\ThemeController@index', 'admin');
    $router->get('/admin/themes/install', 'Admin\\ThemeController@installForm', 'admin');
    $router->post('/admin/themes/upload', 'Admin\\ThemeController@upload', 'admin');
    $router->post('/admin/themes/{slug}/activate', 'Admin\\ThemeController@activate', 'admin');
    $router->post('/admin/themes/{slug}/uninstall', 'Admin\\ThemeController@uninstall', 'admin');

    $router->get('/admin/addons', 'Admin\\AddonController@index', 'admin');

    // System Update
    $router->get('/admin/system-update', 'Admin\\SystemUpdateController@index', 'admin');
    $router->post('/admin/system-update/check', 'Admin\\SystemUpdateController@check', 'admin');
    $router->post('/admin/system-update/apply', 'Admin\\SystemUpdateController@install', 'admin');

    // Balance Verification
    $router->get('/admin/balance-verification', 'Admin\\BalanceVerificationController@index', 'admin');
    $router->post('/admin/balance-verification/run', 'Admin\\BalanceVerificationController@run', 'admin');

    // ─── Cron endpoint ─────────────────────────────────────────
    $router->get('/cron/{secret}', 'Page\\CronController@run', 'global');

    // ─── Unified Webhook Endpoint (dynamic, zero-core-mod) ──────
    $router->post('/webhook/{gateway}', 'Webhook\\UnifiedWebhookController@handle', 'webhook');

    // ─── CSP Report ────────────────────────────────────────────
    $router->post('/csp-report', 'Page\\CspReportController@handle', 'global');

    // ─── Install wizard (only when not installed) ──────────────
    $router->get('/install', 'Install\\InstallerController@show', 'global');
    $router->post('/install/test-db', 'Install\\InstallerController@testDatabase', 'global');
    $router->post('/install/create-admin', 'Install\\InstallerController@createAdmin', 'global');
    $router->post('/install/finalize', 'Install\\InstallerController@finalize', 'global');
};
