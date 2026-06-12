# Findings

## Developer Endpoint UI Structure
- Controller responsible: [DeveloperController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/DeveloperController.php)
- Method: `DeveloperController::getEndpointReference(string $baseUrl)` lists the API endpoints shown on the developer tab.
- Template rendered: [index.twig](file:///c:/laragon/www/ownpay/templates/admin/developer/index.twig)
- Current hardcoded reference categories in `DeveloperController`:
  - `Health`
  - `Payments`
  - `Transactions`
  - `Refunds`
  - `Customers`
  - `API Keys`
  - `Webhooks`
  - `Mobile Device API`
  - `Webhooks (Incoming / IPN)`

## API Routes in `config/routes/api.php`

### Merchant API (Bearer Auth)
1. `GET /api/v1/health` -> `Api\HealthController@check` (auth: None/api)
2. `POST /api/v1/payments` -> `Api\PaymentController@initiate` (auth: Bearer) [Old was `/api/v1/payments/initiate`]
3. `GET /api/v1/payments/{trx_id}` -> `Api\PaymentController@show` (auth: Bearer) [Old path was `/api/v1/payments/{id}`]
4. `GET /api/v1/transactions` -> `Api\TransactionController@index` (auth: Bearer)
5. `GET /api/v1/transactions/{trx_id}` -> `Api\TransactionController@show` (auth: Bearer) [Old path was `/api/v1/transactions/{id}`]
6. `POST /api/v1/refunds` -> `Api\RefundController@create` (auth: Bearer)
7. `GET /api/v1/refunds/{trx_id}` -> `Api\RefundController@show` (auth: Bearer) [Old path was `/api/v1/refunds/{id}`]
8. `GET /api/v1/customers` -> `Api\CustomerController@index` (auth: Bearer)
9. `GET /api/v1/customers/{identifier}` -> `Api\CustomerController@show` (auth: Bearer) [Old path was `/api/v1/customers/{id}`]
10. `POST /api/v1/customers` -> `Api\CustomerController@create` (auth: Bearer)
11. `GET /api/v1/api-keys` -> `Api\ApiKeyController@index` (auth: Bearer)
12. `POST /api/v1/api-keys` -> `Api\ApiKeyController@generate` (auth: Bearer)
13. `DELETE /api/v1/api-keys/{id}` -> `Api\ApiKeyController@revoke` (auth: Bearer) [Old path was `/api/v1/api-keys/{id}/revoke` (POST)]
14. `POST /api/v1/webhooks/tests` -> `Api\WebhookController@test` (auth: Bearer) [Old path was `/api/v1/webhooks/test`]
15. `GET /api/v1/webhooks/deliveries` -> `Api\WebhookController@deliveries` (auth: Bearer)

### CSP Report (Public)
1. `POST /csp-report-api` -> `Webhook\CspReportController@handle` (auth: None)

### Mobile API (JWT Auth)
1. `POST /api/mobile/v1/devices` -> `Api\Mobile\DeviceController@pair` (auth: OTP) [Old path was `/api/mobile/v1/devices/pair`]
2. `POST /api/mobile/v1/devices/heartbeats` -> `Api\Mobile\DeviceController@heartbeat` (auth: JWT) [Old path was `/api/mobile/v1/devices/heartbeat`]
3. `DELETE /api/mobile/v1/devices/{id}` -> `Api\Mobile\DeviceController@revoke` (auth: JWT) [New]
4. `POST /api/mobile/v1/devices/bulk-revocations` -> `Api\Mobile\DeviceController@bulkRevoke` (auth: JWT) [New]
5. `POST /api/mobile/v1/sms` -> `Api\Mobile\SmsController@receive` (auth: JWT)
6. `GET /api/mobile/v1/sms/queues` -> `Api\Mobile\SmsController@queue` (auth: JWT) [New]
7. `GET /api/mobile/v1/notifications` -> `Api\Mobile\NotificationController@index` (auth: JWT)
8. `POST /api/mobile/v1/notifications/acknowledgements` -> `Api\Mobile\NotificationController@ack` (auth: JWT) [Old path was `/api/mobile/v1/notifications/ack`]
9. `GET /api/mobile/v1/dashboard` -> `Api\Mobile\DashboardController@index` (auth: JWT)
10. `GET /api/mobile/v1/config/filter-rules` -> `Api\Mobile\ConfigController@filterRules` (auth: JWT) [New]
11. `POST /api/mobile/v1/devices/token-refreshes` -> `Api\Mobile\DeviceController@refresh` (auth: OTP/JWT) [New]
12. `GET /api/mobile/v1/devices/statuses` -> `Api\Mobile\DeviceController@status` (auth: JWT) [New]

### Web Routes & Webhook/IPN
1. `POST /webhook/{gateway}` -> `Webhook\UnifiedWebhookController@handle` (auth: Sig)

### Template Details in `index.twig`
- Under the "Authentication" tab:
  - Mention of `POST /api/mobile/v1/devices/pair` is outdated (should be `/api/mobile/v1/devices`).
  - Mention of `POST /api/mobile/v1/devices/heartbeat` is outdated (should be `/api/mobile/v1/devices/heartbeats`).
- Under the "Rate Limits" tab:
  - Reference to `Settings → API tab` (web.php is `/admin/settings` or `/admin/settings/{tab}`).
- In the CSS or layout:
  - Standard variables like `{{ base_url }}` are passed.
