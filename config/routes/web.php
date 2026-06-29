<?php
declare(strict_types=1);

/**
 * OwnPay Web Routing Configuration.
 *
 * This file registers all public web routes, payment checkout endpoints,
 * administrative dashboard fragment targets, third-party webhook handlers,
 * background cron executions, and the multi-step installation wizard interface.
 *
 * @param \OwnPay\Http\Router $router The global application Router instance.
 * @return void
 */

return static function (\OwnPay\Http\Router $router): void {

    // ─── Public Pages ──────────────────────────────────────────
    $router->get('/', 'Page\\LandingController@index', 'web');
    $router->get('/rate-limit/emergency-reset', 'Admin\\DeveloperController@emergencyReset', 'web');

    // ─── Admin Login (dynamic slug for security) ───────────────
    // Slug is configurable in Settings → Landing Page → Admin Login URL Slug
    // Default: /login  |  Custom example: /secure-gate-7x2
    // Cache login slug to avoid DB query on every request (webhooks, public pages, etc.)
    $loginSlug = 'login';
    $cacheFile = dirname(__DIR__, 2) . '/storage/cache/login_slug.cache';
    $cacheTtl = 300; // 5 minutes

    // Try file cache first
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false && preg_match('/^[a-z0-9\-]+$/', $cached)) {
            $loginSlug = $cached;
        }
    } else {
        try {
            $settingsRepo = $router->getContainer()->get(\OwnPay\Repository\SettingsRepository::class);
            if ($settingsRepo instanceof \OwnPay\Repository\SettingsRepository) {
                $slug = $settingsRepo->get('landing', 'admin_login_slug', 'login');
                if (!empty($slug) && preg_match('/^[a-z0-9\-]+$/', $slug)) {
                    $loginSlug = $slug;
                }
            }
            // Write cache (atomic: write temp + rename)
            $cacheDir = dirname($cacheFile);
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            $tmpFile = $cacheFile . '.' . getmypid() . '.tmp';
            if (file_put_contents($tmpFile, $loginSlug, LOCK_EX) !== false) {
                rename($tmpFile, $cacheFile);
            }
        } catch (\Throwable) {
            // DB not ready (install phase) - use default
        }
    }
    $router->get('/' . $loginSlug,  'Admin\\AuthController@loginForm', 'web-auth');
    $router->post('/' . $loginSlug, 'Admin\\AuthController@login',     'web-auth');
    // If slug changed away from 'login', /login returns 404 (no route registered)
    $router->post('/logout', 'Admin\\AuthController@logout', 'web');
    $router->post('/admin/logout', 'Admin\\AuthController@logout', 'admin');
    $router->get('/forgot-password',  'Admin\\AuthController@forgotForm',   'web-auth');
    $router->post('/forgot-password', 'Admin\\AuthController@forgotSubmit', 'web-auth');
    $router->get('/reset-password',   'Admin\\AuthController@resetForm',    'web-auth');
    $router->post('/reset-password',  'Admin\\AuthController@resetSubmit',  'web-auth');
    $router->get('/2fa',  'Admin\\AuthController@twoFactorForm',   'web-auth');
    $router->post('/2fa', 'Admin\\AuthController@twoFactorVerify', 'web-auth');

    // ─── Checkout (public, minimal middleware) ─────────────────
    $router->get('/checkout/{token}', 'Checkout\\CheckoutController@show', 'checkout');
    $router->post('/checkout/{token}/pay', 'Checkout\\CheckoutController@pay', 'checkout');
    $router->post('/checkout/{token}/express', 'Checkout\\CheckoutController@expressPay', 'checkout');
    $router->post('/checkout/{token}/cancel', 'Checkout\\CheckoutController@cancel', 'checkout');
    $router->get('/checkout/{token}/status', 'Checkout\\CheckoutController@status', 'checkout');
    $router->post('/checkout/{token}/manual-verify', 'Checkout\\CheckoutController@manualVerify', 'checkout');

    // Universal Payment Intent checkout routes
    $router->get('/checkout/intent/{token}', 'Checkout\\PaymentIntentCheckoutController@show', 'checkout');
    $router->post('/checkout/intent/{token}/pay', 'Checkout\\PaymentIntentCheckoutController@pay', 'checkout');
    $router->post('/checkout/intent/{token}/express', 'Checkout\\PaymentIntentCheckoutController@expressPay', 'checkout');
    $router->get('/checkout/intent/{token}/status', 'Checkout\\PaymentIntentCheckoutController@status', 'checkout');
    $router->post('/checkout/intent/{token}/cancel', 'Checkout\\PaymentIntentCheckoutController@cancel', 'checkout');
    $router->post('/checkout/intent/{token}/manual-verify', 'Checkout\\PaymentIntentCheckoutController@manualVerify', 'checkout');

    // Invoice checkout
    $router->get('/invoice/{token}', 'Checkout\\InvoiceCheckoutController@show', 'checkout');

    // Payment link checkout
    $router->get('/pay/{slug}', 'Checkout\\PaymentLinkCheckoutController@show', 'checkout');
    $router->post('/pay/{slug}/submit', 'Checkout\\PaymentLinkCheckoutController@submit', 'checkout');

    // ─── Admin Panel (SPA-style AJAX fragments :D) ────────────────
    $router->get('/admin', 'Admin\\DashboardController@index', 'admin');
    $router->get('/admin/contributors', 'Admin\\ContributorController@index', 'admin');

    // Onboarding Setup Wizard <!-- note: Need to improve it. Contributor are welcome -->
    $router->post('/admin/setup-wizard/save-settings', 'Admin\\DashboardController@saveOnboardingSettings', 'admin');
    $router->post('/admin/setup-wizard/create-brand', 'Admin\\DashboardController@createOnboardingBrand', 'admin');
    $router->post('/admin/setup-wizard/setup-mail', 'Admin\\DashboardController@setupOnboardingMail', 'admin');
    $router->post('/admin/setup-wizard/setup-gateway', 'Admin\\DashboardController@setupOnboardingGateway', 'admin');
    $router->post('/admin/setup-wizard/complete', 'Admin\\DashboardController@completeOnboarding', 'admin');
    $router->post('/admin/setup-wizard/dismiss', 'Admin\\DashboardController@dismissOnboarding', 'admin');

    // Transactions
    $router->get('/admin/transactions', 'Admin\\TransactionController@index', 'admin');
    $router->get('/admin/transactions/{id}', 'Admin\\TransactionController@show', 'admin');
    $router->post('/admin/transactions/{id}/update', 'Admin\\TransactionController@updateStatus', 'admin');

    // Payment Intents
    $router->get('/admin/payment-intents', 'Admin\\PaymentIntentController@index', 'admin');
    $router->get('/admin/payment-intents/{id}', 'Admin\\PaymentIntentController@show', 'admin');

    // Refunds
    $router->get('/admin/refunds', 'Admin\\RefundController@index', 'admin');
    $router->post('/admin/refunds', 'Admin\\RefundController@store', 'admin');

    // Invoices
    $router->get('/admin/invoices', 'Admin\\InvoiceController@index', 'admin');
    $router->get('/admin/invoices/create', 'Admin\\InvoiceController@create', 'admin');
    $router->post('/admin/invoices/store', 'Admin\\InvoiceController@store', 'admin');
    $router->get('/admin/invoices/{id}/pdf', 'Admin\\InvoiceController@pdf', 'admin');
    $router->get('/admin/invoices/{id}', 'Admin\\InvoiceController@show', 'admin');
    $router->post('/admin/invoices/{id}/update', 'Admin\\InvoiceController@update', 'admin');

    // Disputes
    $router->get('/admin/disputes', 'Admin\\DisputeController@index', 'admin');
    $router->get('/admin/disputes/{id}', 'Admin\\DisputeController@show', 'admin');
    $router->post('/admin/disputes/{id}/resolve', 'Admin\\DisputeController@resolve', 'admin');

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
    $router->post('/admin/customers/{id}/delete', 'Admin\\CustomerController@delete', 'admin');

    // Brands/store
    $router->get('/admin/brands', 'Admin\\BrandController@index', 'admin');
    $router->get('/admin/brands/create', 'Admin\\BrandController@create', 'admin');
    $router->post('/admin/brands/store', 'Admin\\BrandController@store', 'admin');
    $router->post('/admin/brands/switch', 'Admin\\BrandController@switchBrand', 'admin');
    $router->get('/admin/brands/{id}', 'Admin\\BrandController@show', 'admin');
    $router->get('/admin/brands/{id}/edit', 'Admin\\BrandController@show', 'admin');
    $router->post('/admin/brands/{id}/update', 'Admin\\BrandController@update', 'admin');
    $router->post('/admin/brands/{id}/delete', 'Admin\\BrandController@delete', 'admin');

    // Staff
    $router->get('/admin/staff', 'Admin\\StaffController@index', 'admin');
    $router->get('/admin/staff/create', 'Admin\\StaffController@create', 'admin');
    $router->post('/admin/staff/store', 'Admin\\StaffController@store', 'admin');
    $router->get('/admin/staff/{id}', 'Admin\\StaffController@show', 'admin');
    $router->post('/admin/staff/{id}/update', 'Admin\\StaffController@update', 'admin');
    $router->post('/admin/staff/{id}/reset-2fa', 'Admin\\StaffController@reset2fa', 'admin');
    $router->post('/admin/staff/{id}/delete', 'Admin\\StaffController@delete', 'admin');

    // Roles & Permissions
    $router->get('/admin/roles', 'Admin\\RolesController@index', 'admin');
    $router->post('/admin/roles/store', 'Admin\\RolesController@store', 'admin');
    $router->post('/admin/roles/{id}/update', 'Admin\\RolesController@update', 'admin');
    $router->post('/admin/roles/{id}/delete', 'Admin\\RolesController@delete', 'admin');

    // Gateways
    $router->get('/admin/gateways', 'Admin\\GatewayController@index', 'admin');
    $router->get('/admin/gateways/create-manual', 'Admin\\GatewayController@createManual', 'admin');
    $router->post('/admin/gateways/store-manual', 'Admin\\GatewayController@storeManual', 'admin');
    // Phase 2c: a brand configures its OWN account for a platform-defined manual gateway template.
    $router->get('/admin/gateways/configure/{slug}', 'Admin\\GatewayController@configureAccount', 'admin');
    $router->post('/admin/gateways/configure/{slug}', 'Admin\\GatewayController@configureAccount', 'admin');
    $router->get('/admin/gateways/{id}/edit', 'Admin\\GatewayController@editManual', 'admin');
    $router->post('/admin/gateways/{id}/update', 'Admin\\GatewayController@updateManual', 'admin');
    $router->post('/admin/gateways/{id}/toggle', 'Admin\\GatewayController@toggle', 'admin');
    $router->post('/admin/gateways/{id}/delete', 'Admin\\GatewayController@delete', 'admin');

    // Domains
    $router->get('/admin/domains', 'Admin\\DomainController@index', 'admin');
    $router->post('/admin/domains', 'Admin\\DomainController@store', 'admin');        // modal posts here
    $router->post('/admin/domains/store', 'Admin\\DomainController@store', 'admin'); // alias
    $router->post('/admin/domains/{id}/verify', 'Admin\\DomainController@verify', 'admin');
    $router->post('/admin/domains/{id}/primary', 'Admin\\DomainController@primary', 'admin');
    $router->post('/admin/domains/{id}/delete', 'Admin\\DomainController@delete', 'admin');
    $router->post('/admin/domains/{id}/update', 'Admin\\DomainController@update', 'admin');
    $router->post('/admin/domains/{id}/check-ssl', 'Admin\\DomainController@checkSsl', 'admin');

    // Settings
    $router->get('/admin/settings', 'Admin\\SettingsController@index', 'admin');
    $router->get('/admin/settings/{tab}', 'Admin\\SettingsController@tab', 'admin');
    $router->post('/admin/settings/save', 'Admin\\SettingsController@save', 'admin');
    $router->post('/admin/settings/upload', 'Admin\\SettingsController@upload', 'admin');
    $router->post('/admin/settings/cron/regenerate', 'Admin\\SettingsController@regenerateCronSecret', 'admin');
    $router->post('/admin/settings/cron/run/{jobName}', 'Admin\\SettingsController@runCronJob', 'admin');

    // Fee Rules
    $router->get('/admin/fee-rules', 'Admin\\FeeRuleController@index', 'admin');
    $router->get('/admin/fee-rules/create', 'Admin\\FeeRuleController@create', 'admin');
    $router->post('/admin/fee-rules/store', 'Admin\\FeeRuleController@store', 'admin');
    $router->get('/admin/fee-rules/{id}/edit', 'Admin\\FeeRuleController@edit', 'admin');
    $router->post('/admin/fee-rules/{id}/update', 'Admin\\FeeRuleController@update', 'admin');
    $router->post('/admin/fee-rules/{id}/delete', 'Admin\\FeeRuleController@delete', 'admin');

    // Optimization
    $router->post('/admin/settings/optimize/cache', 'Admin\\SettingsController@optimizeCache', 'admin');
    $router->post('/admin/settings/optimize/database', 'Admin\\SettingsController@optimizeDatabase', 'admin');
    $router->post('/admin/settings/optimize/logs', 'Admin\\SettingsController@optimizeLogs', 'admin');
    $router->post('/admin/settings/optimize/uploads', 'Admin\\SettingsController@optimizeUploads', 'admin');
    $router->post('/admin/settings/optimize/all', 'Admin\\SettingsController@optimizeAll', 'admin');

    // Language Settings
    $router->post('/admin/settings/language/save-default', 'Admin\\SettingsController@saveDefaultLanguage', 'admin');
    $router->post('/admin/settings/language/create', 'Admin\\SettingsController@createLanguage', 'admin');
    $router->post('/admin/settings/language/upload', 'Admin\\SettingsController@uploadLanguage', 'admin');
    $router->get('/admin/settings/language/{code}/translate', 'Admin\\SettingsController@translateLanguage', 'admin');
    $router->post('/admin/settings/language/{code}/translate/save', 'Admin\\SettingsController@saveTranslations', 'admin');
    $router->post('/admin/settings/language/{code}/delete', 'Admin\\SettingsController@deleteLanguage', 'admin');

    // Currencies
    $router->get('/admin/currencies', 'Admin\\CurrencyController@index', 'admin');
    $router->post('/admin/currencies/update', 'Admin\\CurrencyController@update', 'admin');
    $router->post('/admin/currencies/toggle/{code}', 'Admin\\CurrencyController@toggle', 'admin');
    $router->post('/admin/currencies/sync-rates', 'Admin\\CurrencyController@syncRates', 'admin');

    // Devices
    $router->get('/admin/devices', 'Admin\\DeviceController@index', 'admin');
    $router->get('/admin/devices/notifications', 'Admin\\MobileNotificationLogController@index', 'admin');
    $router->post('/admin/devices/generate-otp', 'Admin\\DeviceController@generateOtp', 'admin');
    $router->get('/admin/devices/check-status', 'Admin\\DeviceController@checkStatus', 'admin');
    $router->get('/admin/devices/pairing-status', 'Admin\\DeviceController@pairingStatus', 'admin');
    $router->get('/admin/devices/statuses', 'Admin\\DeviceController@statuses', 'admin');
    $router->post('/admin/devices/{id}/revoke', 'Admin\\DeviceController@revoke', 'admin');
    $router->post('/admin/devices/bulk-revoke', 'Admin\\DeviceController@bulkRevoke', 'admin');
    $router->post('/admin/devices/settings', 'Admin\\DeviceController@saveSettings', 'admin');

    // SMS Center
    $router->get('/admin/sms-center',                 'Admin\\SmsTemplateAdminController@index',       'admin');
    $router->post('/admin/sms-center/create',         'Admin\\SmsTemplateAdminController@create',      'admin');
    $router->post('/admin/sms-center/analyze',        'Admin\\SmsTemplateAdminController@analyze',     'admin');
    $router->post('/admin/sms-center/ai-prompt',      'Admin\\SmsTemplateAdminController@aiPrompt',    'admin');
    $router->post('/admin/sms-center/save-analysis',  'Admin\\SmsTemplateAdminController@saveAnalysis','admin');
    $router->get('/admin/sms-center/{id}/edit',       'Admin\\SmsTemplateAdminController@edit',        'admin');
    $router->post('/admin/sms-center/{id}/edit',      'Admin\\SmsTemplateAdminController@edit',        'admin');
    $router->post('/admin/sms-center/{id}/delete',    'Admin\\SmsTemplateAdminController@delete',      'admin');
    $router->post('/admin/sms-center/test-regex',     'Admin\\SmsTemplateAdminController@testRegex',   'admin');
    $router->post('/admin/sms-center/queue/{id}/retry', 'Admin\\SmsTemplateAdminController@retryQueueItem', 'admin');
    $router->post('/admin/sms-center/match', 'Admin\\SmsTemplateAdminController@matchSms', 'admin');

    // SMS Data (parsed SMS log)
    $router->get('/admin/sms-data', 'Admin\\SmsDataController@index', 'admin');

    // API Keys
    $router->get('/admin/api-keys', 'Admin\\ApiKeyController@index', 'admin');
    $router->post('/admin/api-keys/generate', 'Admin\\ApiKeyController@generate', 'admin');
    $router->post('/admin/api-keys/{id}/revoke', 'Admin\\ApiKeyController@revoke', 'admin');

    // Developer Hub
    $router->get('/admin/developer', 'Admin\\DeveloperController@index', 'admin');
    // Inbound gateway webhook / IPN reference page (URLs + plain-English setup guide)
    $router->get('/admin/gateway-webhooks', 'Admin\\GatewayWebhookController@index', 'admin');
    $router->post('/admin/developer/webhook-test', 'Admin\\DeveloperController@webhookTest', 'admin');
    $router->post('/admin/developer/rate-limits/reset', 'Admin\\DeveloperController@resetLimit', 'admin');
    $router->post('/admin/developer/rate-limits/save', 'Admin\\DeveloperController@saveSettings', 'admin');
    $router->post('/admin/developer/webhooks/store', 'Admin\\WebhookController@store', 'admin');
    $router->post('/admin/developer/webhooks/{id}/toggle', 'Admin\\WebhookController@toggle', 'admin');
    $router->post('/admin/developer/webhooks/{id}/delete', 'Admin\\WebhookController@delete', 'admin');

    // Reports & Activities
    $router->get('/admin/ledger', 'Admin\\LedgerController@index', 'admin');
    $router->get('/admin/reports', 'Admin\\DashboardController@reports', 'admin');
    $router->get('/admin/reports/export', 'Admin\\DashboardController@exportCsv', 'admin');
    $router->get('/admin/activities', 'Admin\\ActivitiesController@index', 'admin');
    $router->get('/admin/activities/{id}/details', 'Admin\\ActivitiesController@details', 'admin');
    $router->get('/admin/audit-integrity', 'Admin\\AuditIntegrityController@scan', 'admin');
    $router->post('/admin/audit-integrity/scan', 'Admin\\AuditIntegrityController@scan', 'admin');
    $router->get('/admin/login-attempts', 'Admin\\LoginAttemptController@index', 'admin');
    $router->post('/admin/login-attempts/unlock', 'Admin\\LoginAttemptController@unlock', 'admin');

    // Webhook Events / DLQ
    $router->get('/admin/webhooks/events', 'Admin\\WebhookEventController@index', 'admin');
    $router->get('/admin/webhooks/events/{id}/logs', 'Admin\\WebhookEventController@logs', 'admin');
    $router->post('/admin/webhooks/events/{id}/replay', 'Admin\\WebhookEventController@replay', 'admin');

    // My Account
    $router->get('/admin/my-account', 'Admin\\DashboardController@myAccount', 'admin');
    $router->post('/admin/my-account/update', 'Admin\\DashboardController@updateAccount', 'admin');
    $router->get('/admin/my-account/2fa', 'Admin\\TwoFactorSetupController@index', 'admin');
    $router->post('/admin/my-account/2fa/enable', 'Admin\\TwoFactorSetupController@enable', 'admin');
    $router->post('/admin/my-account/2fa/disable', 'Admin\\TwoFactorSetupController@disable', 'admin');

    // Plugins, Themes, Addons
    $router->get('/admin/plugins', 'Admin\\PluginController@index', 'admin');
    $router->get('/admin/plugins/install', 'Admin\\PluginController@installForm', 'admin');
    $router->post('/admin/plugins/upload', 'Admin\\PluginController@upload', 'admin');
    $router->post('/admin/plugins/upload/cancel', 'Admin\\PluginController@cancelUpload', 'admin');
    $router->post('/admin/plugins/{slug}/activate', 'Admin\\PluginController@activate', 'admin');
    $router->post('/admin/plugins/{slug}/deactivate', 'Admin\\PluginController@deactivate', 'admin');
    $router->post('/admin/plugins/{slug}/uninstall', 'Admin\\PluginController@uninstall', 'admin');
    $router->post('/admin/plugins/{slug}/trash', 'Admin\\PluginController@trash', 'admin');
    $router->post('/admin/plugins/{slug}/restore', 'Admin\\PluginController@restore', 'admin');
    $router->get('/admin/plugins/{slug}/settings', 'Admin\\PluginController@settings', 'admin');
    $router->post('/admin/plugins/{slug}/settings', 'Admin\\PluginController@saveSettings', 'admin');

    $router->get('/admin/themes', 'Admin\\ThemeController@index', 'admin');
    $router->get('/admin/themes/install', 'Admin\\ThemeController@installForm', 'admin');
    $router->post('/admin/themes/upload', 'Admin\\ThemeController@upload', 'admin');
    $router->post('/admin/themes/{slug}/activate', 'Admin\\ThemeController@activate', 'admin');
    $router->post('/admin/themes/{slug}/uninstall', 'Admin\\ThemeController@uninstall', 'admin');

    // System Update
    $router->get('/admin/system-update', 'Admin\\SystemUpdateController@index', 'admin');
    $router->get('/admin/system-update/status', 'Admin\\SystemUpdateController@status', 'admin');
    $router->post('/admin/system-update/check', 'Admin\\SystemUpdateController@check', 'admin');
    $router->post('/admin/system-update/apply', 'Admin\\SystemUpdateController@install', 'admin');
    $router->post('/admin/system-update/settings', 'Admin\\SystemUpdateController@settings', 'admin');

    // Balance Verification
    $router->get('/admin/balance-verification', 'Admin\\BalanceVerificationController@index', 'admin');
    $router->post('/admin/balance-verification/run', 'Admin\\BalanceVerificationController@run', 'admin');

    // ─── Cron endpoint ─────────────────────────────────────────
    $router->get('/cron/{secret}', 'Page\\CronController@run', 'cron');
    $router->post('/cron', 'Page\\CronController@run', 'cron');

    // ─── Unified Webhook Endpoint (dynamic, zero-core-mod) ──────
    $router->post('/webhook/{gateway}', 'Webhook\\UnifiedWebhookController@handle', 'webhook');

    // /admin/login - redirect to actual login (common typo)
    $router->get('/admin/login', 'Admin\\AuthController@loginForm', 'web');

    // ─── CSP Report ────────────────────────────────────────────
    $router->post('/csp-report', 'Webhook\\CspReportController@handle', 'global');

    // ─── Install wizard (only when not installed) ──────────────
    // Uses 'install' middleware group (rate-limited)
    $router->get('/install', 'Install\\InstallerController@show', 'install');
    $router->post('/install/test-db', 'Install\\InstallerController@testDatabase', 'install');
    $router->post('/install/import-schema', 'Install\\InstallerController@importSchema', 'install');
    $router->post('/install/create-admin', 'Install\\InstallerController@createAdmin', 'install');
    $router->post('/install/finalize', 'Install\\InstallerController@finalize', 'install');
};
