# Task Plan: OwnPay API Refactor & Hardening

## Goal
To refactor the OwnPay REST and Mobile companion APIs to be strictly REST-compliant, enfolding standard data/error envelopes, and secure them with robust production-ready deployment guardrails.

## Current Phase
Phase 5

## Phases

### Phase 1: Core HTTP & Routing Refactor
- [x] Add `apiSuccess`, `apiError`, and `apiErrors` helpers to [Response.php](file:///c:/laragon/www/ownpay/src/Http/Response.php)
- [x] Align routes to noun-based paths in [api.php](file:///c:/laragon/www/ownpay/config/routes/api.php)
- **Status:** complete

### Phase 2: Controller Alignment (Merchant REST endpoints)
- [x] Refactor [HealthController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/HealthController.php)
- [x] Refactor [PaymentController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/PaymentController.php)
- [x] Refactor [TransactionController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/TransactionController.php)
- [x] Refactor [RefundController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/RefundController.php)
- [x] Refactor [CustomerController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/CustomerController.php)
- [x] Refactor [ApiKeyController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/ApiKeyController.php)
- [x] Refactor [WebhookController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/WebhookController.php)
- **Status:** complete

### Phase 3: Controller Alignment (Mobile companion endpoints)
- [x] Refactor [DeviceController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/DeviceController.php)
- [x] Refactor [SmsController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/SmsController.php)
- [x] Refactor [NotificationController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/NotificationController.php)
- [x] Refactor [DashboardController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/DashboardController.php)
- [x] Refactor [ConfigController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Mobile/ConfigController.php)
- **Status:** complete

### Phase 4: Controller Alignment (Administrative endpoints)
- [x] Refactor [SmsTemplateController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/SmsTemplateController.php)
- [x] Refactor [SmsQueueController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/SmsQueueController.php)
- [x] Refactor [DeviceController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/DeviceController.php)
- [x] Refactor [DomainController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/Admin/DomainController.php)
- **Status:** complete

### Phase 5: Production Hardening & Verification
- [x] Verify Rate Limiting across public API channels
- [x] Verify CORS policy targets
- [x] Verify Security Headers middleware output
- [x] Execute static analysis type checking (`phpstan`)
- [x] Execute full PHPUnit automated integration and business tests (`phpunit`)
- [x] Update OpenAPI v3.2.0 specification (`docs/v2/api/openapi.yaml`)
- [x] Update developer API reference guide (`docs/v2/api/README.md`)
- [x] Update Mobile App plans (`plan.md`, `flutter_plan.md`, `todo.md` in `docs/v2/mobile_app/`)
- [x] Synchronize actual API Key prefix formatting (`op_` format) across all documentation files
- [x] Rebuild `public/api-tester.php` as a premium, highly responsive light-themed single-page app
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Standard Response Envelope | Wraps success data in `"data"`, pagination parameters in `"meta"`, and error details in a structured `"errors"` object. |

## Errors Encountered
| Error | Resolution |
|-------|------------|

