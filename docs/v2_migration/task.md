# Own Pay v0.1.0 — Task Tracker (Rev 3)

> `[ ]` pending | `[/]` in progress | `[x]` done

---

## Phase A: Foundation (Core Framework)

- [x] A1. Create full directory structure per plan §3
- [x] A2. Build `src/Container.php` — PSR-11 DI container
- [x] A3. Build `config/app.php` — Version, timezone, debug, paths
- [x] A4. Build `config/database.php` — DB from .env
- [x] A5. Build `config/services.php` — All DI bindings
- [x] A6. Build `config/middleware.php` — Pipeline order
- [x] A7. Build `config/hooks.php` — 80+ core hook registration points
- [x] A8. Build `config/routes/web.php` — Admin + checkout + landing + login
- [x] A9. Build `config/routes/api.php` — REST API v1
- [x] A10. Build `src/Kernel.php` — Boot: .env → DI → middleware → plugins → dispatch
- [x] A11. Build `public/index.php` — Single front controller
- [x] A12. Build root `.htaccess` + `public/.htaccess`
- [x] A13. Build `src/Core/Database.php` — Constructor-injected PDO
- [x] A14. Build `src/Http/Request.php` — Immutable request
- [x] A15. Build `src/Http/Response.php` — HTML + JSON + redirect
- [x] A16. Build `src/Http/Router.php` — Unified: web + API + plugin route injection via `system.routes.register` hook
- [x] A17. Build `src/Controller/BaseController.php` — DI + Twig rendering
- [x] A18. Integrate Twig 3.x — composer require, configure
- [x] A19. Build Twig extensions — `csrf_token()`, `asset()`, `route()`, `env()`, `hook()`, `money()`, `datetime()`
- [x] A20. Build `src/Cache/CacheInterface.php`
- [x] A21. Build `src/Cache/FileCache.php`
- [x] A22. Build `src/Cache/RedisCache.php`
- [x] A23. Build `src/Queue/QueueInterface.php`
- [x] A24. Build `src/Queue/FileQueue.php`
- [x] A25. Build `src/Queue/RedisQueue.php`
- [x] A26. Update `composer.json`
- [x] A27. Create `.env.example`
- [x] A28. Build `src/View/TwigFactory.php` — Template loader with plugin path injection

## Phase B: Database & Data Layer

- [x] B1. Design `database/schema.sql` — Unified ~48 tables
- [x] B2. Add `op_manual_gateways` table
- [x] B3. Add `op_queue_jobs` table
- [x] B4. Add `op_cache` table
- [x] B5. Add `op_comm_log` table
- [x] B6. Add `op_plugin_settings` table
- [x] B7. Add `op_update_history` table (NEW Rev 3)
- [x] B8. Add `op_maintenance_locks` table (NEW Rev 3)
- [x] B9. Enhance `op_domains` table — dns_verified, ssl_status, redirect_url fields (NEW Rev 3)
- [x] B10. Create `database/seeds/roles.sql`
- [x] B11. Create `database/seeds/currencies.sql`
- [x] B12. Create `database/seeds/sms_templates.sql`
- [x] B13. Create `database/seeds/system_settings.sql`
- [x] B14. Port `BaseRepository.php` — Constructor-injected PDO
- [x] B15. Port `TenantScope.php` trait
- [x] B16. Port all 22 repositories (remove CrudService)
  - [x] B16a. MerchantRepository
  - [x] B16b. TransactionRepository
  - [x] B16c. CustomerRepository
  - [x] B16d. ApiKeyRepository
  - [x] B16e. AuditLogRepository
  - [x] B16f. DevicePairingTokenRepository
  - [x] B16g. DisputeRepository
  - [x] B16h. GatewayConfigRepository
  - [x] B16i. IdempotencyRepository
  - [x] B16j. LedgerRepository
  - [x] B16k. MobileNotificationRepository
  - [x] B16l. PairedDeviceRepository
  - [x] B16m. PaymentIntentRepository
  - [x] B16n. RateLimitRepository
  - [x] B16o. RefundRepository
  - [x] B16p. SettlementRepository
  - [x] B16q. SmsDataRepository
  - [x] B16r. SmsTemplateRepository
  - [x] B16s. WebhookEventRepository
  - [x] B16t. WebhookRepository
