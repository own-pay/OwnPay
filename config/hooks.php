<?php
declare(strict_types=1);

/**
 * Core hook registration points.
 *
 * This file documents every hook/filter the core fires.
 * Plugins reference this to know what they can listen to.
 * EventManager registers no hooks automatically — they are fired
 * inline by the services listed below.
 *
 * Format: 'hook.name' => ['type' => 'action|filter', 'location' => 'ClassName']
 */

return [
    // ── System Lifecycle ────────────────────────────────────────
    'system.boot'                    => ['type' => 'action', 'location' => 'Kernel'],
    'system.shutdown'                => ['type' => 'action', 'location' => 'Kernel'],
    'system.maintenance.enter'       => ['type' => 'action', 'location' => 'MaintenanceMiddleware'],
    'system.maintenance.exit'        => ['type' => 'action', 'location' => 'UpdateService'],
    'system.config.loaded'           => ['type' => 'filter', 'location' => 'Kernel'],
    'system.routes.register'         => ['type' => 'action', 'location' => 'Router'],
    'system.middleware.pipeline'      => ['type' => 'filter', 'location' => 'Kernel'],
    'system.cron.before'             => ['type' => 'action', 'location' => 'CronJobRunner'],
    'system.cron.after'              => ['type' => 'action', 'location' => 'CronJobRunner'],

    // ── Auth & Session ──────────────────────────────────────────
    'auth.login.before'              => ['type' => 'filter', 'location' => 'AuthController'],
    'auth.login.success'             => ['type' => 'action', 'location' => 'AuthController'],
    'auth.login.failed'              => ['type' => 'action', 'location' => 'AuthController'],
    'auth.logout'                    => ['type' => 'action', 'location' => 'AuthController'],
    'auth.session.started'           => ['type' => 'action', 'location' => 'SessionMiddleware'],
    'auth.2fa.required'              => ['type' => 'filter', 'location' => 'TwoFactorMiddleware'],
    'auth.permission.check'          => ['type' => 'filter', 'location' => 'PermissionMiddleware'],

    // ── Admin Panel ─────────────────────────────────────────────
    'admin.menu.register'            => ['type' => 'action', 'location' => 'Sidebar template'],
    'admin.head'                     => ['type' => 'action', 'location' => 'Admin base.twig <head>'],
    'admin.footer'                   => ['type' => 'action', 'location' => 'Admin base.twig </body>'],
    'admin.dashboard.widgets'        => ['type' => 'filter', 'location' => 'DashboardController'],
    'admin.dashboard.stats'          => ['type' => 'filter', 'location' => 'DashboardController'],
    'admin.page.before_render'       => ['type' => 'filter', 'location' => 'BaseController'],
    'admin.page.after_render'        => ['type' => 'filter', 'location' => 'BaseController'],
    'admin.settings.tabs'            => ['type' => 'filter', 'location' => 'SettingsController'],
    'admin.settings.save'            => ['type' => 'action', 'location' => 'SettingsController'],
    'admin.landing.render'           => ['type' => 'filter', 'location' => 'LandingController'],
    'admin.login.render'             => ['type' => 'filter', 'location' => 'AuthController'],

    // ── Payment & Transaction ───────────────────────────────────
    'payment.intent.created'         => ['type' => 'action', 'location' => 'PaymentService'],
    'payment.intent.expired'         => ['type' => 'action', 'location' => 'PaymentService'],
    'payment.transaction.before_create' => ['type' => 'filter', 'location' => 'TransactionService'],
    'payment.transaction.created'    => ['type' => 'action', 'location' => 'TransactionService'],
    'payment.transaction.completed'  => ['type' => 'action', 'location' => 'TransactionService'],
    'payment.transaction.failed'     => ['type' => 'action', 'location' => 'TransactionService'],
    'payment.transaction.cancelled'  => ['type' => 'action', 'location' => 'TransactionService'],
    'payment.transaction.refunded'   => ['type' => 'action', 'location' => 'RefundService'],
    'payment.amount.calculate'       => ['type' => 'filter', 'location' => 'PaymentService'],
    'payment.fee.calculate'          => ['type' => 'filter', 'location' => 'FeeService'],

    // ── Gateway & Checkout ──────────────────────────────────────
    'gateway.list'                   => ['type' => 'filter', 'location' => 'CheckoutController'],
    'gateway.manual.render'          => ['type' => 'filter', 'location' => 'ManualGatewayService'],
    'gateway.manual.verify'          => ['type' => 'filter', 'location' => 'ManualGatewayService'],
    'gateway.capture.before'         => ['type' => 'filter', 'location' => 'GatewayBridge'],
    'gateway.capture.after'          => ['type' => 'action', 'location' => 'GatewayBridge'],
    'gateway.webhook.received'       => ['type' => 'action', 'location' => 'WebhookController'],
    'checkout.page.data'             => ['type' => 'filter', 'location' => 'CheckoutController'],
    'checkout.before_render'         => ['type' => 'filter', 'location' => 'CheckoutController'],
    'checkout.after_render'          => ['type' => 'action', 'location' => 'CheckoutController'],
    'checkout.expired'               => ['type' => 'action', 'location' => 'CheckoutController'],

    // ── Invoice & Payment Link ──────────────────────────────────
    'invoice.created'                => ['type' => 'action', 'location' => 'InvoiceService'],
    'invoice.total'                  => ['type' => 'filter', 'location' => 'InvoiceService'],
    'invoice.paid'                   => ['type' => 'action', 'location' => 'InvoiceService'],
    'payment_link.created'           => ['type' => 'action', 'location' => 'PaymentLinkService'],
    'payment_link.used'              => ['type' => 'action', 'location' => 'PaymentLinkService'],

    // ── Customer ────────────────────────────────────────────────
    'customer.created'               => ['type' => 'action', 'location' => 'CustomerService'],
    'customer.updated'               => ['type' => 'action', 'location' => 'CustomerService'],
    'customer.deleted'               => ['type' => 'action', 'location' => 'CustomerService'],

    // ── Communication ───────────────────────────────────────────
    'communication.sms.send'         => ['type' => 'action', 'location' => 'CommunicationService'],
    'communication.mail.send'        => ['type' => 'action', 'location' => 'CommunicationService'],
    'communication.channels'         => ['type' => 'filter', 'location' => 'CommunicationService'],
    'communication.template.render'  => ['type' => 'filter', 'location' => 'CommunicationService'],

    // ── Mobile / SMS ────────────────────────────────────────────
    'mobile.device.paired'           => ['type' => 'action', 'location' => 'DevicePairingService'],
    'mobile.device.revoked'          => ['type' => 'action', 'location' => 'DevicePairingService'],
    'mobile.sms.received'            => ['type' => 'action', 'location' => 'SmsParserService'],
    'mobile.sms.parsed'              => ['type' => 'action', 'location' => 'SmsParserService'],
    'mobile.sms.matched'             => ['type' => 'action', 'location' => 'SmsVerificationJob'],
    'mfs.templates'                  => ['type' => 'filter', 'location' => 'SmsRegexParser'],

    // ── Webhook ─────────────────────────────────────────────────
    'webhook.created'                => ['type' => 'action', 'location' => 'WebhookService'],
    'webhook.delivery.success'       => ['type' => 'action', 'location' => 'WebhookService'],
    'webhook.delivery.failed'        => ['type' => 'action', 'location' => 'WebhookService'],

    // ── Plugin Meta ─────────────────────────────────────────────
    'plugin.installed'               => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.before_install'          => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.activated'               => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.before_activate'         => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.deactivated'             => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.before_deactivate'       => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.uninstalled'             => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.before_uninstall'        => ['type' => 'action', 'location' => 'PluginManager'],
    'plugin.settings.saved'          => ['type' => 'action', 'location' => 'PluginController'],
    'plugin.load_error'              => ['type' => 'action', 'location' => 'PluginLoader'],
    'plugin.boot_error'              => ['type' => 'action', 'location' => 'PluginLoader'],
    'plugins.before_load'            => ['type' => 'action', 'location' => 'PluginLoader'],
    'plugins.after_load'             => ['type' => 'action', 'location' => 'PluginLoader'],

    // ── Update System ───────────────────────────────────────────
    'update.available'               => ['type' => 'action', 'location' => 'UpdateService'],
    'update.before'                  => ['type' => 'action', 'location' => 'UpdateService'],
    'update.after'                   => ['type' => 'action', 'location' => 'UpdateService'],
    'update.failed'                  => ['type' => 'action', 'location' => 'UpdateService'],
    'update.rollback'                => ['type' => 'action', 'location' => 'UpdateService'],

    // ── Domain ──────────────────────────────────────────────────
    'domain.mapped'                  => ['type' => 'action', 'location' => 'DomainService'],
    'domain.verified'                => ['type' => 'action', 'location' => 'DomainService'],
    'domain.removed'                 => ['type' => 'action', 'location' => 'DomainService'],
    'domain.resolve'                 => ['type' => 'filter', 'location' => 'DomainMiddleware'],

    // ── Ledger & Audit ──────────────────────────────────────────
    'ledger.entry.created'           => ['type' => 'action', 'location' => 'LedgerService'],
    'audit.log.created'              => ['type' => 'action', 'location' => 'AuditLogger'],
    'report.data'                    => ['type' => 'filter', 'location' => 'ReportController'],
    'export.row'                     => ['type' => 'filter', 'location' => 'ExportService'],
];
