# Findings & Decisions

## Codebase Catalog

### 1. Route Configuration Files
* **Route Manifest:** [api.php](file:///c:/laragon/www/ownpay/config/routes/api.php) - Governs all REST API and mobile app companion routes.

### 2. API Controllers
* **Merchant REST Controllers (Bearer Auth):**
  * [HealthController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/HealthController.php) - Performs system health and diagnostics checks.
  * [PaymentController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/PaymentController.php) - Processes payment initiations and intent status checks.
  * [TransactionController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/TransactionController.php) - Handles transaction queries and safe lists.
  * [RefundController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/RefundController.php) - Creates and queries transaction refunds.
  * [CustomerController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/CustomerController.php) - Manages customer lookup and creation.
  * [ApiKeyController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/ApiKeyController.php) - Lists, generates, and revokes API keys.
  * [WebhookController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/WebhookController.php) - Dispatches webhook tests and retrieves history.
* **Mobile Companion Controllers (JWT Auth):**
  * [DeviceController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/DeviceController.php) - Handles mobile device pairing, heartbeats, status, and revocation.
  * [SmsController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/SmsController.php) - Ingests message payloads and serves pending outbound messages.
  * [NotificationController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/NotificationController.php) - Lists and acknowledges push notifications.
  * [DashboardController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/DashboardController.php) - Prepares consolidated metrics.
  * [ConfigController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/ConfigController.php) - Serves client configuration filters.
* **Administrative REST Controllers (Bearer Session/Auth):**
  * [SmsTemplateController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/SmsTemplateController.php) - Manages SMS template formats.
  * [SmsQueueController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/SmsQueueController.php) - Retrieves and retries outbound SMS.
  * [DeviceController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/DeviceController.php) - Audits and revokes active devices globally.
  * [DomainController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/DomainController.php) - Triggers brand domain verifications.

### 3. Middleware Pipeline (API-facing)
* **Configuration:** [middleware.php](file:///c:/laragon/www/ownpay/config/middleware.php)
* [CorsMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/CorsMiddleware.php) - Sets explicit CORS headers.
* [BearerAuthMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/BearerAuthMiddleware.php) - Enforces API key header resolution and scopes.
* [JwtAuthMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/JwtAuthMiddleware.php) - Enforces valid JWT token presentation and device checks.
* [RateLimiterMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/RateLimiterMiddleware.php) - Prevents credential brute forcing and endpoint spamming.
* [SecurityHeadersMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/SecurityHeadersMiddleware.php) - Appends CSP, HSTS, and Frame boundaries.

### 4. Database Repositories
* [TransactionRepository.php](file:///c:/laragon/www/ownpay/src/Repository/TransactionRepository.php)
* [PairedDeviceRepository.php](file:///c:/laragon/www/ownpay/src/Repository/PairedDeviceRepository.php)
* [MobileNotificationRepository.php](file:///c:/laragon/www/ownpay/src/Repository/MobileNotificationRepository.php)
* [CommLogRepository.php](file:///c:/laragon/www/ownpay/src/Repository/CommLogRepository.php)

### 5. Services & Business Logic Layers
* [CustomerPiiService.php](file:///c:/laragon/www/ownpay/src/Service/Customer/CustomerPiiService.php)
* [ApiKeyService.php](file:///c:/laragon/www/ownpay/src/Service/Customer/ApiKeyService.php)
* [PaymentService.php](file:///c:/laragon/www/ownpay/src/Service/Payment/PaymentService.php)
* [RefundService.php](file:///c:/laragon/www/ownpay/src/Service/Payment/RefundService.php)
* [DevicePairingService.php](file:///c:/laragon/www/ownpay/src/Service/Device/DevicePairingService.php)
* [SmsParserService.php](file:///c:/laragon/www/ownpay/src/Service/Sms/SmsParserService.php)
* [WebhookDispatcher.php](file:///c:/laragon/www/ownpay/src/Service/Notification/WebhookDispatcher.php)

---

## Technical Decisions
| Decision | Rationale |
|----------|-----------|

## Issues Encountered
| Issue | Resolution |
|-------|------------|

