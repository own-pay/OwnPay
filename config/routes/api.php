<?php
declare(strict_types=1);

/**
 * API Routes — Unified routing for Merchant, Mobile, and Admin APIs.
 * OWASP: All routes require auth middleware. No wildcard CORS.
 * PCI: Payment routes never log/store card data.
 *
 * Route format: [method, path, controller::action, middleware[]]
 */

return [
    // ======================== MERCHANT API (Bearer Auth) ========================
    ['GET',  '/api/v1/health',                  'Api\HealthController::check',          ['api.throttle']],
    ['POST', '/api/v1/payments/initiate',        'Api\PaymentController::initiate',      ['api.auth', 'api.throttle']],
    ['GET',  '/api/v1/payments/{id}',            'Api\PaymentController::show',          ['api.auth', 'api.throttle']],
    ['GET',  '/api/v1/transactions',             'Api\TransactionController::index',     ['api.auth', 'api.throttle']],
    ['GET',  '/api/v1/transactions/{id}',        'Api\TransactionController::show',      ['api.auth', 'api.throttle']],
    ['POST', '/api/v1/refunds',                  'Api\RefundController::create',         ['api.auth', 'api.throttle']],
    ['GET',  '/api/v1/refunds/{id}',             'Api\RefundController::show',           ['api.auth', 'api.throttle']],
    ['GET',  '/api/v1/customers',                'Api\CustomerController::index',        ['api.auth', 'api.throttle']],
    ['GET',  '/api/v1/customers/{id}',           'Api\CustomerController::show',         ['api.auth', 'api.throttle']],
    ['POST', '/api/v1/customers',                'Api\CustomerController::create',       ['api.auth', 'api.throttle']],
    ['GET',  '/api/v1/api-keys',                 'Api\ApiKeyController::index',          ['api.auth', 'api.throttle']],
    ['POST', '/api/v1/api-keys',                 'Api\ApiKeyController::generate',       ['api.auth', 'api.throttle']],
    ['DELETE','/api/v1/api-keys/{id}',           'Api\ApiKeyController::revoke',         ['api.auth', 'api.throttle']],
    ['POST', '/api/v1/webhooks/test',            'Api\WebhookController::test',          ['api.auth', 'api.throttle']],
    ['GET',  '/api/v1/webhooks/deliveries',      'Api\WebhookController::deliveries',    ['api.auth', 'api.throttle']],

    // ======================== CSP REPORT ========================
    ['POST', '/csp-report',                      'Webhook\CspReportController::handle',   ['api.throttle']],

    // ======================== MOBILE API (JWT Auth) ========================
    ['POST', '/api/mobile/v1/devices/pair',      'Api\Mobile\DeviceController::pair',       ['api.throttle']],
    ['POST', '/api/mobile/v1/devices/heartbeat', 'Api\Mobile\DeviceController::heartbeat',  ['jwt.auth']],
    ['POST', '/api/mobile/v1/devices/revoke',    'Api\Mobile\DeviceController::revoke',      ['jwt.auth']],
    ['POST', '/api/mobile/v1/devices/bulk-revoke','Api\Mobile\DeviceController::bulkRevoke', ['jwt.auth']],
    ['POST', '/api/mobile/v1/sms',               'Api\Mobile\SmsController::receive',         ['jwt.auth']],
    ['GET',  '/api/mobile/v1/sms/queue',         'Api\Mobile\SmsController::queue',            ['jwt.auth']],
    ['GET',  '/api/mobile/v1/notifications',     'Api\Mobile\NotificationController::index',   ['jwt.auth']],
    ['POST', '/api/mobile/v1/notifications/ack', 'Api\Mobile\NotificationController::ack',     ['jwt.auth']],
    ['GET',  '/api/mobile/v1/dashboard',         'Api\Mobile\DashboardController::index',      ['jwt.auth']],

    // ======================== ADMIN API (Bearer Auth) ========================
    ['GET',  '/api/admin/v1/sms-templates',      'Api\Admin\SmsTemplateController::index',    ['api.auth', 'admin.role']],
    ['PUT',  '/api/admin/v1/sms-templates/{id}', 'Api\Admin\SmsTemplateController::update',   ['api.auth', 'admin.role']],
    ['GET',  '/api/admin/v1/sms-queue',          'Api\Admin\SmsQueueController::index',       ['api.auth', 'admin.role']],
    ['POST', '/api/admin/v1/sms-queue/{id}/retry','Api\Admin\SmsQueueController::retry',      ['api.auth', 'admin.role']],
    ['GET',  '/api/admin/v1/devices',            'Api\Admin\DeviceController::index',          ['api.auth', 'admin.role']],
    ['POST', '/api/admin/v1/devices/{id}/revoke','Api\Admin\DeviceController::revoke',         ['api.auth', 'admin.role']],
    ['POST', '/api/admin/v1/domains/verify',     'Api\Admin\DomainController::verify',         ['api.auth', 'admin.role']],
];