- [x] B17. Build `ManualGatewayRepository`
- [x] B18. Build `CommLogRepository`
- [x] B19. Build `DomainRepository` (NEW Rev 3)
- [x] B20. Build `UpdateHistoryRepository` (NEW Rev 3)
- [x] B21. Delete `CrudService.php`

## Phase C: Security & Middleware

- [x] C1. Port `SessionMiddleware.php`
- [x] C2. Port `CsrfMiddleware.php`
- [x] C3. Port `BearerAuthMiddleware.php`
- [x] C4. Port `CorsMiddleware.php`
- [x] C5. Port `RateLimiterMiddleware.php` — DB or Redis auto-detect
- [x] C6. Port `IpAllowlistMiddleware.php`
- [x] C7. Port `JwtAuthMiddleware.php`
- [x] C8. Port `RequestSignatureMiddleware.php`
- [x] C9. Port `PermissionMiddleware.php` — fire `auth.permission.check` filter
- [x] C10. Port `TwoFactorMiddleware.php` — fire `auth.2fa.required` filter
- [x] C11. Build `SecurityHeadersMiddleware.php`
- [x] C12. Build `MaintenanceMiddleware.php` (NEW Rev 3) — 503 JSON/HTML response
- [x] C13. Build `DomainMiddleware.php` (NEW Rev 3) — Custom domain resolution
- [x] C14. Port `Security/Authenticator.php`
- [x] C15. Port `Security/FieldEncryptor.php`
- [x] C16. Port `Security/PiiMasker.php`
- [x] C17. Port `Security/LogSanitizer.php`
- [x] C18. Port `Security/SecurityHelpers.php`
- [x] C19. Port `Security/UrlValidator.php`

## Phase D: Plugin Ecosystem

- [x] D1. Port `Event/EventManager.php` — 80+ hooks registered
- [x] D2. Port `Plugin/PluginInterface.php`
- [x] D3. Port `Plugin/Capability.php` — Add COMMUNICATION capability
- [x] D4. Port `Plugin/PluginManifest.php`
- [x] D5. Port `Plugin/PluginLoader.php`
- [x] D6. Port `Plugin/PluginRegistry.php`
- [x] D7. Port `Plugin/PluginInstaller.php`
- [x] D8. Port `Plugin/PluginSandbox.php`
- [x] D9. Port `Plugin/PluginMigrator.php`
- [x] D10. Port `Plugin/PluginManager.php`
- [x] D11. Build UI-agnostic view renderer (.twig first → .php fallback)
- [x] D12. Build plugin settings auto-renderer from `fields()`
- [x] D13. Build `templates/admin/plugins/index.twig` — WordPress-style list
- [x] D14. Build `templates/admin/plugins/install.twig` — ZIP drag & drop
- [x] D15. Build `templates/admin/plugins/settings.twig`
- [x] D16. Build `templates/admin/themes/index.twig` — Grid + preview
- [x] D17. Build `templates/admin/addons/index.twig`
- [x] D18. Build `Controller/Admin/PluginController.php`
- [x] D19. Build `Controller/Admin/ThemeController.php`
- [x] D20. Build `Controller/Admin/AddonController.php`
- [x] D21. Register all 80+ hook points in `config/hooks.php`
- [x] D22. Write hook documentation: `docs/plugins/hooks-reference.md`

## Phase E: Service Layer

### Auth
- [x] E1. Port `Service/Auth/AuthSessionService.php` — fire `auth.login.*` hooks
- [x] E2. Port `Service/Auth/JwtService.php`
- [x] E3. Port `Service/Auth/PermissionGuard.php`
- [x] E4. Port `Service/Auth/PermissionService.php`
- [x] E5. Port `Service/Auth/StatusGuard.php`

