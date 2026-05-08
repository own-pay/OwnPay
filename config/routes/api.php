<?php
declare(strict_types=1);

/**
 * API Routes — Unified routing for Merchant, Mobile, and Admin APIs.
 * OWASP: All routes require auth middleware. No wildcard CORS.
 * PCI: Payment routes never log/store card data.
 *
 * @param \OwnPay\Http\Router $router
 */

return static function (\OwnPay\Http\Router $router): void {

    // ======================== MERCHANT API (Bearer Auth) ========================
    $router->get('/api/v1/health',                   'Api\\HealthController@check',            'api');
    $router->post('/api/v1/payments/initiate',        'Api\\PaymentController@initiate',        'api');
    $router->get('/api/v1/payments/{id}',             'Api\\PaymentController@show',            'api');
    $router->get('/api/v1/transactions',              'Api\\TransactionController@index',       'api');
    $router->get('/api/v1/transactions/{id}',         'Api\\TransactionController@show',        'api');
    $router->post('/api/v1/refunds',                  'Api\\RefundController@create',           'api');
    $router->get('/api/v1/refunds/{id}',              'Api\\RefundController@show',             'api');
    $router->get('/api/v1/customers',                 'Api\\CustomerController@index',          'api');
    $router->get('/api/v1/customers/{id}',            'Api\\CustomerController@show',           'api');
    $router->post('/api/v1/customers',                'Api\\CustomerController@create',         'api');
    $router->get('/api/v1/api-keys',                  'Api\\ApiKeyController@index',            'api');
    $router->post('/api/v1/api-keys',                 'Api\\ApiKeyController@generate',         'api');
    $router->post('/api/v1/api-keys/{id}/revoke',     'Api\\ApiKeyController@revoke',           'api');
    $router->post('/api/v1/webhooks/test',            'Api\\WebhookController@test',            'api');
    $router->get('/api/v1/webhooks/deliveries',       'Api\\WebhookController@deliveries',      'api');

    // ======================== CSP REPORT ========================
    $router->post('/csp-report-api',                  'Webhook\\CspReportController@handle',    'api');

    // ======================== MOBILE API (JWT Auth) ========================
    $router->post('/api/mobile/v1/devices/pair',       'Api\\Mobile\\DeviceController@pair',         'mobile');
    $router->post('/api/mobile/v1/devices/heartbeat',  'Api\\Mobile\\DeviceController@heartbeat',    'mobile');
    $router->post('/api/mobile/v1/devices/revoke',     'Api\\Mobile\\DeviceController@revoke',       'mobile');
    $router->post('/api/mobile/v1/devices/bulk-revoke','Api\\Mobile\\DeviceController@bulkRevoke',   'mobile');
    $router->post('/api/mobile/v1/sms',                'Api\\Mobile\\SmsController@receive',         'mobile');
    $router->get('/api/mobile/v1/sms/queue',           'Api\\Mobile\\SmsController@queue',           'mobile');
    $router->get('/api/mobile/v1/notifications',       'Api\\Mobile\\NotificationController@index',  'mobile');
    $router->post('/api/mobile/v1/notifications/ack',  'Api\\Mobile\\NotificationController@ack',    'mobile');
    $router->get('/api/mobile/v1/dashboard',           'Api\\Mobile\\DashboardController@index',     'mobile');

    // ======================== ADMIN API (Bearer Auth) ========================
    $router->get('/api/admin/v1/sms-templates',         'Api\\Admin\\SmsTemplateController@index',   'api');
    $router->put('/api/admin/v1/sms-templates/{id}',    'Api\\Admin\\SmsTemplateController@update',  'api');
    $router->get('/api/admin/v1/sms-queue',             'Api\\Admin\\SmsQueueController@index',      'api');
    $router->post('/api/admin/v1/sms-queue/{id}/retry', 'Api\\Admin\\SmsQueueController@retry',      'api');
    $router->get('/api/admin/v1/devices',               'Api\\Admin\\DeviceController@index',         'api');
    $router->post('/api/admin/v1/devices/{id}/revoke',  'Api\\Admin\\DeviceController@revoke',        'api');
    $router->post('/api/admin/v1/domains/verify',       'Api\\Admin\\DomainController@verify',        'api');
};
