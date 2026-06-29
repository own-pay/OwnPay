<?php
declare(strict_types=1);

/**
 * System event hooks map - the authoritative catalogue of action/filter hooks a plugin can bind.
 *
 * Every entry below corresponds to a REAL fire site in the codebase (verified against
 * EventManager::doAction / applyFilter calls and the Twig `hook()` / `hookFilter()` helpers).
 * Keep this file in sync when adding or renaming a hook - it is the developer-facing contract.
 *
 * Semantics:
 *  - 'action' hooks are fire-and-forget: bind with $events->addAction($hook, $cb, $priority).
 *  - 'filter' hooks transform and return a value: bind with $events->addFilter($hook, $cb, $priority).
 *  - Hooks whose location is a *.twig template are dispatched via the `hook()` Twig helper, which
 *    buffers and sanitises whatever the listener echoes - use them to inject markup (menus, head/
 *    footer assets, settings-tab panels).
 *  - The unified webhook controller additionally dispatches a per-gateway dynamic action named
 *    "gateway.webhook.{slug}" (e.g. "gateway.webhook.stripe") when an inbound webhook is verified.
 *
 * @return array<string, array{type: 'action'|'filter', location: string}>
 */
return [
    // System lifecycle
    'system.boot'                       => ['type' => 'action', 'location' => 'Kernel'],
    'system.shutdown'                   => ['type' => 'action', 'location' => 'Kernel'],
    'system.request'                    => ['type' => 'filter', 'location' => 'Kernel'],
    'system.response'                   => ['type' => 'filter', 'location' => 'Kernel'],
    'system.middleware.pipeline'        => ['type' => 'filter', 'location' => 'Kernel'],
    'system.route.matched'              => ['type' => 'action', 'location' => 'Kernel'],
    'system.routes.register'            => ['type' => 'action', 'location' => 'Router'],
    'system.cron.before'                => ['type' => 'action', 'location' => 'CronJobRunner'],
    'system.cron.after'                 => ['type' => 'action', 'location' => 'CronJobRunner'],
    'system.update.available'           => ['type' => 'action', 'location' => 'SystemUpdateJob'],

    // Database
    'db.query.before'                   => ['type' => 'filter', 'location' => 'Database'],
    'db.query.after'                    => ['type' => 'action', 'location' => 'Database'],

    // Auth & session
    'auth.login.before'                 => ['type' => 'filter', 'location' => 'AuthSessionService'],
    'auth.login.attempt'                => ['type' => 'action', 'location' => 'AuthController'],
    'auth.login.success'                => ['type' => 'action', 'location' => 'AuthController, Authenticator, AuthSessionService'],
    'auth.login.failed'                 => ['type' => 'action', 'location' => 'Authenticator, AuthSessionService'],
    'auth.logout'                       => ['type' => 'action', 'location' => 'AuthController, AuthSessionService'],
    'auth.forgot_password'              => ['type' => 'action', 'location' => 'AuthController'],

    // Admin panel (templates dispatch via the Twig hook() helper - echo markup to inject)
    'admin.head'                        => ['type' => 'action', 'location' => 'admin/layout/base.twig <head>'],
    'admin.footer'                      => ['type' => 'action', 'location' => 'admin/layout/base.twig </body>'],
    'admin.menu.register'               => ['type' => 'action', 'location' => 'admin/layout/sidebar.twig'],
    'admin.dashboard.before'            => ['type' => 'action', 'location' => 'admin/dashboard.twig'],
    'admin.dashboard.bottom'            => ['type' => 'action', 'location' => 'admin/dashboard.twig'],
    'admin.dashboard.stats'             => ['type' => 'filter', 'location' => 'DashboardController'],
    'admin.landing.render'              => ['type' => 'action', 'location' => 'page/landing.twig'],
    'admin.login.render'                => ['type' => 'action', 'location' => 'page/login.twig'],
    'admin.page.before_render'          => ['type' => 'filter', 'location' => 'BaseController'],
    'admin.page.after_render'           => ['type' => 'filter', 'location' => 'BaseController'],
    'admin.template.resolve'            => ['type' => 'filter', 'location' => 'AdminPageTrait'],
    'admin.template.data'               => ['type' => 'filter', 'location' => 'AdminPageTrait'],
    'admin.settings.tabs'               => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'admin.settings.general'            => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'admin.settings.branding'           => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'admin.settings.landing'            => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'admin.settings.payment'            => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'admin.settings.email'              => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'admin.settings.security'           => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'admin.settings.checkout'           => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'admin.settings.notification'       => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'admin.settings.faq'                => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'admin.settings.cron'               => ['type' => 'action', 'location' => 'admin/settings/index.twig'],
    'settings.saved'                    => ['type' => 'action', 'location' => 'SettingsController'],

    // Landing
    'landing.head'                      => ['type' => 'action', 'location' => 'page/landing.twig'],
    'landing.features'                  => ['type' => 'filter', 'location' => 'LandingController'],

    // Payment & transaction
    'payment.amount.calculate'          => ['type' => 'filter', 'location' => 'PaymentService'],
    'payment.fee.calculate'             => ['type' => 'filter', 'location' => 'FeeService'],
    'payment.intent.created'            => ['type' => 'action', 'location' => 'PaymentService'],
    'payment.intent.expired'            => ['type' => 'action', 'location' => 'PaymentService'],
    'payment.transaction.before_create' => ['type' => 'filter', 'location' => 'TransactionService'],
    'payment.transaction.created'       => ['type' => 'action', 'location' => 'TransactionService'],
    'payment.transaction.completed'     => ['type' => 'action', 'location' => 'TransactionService'],
    'payment.transaction.failed'        => ['type' => 'action', 'location' => 'TransactionService'],
    'payment.transaction.cancelled'     => ['type' => 'action', 'location' => 'TransactionService'],
    'payment.refund.reconciliation_failed' => ['type' => 'action', 'location' => 'RefundReconciliationJob'],
    'refund.created'                    => ['type' => 'action', 'location' => 'RefundController'],
    'transaction.status.before'         => ['type' => 'action', 'location' => 'TransactionController'],
    'transaction.status.changed'        => ['type' => 'action', 'location' => 'TransactionController'],
    'ledger.entry.created'              => ['type' => 'action', 'location' => 'LedgerService'],
    'dispute.opened'                    => ['type' => 'action', 'location' => 'DisputeService'],
    'dispute.resolved'                  => ['type' => 'action', 'location' => 'DisputeService'],

    // Gateway & checkout
    'gateway.capture.before'            => ['type' => 'filter', 'location' => 'GatewayBridge'],
    'gateway.capture.after'             => ['type' => 'action', 'location' => 'GatewayBridge'],
    'checkout.before'                   => ['type' => 'action', 'location' => 'CheckoutController'],
    'checkout.render'                   => ['type' => 'filter', 'location' => 'CheckoutController'],
    'checkout.template'                 => ['type' => 'filter', 'location' => 'CheckoutController'],
    'checkout.status.template'          => ['type' => 'filter', 'location' => 'CheckoutController, InvoiceCheckoutController, PaymentLinkCheckoutController, PaymentIntentCheckoutController'],
    'checkout.intent.render'            => ['type' => 'filter', 'location' => 'PaymentIntentCheckoutController'],
    'checkout.payment_link.template'    => ['type' => 'filter', 'location' => 'PaymentLinkCheckoutController'],
    'checkout.gateway.selected'         => ['type' => 'action', 'location' => 'CheckoutController'],
    'checkout.cancelled'                => ['type' => 'action', 'location' => 'CheckoutController'],
    'checkout.manual_verify.submitted'  => ['type' => 'action', 'location' => 'CheckoutController, PaymentIntentCheckoutController'],
    'checkout.head'                     => ['type' => 'action', 'location' => 'checkout/checkout.twig <head>'],
    'checkout.footer'                   => ['type' => 'action', 'location' => 'checkout/checkout.twig </body>'],
    'checkout.csp.sources'              => ['type' => 'filter', 'location' => 'SecurityHeadersMiddleware'],

    // Invoice & payment link
    'invoice.created'                   => ['type' => 'action', 'location' => 'InvoiceController'],
    'invoice.updated'                   => ['type' => 'action', 'location' => 'InvoiceController'],
    'payment_link.created'              => ['type' => 'action', 'location' => 'PaymentLinkController'],
    'payment_link.updated'             => ['type' => 'action', 'location' => 'PaymentLinkController'],

    // Customer
    'customer.created'                  => ['type' => 'action', 'location' => 'CustomerPiiService'],
    'customer.updated'                  => ['type' => 'action', 'location' => 'CustomerPiiService'],
    'customer.deleted'                  => ['type' => 'action', 'location' => 'CustomerPiiService'],

    // Communication
    'communication.sms.send'            => ['type' => 'action', 'location' => 'CommunicationService, NotificationService'],
    'communication.mail.send'           => ['type' => 'action', 'location' => 'CommunicationService, NotificationService'],
    'communication.template.render'     => ['type' => 'filter', 'location' => 'CommunicationService'],
    'communication.channels'            => ['type' => 'filter', 'location' => 'CommunicationService'],

    // Mobile / SMS
    'mobile.device.paired'              => ['type' => 'action', 'location' => 'DevicePairingService'],
    'mobile.device.revoked'             => ['type' => 'action', 'location' => 'DevicePairingService'],
    'mobile.sms.matched'               => ['type' => 'action', 'location' => 'SmsVerificationJob'],
    'sms.received.before'               => ['type' => 'action', 'location' => 'SmsController'],
    'sms.received.after'                => ['type' => 'action', 'location' => 'SmsController'],
    'mfs.templates'                     => ['type' => 'filter', 'location' => 'SmsParserService'],

    // Webhook delivery (outbound)
    'webhook.delivery.success'          => ['type' => 'action', 'location' => 'WebhookDispatcher, WebhookService'],
    'webhook.delivery.failed'           => ['type' => 'action', 'location' => 'WebhookDispatcher, WebhookService'],

    // Update system
    'update.available'                  => ['type' => 'action', 'location' => 'UpdateService'],
    'update.before'                     => ['type' => 'action', 'location' => 'UpdateService'],
    'update.after'                      => ['type' => 'action', 'location' => 'UpdateService'],
    'update.failed'                     => ['type' => 'action', 'location' => 'UpdateService'],
    'update.rollback'                   => ['type' => 'action', 'location' => 'UpdateService'],

    // Domain
    'domain.mapped'                     => ['type' => 'action', 'location' => 'DomainService'],
    'domain.verified'                   => ['type' => 'action', 'location' => 'DomainService'],
    'domain.removed'                    => ['type' => 'action', 'location' => 'DomainService'],

    // Reporting & export
    'report.data'                       => ['type' => 'filter', 'location' => 'DashboardController'],
    'export.row'                        => ['type' => 'filter', 'location' => 'DashboardController'],

    // Audit
    'audit.log.created'                 => ['type' => 'action', 'location' => 'AuditLogger'],

    // Plugin lifecycle
    'plugins.before_load'              => ['type' => 'action', 'location' => 'PluginLoader'],
    'plugins.after_load'               => ['type' => 'action', 'location' => 'PluginLoader'],
    'plugin.load_error'                => ['type' => 'action', 'location' => 'PluginLoader'],
    'plugin.boot_error'                => ['type' => 'action', 'location' => 'PluginLoader'],
    'plugin.before_install'            => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.installed'                 => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.before_activate'           => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.activated'                 => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.before_deactivate'         => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.deactivated'               => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.before_uninstall'          => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.uninstalled'               => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.before_update'             => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.updated'                   => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.trashed'                   => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.restored'                  => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.settings.saved'            => ['type' => 'action', 'location' => 'PluginController'],
];