### Payment
- [x] E6. Port `Service/Payment/PaymentService.php` — fire `payment.intent.*`, `payment.amount.calculate` hooks
- [x] E7. Port `Service/Payment/TransactionService.php` — fire `payment.transaction.*` hooks
- [x] E8. Port `Service/Payment/CurrencyService.php`
- [x] E9. Port `Service/Payment/LedgerService.php` — fire `ledger.entry.created`
- [x] E10. Port `Service/Payment/MfsService.php`
- [x] E11. Port `Service/Payment/IdempotencyService.php`
- [x] E12. Port `Service/Payment/IdempotencyBridge.php`
- [x] E13. Port `Service/Payment/ReconciliationService.php`
- [x] E14. Port `Service/Payment/SettlementService.php`
- [x] E15. Port `Service/Payment/DisputeService.php`
- [x] E16. Port `Service/Payment/WebhookService.php` — fire `webhook.*` hooks
- [x] E17. Port `Service/Payment/GatewayApiService.php`
- [x] E18. Port `Service/Payment/GatewayRendererService.php`
- [x] E19. Port `Service/Payment/FeeService.php` — fire `payment.fee.calculate`

### SMS
- [x] E20. Port `Service/Sms/SmsParserService.php` — fire `mobile.sms.*` hooks
- [x] E21. Port `Service/Sms/SmsRegexParser.php` — fire `mfs.templates` filter
- [x] E22. Port `Service/Sms/SmsHeuristicParser.php`

### Device & Notification
- [x] E23. Port `Service/Device/DevicePairingService.php` — fire `mobile.device.*` hooks
- [x] E24. Port `Service/Notification/MobileNotificationService.php`
- [x] E25. Port `Service/Notification/AlertService.php`
- [x] E26. Port `Service/Notification/NotificationService.php`

### Customer
- [x] E27. Port `Service/Customer/CustomerPiiService.php` — fire `customer.*` hooks
- [x] E28. Port `Service/Customer/ApiKeyService.php`

### Communication (NEW)
- [x] E29. Build `Service/Communication/CommunicationService.php` — dispatch via `communication.sms.send` / `communication.mail.send` hooks
- [x] E30. Build `Service/Communication/SmsProviderInterface.php`
- [x] E31. Build `Service/Communication/MailProviderInterface.php`

### Gateway
- [x] E32. Port `Gateway/GatewayAdapterInterface.php`
- [x] E33. Port `Gateway/GatewayBridge.php` — fire `gateway.capture.*` hooks
- [x] E34. Port `Gateway/GatewayDefaults.php`
- [x] E35. Port `Gateway/WebhookInboundProcessor.php` — fire `gateway.webhook.received`
- [x] E36. Build `Service/Gateway/ManualGatewayService.php` — fire `gateway.manual.*` hooks

### Domain (NEW Rev 3)
- [x] E37. Build `Service/Domain/DomainService.php` — CRUD, DNS verify, URL generation
- [x] E38. Build `Service/Domain/DnsVerifier.php` — A record checker
- [x] E39. Build `Cron/DnsVerificationJob.php` — Every 6h for pending domains

### Update (NEW Rev 3)
- [x] E40. Build `Update/UpdateService.php` — Full 9-step flow with rollback
- [x] E41. Build `Update/BackupService.php` — DB dump + code ZIP
- [x] E42. Build `Update/HealthChecker.php` — Post-update health ping
- [x] E43. Build `Update/MaintenanceMode.php` — Enter/exit maintenance
- [x] E44. Build `Cron/UpdateCheckJob.php` — Daily check + night window trigger

### System
- [x] E45. Port `Service/System/EnvironmentService.php`
- [x] E46. Port `Service/System/DateTimeService.php`
- [x] E47. Port `Service/System/FilesystemService.php`
- [x] E48. Port `Service/System/HttpClient.php`
- [x] E49. Port `Service/System/ImageService.php`
- [x] E50. Port `Service/System/Logger.php`
- [x] E51. Port `Service/System/PaginationService.php`
- [x] E52. Port `Service/System/PdfService.php`
- [x] E53. Port `Service/System/InputSanitizer.php`
- [x] E54. Port `Service/System/AuditLogger.php` — fire `audit.log.created`
- [x] E55. Delete old `UpdaterService.php` (replaced by Update/ module)

### Cron
- [x] E56. Port `Cron/CronJobRunner.php`
- [x] E57. Port `Cron/SmsVerificationJob.php` — fire `mobile.sms.matched`
- [x] E58. Port `Cron/CurrencyUpdateJob.php`
- [x] E59. Port `Cron/BalanceVerificationJob.php`
- [x] E60. Port `Cron/WebhookRetryJob.php`
- [x] E61. Build `Cron/QueueWorkerJob.php`

