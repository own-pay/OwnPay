<?php
declare(strict_types=1);

/**
 * OwnPay API Routing Configuration.
 *
 * This file registers all API endpoints for the OwnPay gateway platform.
 * It maps incoming HTTP request patterns to their respective API controllers,
 * grouping endpoints by functional domain:
 * 1. Merchant REST API: Authenticated via bearer API keys (scopes: read, write).
 * 2. Mobile Companion App API: Authenticated via JWT tokens.
 * 3. Administrative API: Authenticated via a bearer API key carrying the 'admin'
 *    scope. Actions are scoped to that key's own merchant_id (per-merchant admin),
 *    not a cross-merchant superadmin.
 *
 * @param \OwnPay\Http\Router $router The global application Router instance.
 * @return void
 */
return static function (\OwnPay\Http\Router $router): void {

    // ======================== MERCHANT API (Bearer Auth) ========================
    $router->get('/api/v1/health',                    'Api\\HealthController@check',            'api');
    $router->post('/api/v1/payments',                 'Api\\PaymentController@initiate',        'api');
    $router->get('/api/v1/payments/{payment_id}',     'Api\\PaymentController@show',            'api');
    $router->get('/api/v1/transactions',              'Api\\TransactionController@index',       'api');
    $router->get('/api/v1/transactions/{trx_id}',     'Api\\TransactionController@show',        'api');
    $router->post('/api/v1/refunds',                  'Api\\RefundController@create',           'api');
    $router->get('/api/v1/refunds',                   'Api\\RefundController@index',            'api');
    $router->get('/api/v1/refunds/{trx_id}',          'Api\\RefundController@show',             'api');
    $router->get('/api/v1/customers',                 'Api\\CustomerController@index',          'api');
    $router->get('/api/v1/customers/{identifier}',    'Api\\CustomerController@show',           'api');
    $router->post('/api/v1/customers',                'Api\\CustomerController@create',         'api');
    $router->get('/api/v1/api-keys',                  'Api\\ApiKeyController@index',            'api');
    $router->post('/api/v1/api-keys',                 'Api\\ApiKeyController@generate',         'api');
    $router->delete('/api/v1/api-keys/{id}',          'Api\\ApiKeyController@revoke',           'api');
    $router->post('/api/v1/webhooks/tests',           'Api\\WebhookController@test',            'api');
    $router->get('/api/v1/webhooks/deliveries',       'Api\\WebhookController@deliveries',      'api');

    // ======================== CSP REPORT ========================
    $router->post('/csp-report-api',                  'Webhook\\CspReportController@handle',    'api-public');

    // ======================== MOBILE API (JWT Auth) ========================
    $router->post('/api/mobile/v1/devices',            'Api\\Mobile\\DeviceController@pair',         'mobile-bootstrap');
    $router->post('/api/mobile/v1/devices/heartbeats', 'Api\\Mobile\\DeviceController@heartbeat',    'mobile');
    $router->delete('/api/mobile/v1/devices/{id}',     'Api\\Mobile\\DeviceController@revoke',       'mobile');
    $router->post('/api/mobile/v1/devices/bulk-revocations', 'Api\\Mobile\\DeviceController@bulkRevoke', 'mobile');
    $router->post('/api/mobile/v1/sms',                'Api\\Mobile\\SmsController@receive',         'mobile');
    $router->get('/api/mobile/v1/sms/queues',          'Api\\Mobile\\SmsController@queue',           'mobile');
    $router->get('/api/mobile/v1/notifications',       'Api\\Mobile\\NotificationController@index',  'mobile');
    $router->post('/api/mobile/v1/notifications/acknowledgements', 'Api\\Mobile\\NotificationController@ack', 'mobile');
    $router->get('/api/mobile/v1/dashboard',           'Api\\Mobile\\DashboardController@index',     'mobile');

    // Config / Filter Rules (mobile SMS privacy gate)
    $router->get('/api/mobile/v1/config/filter-rules', 'Api\\Mobile\\ConfigController@filterRules',   'mobile');

    // Device refresh + status
    $router->post('/api/mobile/v1/devices/token-refreshes', 'Api\\Mobile\\DeviceController@refresh',   'mobile-bootstrap');
    $router->get('/api/mobile/v1/devices/statuses',    'Api\\Mobile\\DeviceController@status',        'mobile');

    // ======================== ADMIN API (Bearer Auth) ========================
    $router->get('/api/admin/v1/sms-templates',         'Api\\Admin\\SmsTemplateController@index',   'admin-api');
    $router->put('/api/admin/v1/sms-templates/{id}',    'Api\\Admin\\SmsTemplateController@update',  'admin-api');
    $router->get('/api/admin/v1/sms-queues',            'Api\\Admin\\SmsQueueController@index',      'admin-api');
    $router->post('/api/admin/v1/sms-queues/{id}/retries', 'Api\\Admin\\SmsQueueController@retry',      'admin-api');
    $router->get('/api/admin/v1/devices',               'Api\\Admin\\DeviceController@index',         'admin-api');
    $router->delete('/api/admin/v1/devices/{id}',       'Api\\Admin\\DeviceController@revoke',        'admin-api');
    $router->post('/api/admin/v1/domains/verifications', 'Api\\Admin\\DomainController@verify',        'admin-api');
};

// Without discussion do not create new endpoint. Open a issue or discussation on github. /own-pay/ownpay