## Phase F: Gateway System

- [x] F1. Build admin: `templates/admin/gateways/index.twig`
- [x] F2. Build admin: `templates/admin/gateways/create-manual.twig`
- [x] F3. Build admin: `templates/admin/gateways/edit-manual.twig`
- [x] F4. Build `Controller/Admin/GatewayController.php`
- [x] F5. Build checkout renderer for manual gateways — dynamic from JSON
- [x] F6. SMS verification toggle per gateway
- [x] F7. Build `modules/gateways/stripe/` — PluginInterface
- [x] F8. Build `modules/gateways/sslcommerz/`
- [x] F9. Build `modules/gateways/bkash-api/`

## Phase G: Admin Panel

### Layout
- [x] G1. Build `templates/admin/layout/base.twig` — fire `admin.head`, `admin.footer` hooks
- [x] G2. Build `templates/admin/layout/navbar.twig`
- [x] G3. Build `templates/admin/layout/sidebar.twig` — fire `admin.menu.register` hook
- [x] G4. Build `templates/admin/layout/footer.twig`
- [x] G5. Build `templates/admin/layout/modals.twig`

### Pages
- [x] G6. Build `templates/admin/dashboard.twig` — fire `admin.dashboard.*` hooks
- [x] G7. Build `templates/admin/transactions/index.twig` + `edit.twig`
- [x] G8. Build `templates/admin/invoices/` — index, create, edit
- [x] G9. Build `templates/admin/payment-links/` — index, create, edit
- [x] G10. Build `templates/admin/customers.twig`
- [x] G11. Build `templates/admin/merchants/` — index, create, edit, domain tab (NEW Rev 3)
- [x] G12. Build `templates/admin/staff/` — index, create, edit
- [x] G13. Build `templates/admin/settings/` — general, API, currency, FAQ, themes — fire `admin.settings.*` hooks
- [x] G14. Build `templates/admin/sms-center/` — index, templates, queue
- [x] G15. Build `templates/admin/devices/index.twig`
- [x] G16. Build `templates/admin/domains/index.twig` — DNS verify UI (NEW Rev 3)
- [x] G17. Build `templates/admin/reports.twig` — fire `report.data` filter
- [x] G18. Build `templates/admin/sms-data.twig`
- [x] G19. Build `templates/admin/activities.twig`
- [x] G20. Build `templates/admin/my-account.twig`
- [x] G21. Build `templates/admin/system-update.twig` — Update UI with history (NEW Rev 3)

### Auth Pages
- [x] G22. Build `templates/page/landing.twig` — Customizable, fire `admin.landing.render`
- [x] G23. Build `templates/page/login.twig` — Custom URL, fire `admin.login.render`
- [x] G24. Build `templates/page/forgot.twig`
- [x] G25. Build `templates/page/2fa.twig`

### Controllers (DI, no globals)
- [x] G26. Port `Controller/Admin/AuthController.php` — fire `auth.*` hooks
- [x] G27. Port `Controller/Admin/DashboardController.php`
- [x] G28. Port `Controller/Admin/TransactionController.php`
- [x] G29. Port `Controller/Admin/InvoiceController.php` — fire `invoice.*` hooks
- [x] G30. Port `Controller/Admin/PaymentLinkController.php` — fire `payment_link.*` hooks
- [x] G31. Port `Controller/Admin/CustomerController.php`
- [x] G32. Port `Controller/Admin/CurrencyController.php`
- [x] G33. Port `Controller/Admin/StaffController.php`
- [x] G34. Port `Controller/Admin/MerchantController.php` (rename from Brand)
- [x] G35. Port `Controller/Admin/SettingsController.php`
- [x] G36. Port `Controller/Admin/ApiKeyController.php`
- [x] G37. Port `Controller/Admin/SmsDataController.php`
- [x] G38. Port `Controller/Admin/SmsTemplateAdminController.php`
- [x] G39. Port `Controller/Admin/BalanceVerificationController.php`
- [x] G40. Port `Controller/Admin/DeviceController.php`
- [x] G41. Port `Controller/Admin/DomainController.php` — DNS verify, custom domain CRUD (enhanced Rev 3)
- [x] G42. Port `Controller/Admin/FaqController.php`
- [x] G43. Build `Controller/Admin/SystemUpdateController.php` (NEW Rev 3)
- [x] G44. Build `Controller/Page/LandingController.php` (NEW)
- [x] G45. Build `Controller/Page/LoginController.php` (NEW)

### Frontend Assets
- [x] G46. Rewrite `app.js` — SPA, `op` prefix
- [x] G47. Rewrite `op-fetch.js` — AJAX + CSRF
- [x] G48. Build Tailwind CLI pipeline
- [x] G49. Rebuild `admin.css`
- [x] G50. Port `ContentLoader.php` → Twig SPA fragment renderer

### Error Pages
- [x] G51. Build `templates/error/404.twig`
- [x] G52. Build `templates/error/503.twig` — Maintenance mode page (Rev 3)
- [x] G53. Build `templates/error/maintenance.twig`

## Phase H: API Layer

- [x] H1. Unify routes into `config/routes/api.php`

### Merchant API (Bearer Auth)
- [x] H2. Port `PaymentController.php`
- [x] H3. Port `TransactionController.php`
- [x] H4. Port `RefundController.php`
- [x] H5. Port `CustomerController.php`
- [x] H6. Port `ApiKeyController.php`
- [x] H7. Port `WebhookController.php`
- [x] H8. Port `HealthController.php`

### Mobile API (JWT)
- [x] H9. Port `MobileDeviceController.php`
- [x] H10. Port `MobileSmsController.php`
- [x] H11. Port `MobileNotificationController.php`
- [x] H12. Port `MobileDashboardController.php`
- [x] H13. Add bulk device revoke endpoint
- [x] H14. Add `X-API-Version` header

### Admin API (Bearer Auth)
- [x] H15. Port `AdminSmsTemplateController.php`
- [x] H16. Port `AdminSmsQueueController.php`
- [x] H17. Port `AdminDeviceController.php`

### Legacy Merge
- [x] H18. Merge `ApiController.php` → unified routes
- [x] H19. Merge `CompanionApiController.php` → unified routes
- [x] H20. Port `IpnController.php` → `Controller/Webhook/`
- [x] H21. Port `CspReportController.php`
- [x] H22. Build `Controller/Api/DomainController.php` — DNS verify API (NEW Rev 3)

## Phase I: Checkout & Theme (Rev 3 — v2 HTML Integration)

- [x] I1. Port `Controller/Checkout/CheckoutController.php` — fire `checkout.*` hooks
- [x] I2. Port `Controller/Checkout/PaymentCheckoutController.php`
- [x] I3. Port `Controller/Checkout/InvoiceCheckoutController.php`
- [x] I4. Port `Controller/Checkout/PaymentLinkCheckoutController.php`
- [x] I5. Decompose `Own_pay_checkout_ui_v2.html` → Twig partials (per plan §5.2):
  - [x] I5a. `checkout.twig` — main page
  - [x] I5b. `checkout-status.twig` — post-transaction
  - [x] I5c. `_left-panel.twig` — order summary
  - [x] I5d. `_mobile-summary.twig`
  - [x] I5e. `_top-bar.twig` — timer + actions
  - [x] I5f. `_gateway-tabs.twig`
  - [x] I5g. `_gateway-grid.twig` — dynamic from DB
  - [x] I5h. `_manual-popup.twig` — manual payment overlay
  - [x] I5i. `_express-checkout.twig`
  - [x] I5j. `_modals.twig`
  - [x] I5k. `_footer.twig`
  - [x] I5l. Status partials: `_success.twig`, `_failed.twig`, `_cancelled.twig`, `_pending.twig`, `_expired.twig`
- [x] I6. Extract `checkout.css` — preserve CSS custom properties
- [x] I7. Extract `checkout.js` — timer, gateway picker, manual flow, state engine
- [x] I8. Build `modules/themes/own-pay/Theme.php` — implements PluginInterface
- [x] I9. Build `modules/themes/own-pay/manifest.json`
- [x] I10. Bind dynamic data: merchant, amount, gateways from DB, manual steps from JSON, FAQ from settings
- [x] I11. Custom domain integration — checkout URLs use merchant's mapped domain

## Phase J: Built-in Addons

### SMS Gateway
- [x] J1. `modules/addons/sms-gateway/manifest.json`
- [x] J2. `modules/addons/sms-gateway/Plugin.php`
- [x] J3. SMS send via HTTP API (Twilio, custom)
- [x] J4. Template configuration
- [x] J5. Admin settings page

### Mail Gateway
- [x] J6. `modules/addons/mail-gateway/manifest.json`
- [x] J7. `modules/addons/mail-gateway/Plugin.php`
- [x] J8. SMTP + API (Mailgun, SendGrid)
- [x] J9. Twig email templates
- [x] J10. Admin settings page

### Telegram Bot (POC)
- [x] J11. `modules/addons/telegram-bot/manifest.json`
- [x] J12. `modules/addons/telegram-bot/Plugin.php`
- [x] J13. Transaction alerts via `payment.transaction.completed` hook
- [x] J14. Commands: `/status`, `/today`, `/recent`
- [x] J15. Webhook route: `/plugins/telegram-bot/webhook`
- [x] J16. Admin settings: token, chat ID, toggles

## Phase K: Installer

- [x] K1. Enterprise-grade multi-step Twig wizard
- [x] K2. Step 1: Requirements check
- [x] K3. Step 2: DB config + test
- [x] K4. Step 3: Admin account
- [x] K5. Step 4: Initial settings (name, currency, timezone)
- [x] K6. Generate `.env`
- [x] K7. Import schema + seeds
- [x] K8. Seed super admin
- [x] K9. `.installed` lockout marker
- [x] K10. Activate built-in addons

## Phase L: Testing & QA

- [x] L1. Unit: Container/DI
- [x] L2. Unit: Router
- [x] L3. Unit: Security classes
- [x] L4. Unit: All middleware
- [x] L5. Unit: EventManager + 80+ hooks
- [x] L6. Unit: Plugin system (Manifest, Sandbox, Migrator)
- [x] L7. Unit: PaymentService, LedgerService, TransactionService
- [x] L8. Unit: SmsParser pipeline
- [x] L9. Unit: ManualGatewayService
- [x] L10. Unit: CommunicationService
- [x] L11. Unit: DomainService + DnsVerifier (NEW Rev 3)
- [x] L12. Unit: UpdateService + BackupService + HealthChecker (NEW Rev 3)
- [x] L13. Integration: Auth flow
- [x] L14. Integration: Payment flow
- [x] L15. Integration: SMS parsing
- [x] L16. Integration: Plugin lifecycle
- [x] L17. Integration: Manual gateway checkout
- [x] L18. Integration: Mobile API
- [x] L19. Integration: Custom domain resolution (NEW Rev 3)
- [x] L20. Integration: Auto-update + rollback (NEW Rev 3)
- [x] L21. Smoke test: fresh install → login → payment → complete
- [x] L22. PHPStan level 6
- [x] L23. Responsive: mobile/tablet/desktop
- [x] L24. Shared hosting simulation

## Phase M: Branding Sweep & Cleanup

- [x] M1. `grep -r` for `piprapay`, `pipra`, `anirban`, `pp_`, `ap_`, `AP_`
- [x] M2. Update `composer.json`
- [x] M3. Update `CLAUDE.md` for v0.1.0
- [x] M4. Update `README.md`
- [x] M5. Update `phpunit.xml`
- [x] M6. Rename cookie `apTheme` → `op_theme`
- [x] M7. Rename JS `ap` prefixes → `op`
- [x] M8. Clean test files
- [x] M9. Update CI workflows
- [x] M10. Delete legacy files:
  - [x] M10a. Old `index.php`
  - [x] M10b. `app/admin/`
  - [x] M10c. `app/modules/gateways/` (46 legacy)
  - [x] M10d. `app/modules/themes/`
  - [x] M10e. `app/modules/plugins/`
  - [x] M10f. `app/install/`
  - [x] M10g. `errors/`
  - [x] M10h. `scripts/`
  - [x] M10i. `migrations/`
  - [x] M10j. `src/Core/helpers.php`
  - [x] M10k. `src/Core/ActionDispatcher.php`
  - [x] M10l. `src/Core/ContentLoader.php`
  - [x] M10m. `src/Service/System/CrudService.php`
  - [x] M10n. `src/Service/System/UpdaterService.php` (replaced by Update/)
- [x] M11. Update `.gitignore`
- [x] M12. Final `grep -r` verification
- [x] M13. Write `docs/plugins/hooks-reference.md` — Complete hook documentation
- [x] M14. Write `docs/plugins/developer-guide.md` — Updated for v0.1.0
- [x] M15. Tag release `v0.1.0`

## Phase N: Unified Dynamic Webhook/IPN Architecture

### Inbound (Gateway → Own Pay)
- [x] N1. Create `src/Model/WebhookPayload.php` — immutable value object (gateway, merchantId, rawBody, headers, ip)
- [x] N2. Create `src/Controller/Webhook/UnifiedWebhookController.php` — single `handle()` method, fires `webhook.incoming.{gateway}` hook
- [x] N3. Remove hardcoded routes from `api.php` (`/webhook/stripe`, `/webhook/sslcommerz`, `/webhook/bkash`)
- [x] N4. Clean `web.php` — remove `/ipn/{gateway}` alias, keep only `POST /webhook/{gateway}`
- [x] N5. Delete `src/Controller/Webhook/IpnController.php` — replaced by UnifiedWebhookController
- [x] N6. Add `DomainResolverMiddleware` to webhook middleware group — resolves merchant_id from Host header
- [x] N7. Fallback merchant resolution — lookup `op_transactions` by reference in payload when domain match fails

### Outbound (Own Pay → Merchant Website)
- [x] N8. Create `src/Service/Notification/WebhookDispatcher.php` — HMAC-SHA256 signed POST to merchant webhook_url
- [x] N9. Standardized outbound payload (event, transaction_id, amount, currency, gateway, gateway_type, status, timestamp)
- [x] N10. Retry logic — exponential backoff (3 attempts: 1m, 5m, 30m)
- [x] N11. Delivery logging — `op_webhook_deliveries` (status_code, response_time_ms, attempt count)
- [x] N12. Listen to ALL `payment.transaction.*` hooks — universal for api/manual/bank gateway types

### Gateway Plugin Refactoring
- [x] N13. Refactor Stripe verification logic → `modules/gateways/stripe/Plugin.php` listens to `webhook.incoming.stripe`
- [x] N14. Refactor SSLCommerz verification logic → `modules/gateways/sslcommerz/Plugin.php` listens to `webhook.incoming.sslcommerz`
- [x] N15. Refactor bKash verification logic → `modules/gateways/bkash/Plugin.php` listens to `webhook.incoming.bkash`

### Domain-Aware Callback URL Generation
- [x] N16. Update `DomainService::merchantUrl()` usage in payment initiation — callback URLs use merchant's custom domain
- [x] N17. Ensure `PaymentService` passes domain-aware callback URL to all gateway providers

### Testing
- [x] N18. Unit: `WebhookPayloadTest.php` — construction, header access, JSON parsing, formData
- [x] N19. Unit: `WebhookDispatcherTest.php` — HMAC signing, retry logic, delivery log
- [x] N20. Unit: `UnifiedWebhookControllerTest.php` — dynamic dispatch, unknown gateway → 404, payload creation
- [x] N21. Integration: Inbound webhook end-to-end (mock gateway → own pay → transaction update)
- [x] N22. Integration: Outbound webhook end-to-end (transaction complete → signed POST → merchant)

---

**Total: ~344 tasks across 14 phases (A–N)**

| Phase | Tasks | Scope |
|-------|-------|-------|
| A | 28 | Core framework |
| B | 25 | Database + repos |
| C | 19 | Security + middleware |
| D | 22 | Plugin ecosystem |
| E | 61 | Services + domain + update |
| F | 9 | Gateways |
| G | 53 | Admin panel |
| H | 22 | API layer |
| I | 17 | Checkout theme (v2 HTML) |
| J | 16 | Built-in addons |
| K | 10 | Installer |
| L | 24 | Testing |
| M | 15 | Cleanup + ship |
| **N** | **22** | **Unified Dynamic Webhook/IPN** |
