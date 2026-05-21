# Codebase Audit & Gap Analysis

> **Audited:** 2026-04-08 | **Scope:** Full codebase (119 src/ files, 130+ app/ files, 46 gateways)

---

## Part 1: Current State Architecture (What Is Already Modern)

### 1.1 Fully Modern Components (60+ files) ✅

These components are production-ready: strict-types, PSR-4, proper DI, zero legacy function calls.

| Layer | Files | Key Classes |
|-------|-------|-------------|
| **Core** (3) | `src/Core/Database.php`, `src/Core/UuidGenerator.php`, `src/Bootstrap.php` | PDO singleton, UUID v7, app initializer |
| **API Controllers** (8) | `src/Http/Controller/*.php` | PaymentController, TransactionController, RefundController, CustomerController, ApiKeyController, WebhookController, HealthController, AdminUpdateController |
| **Repositories** (14) | `src/Repository/*.php` | BaseRepository + TenantScope trait + 12 domain repos |
| **Plugin System** (8) | `src/Plugin/*.php` | PluginInterface, PluginManifest, PluginLoader, PluginRegistry, PluginInstaller, PluginSandbox, PluginMigrator, Capability |
| **Event System** (1) | `src/Event/EventManager.php` | Pure OOP singleton event bus — sole hook API |
| **Gateway Infra** (4) | `src/Gateway/*.php` | GatewayAdapterInterface, GatewayDefaults, LegacyGatewayBridge, WebhookInboundProcessor |
| **Security** (4) | `src/Security/*.php` | FieldEncryptor (AES-256-GCM), PiiMasker, LogSanitizer, SecurityHelpers |
| **Http Core** (4) | `src/Http/*.php` | Router, RequestContext (immutable VO), JsonResponse, ErrorHandler |
| **Modern Middleware** (5) | `src/Middleware/*.php` | BearerAuth, RateLimiter, Cors, IpAllowlist, RequestSignature |
| **Core Services** (15) | `src/Service/*.php` | PaymentService, LedgerService, TransactionService, AuditLogger, ApiKeyService, CustomerPiiService, DisputeService, HttpClient, IdempotencyService, Logger, PluginManager, ReconciliationService, RefundService, SettlementService, StatusGuard, WebhookService |
| **Cron** (1) | `src/Cron/CronJobRunner.php` | Advisory-lock cron runner with plugin job registration |
| **Sample Plugins** (2) | `app/modules/plugins/hello-world/`, `webhook-logger/` | Reference PluginInterface implementations |

### 1.2 Design Patterns Successfully Implemented

- **Repository Pattern** — BaseRepository + TenantScope trait
- **Service Layer** — 27 service classes
- **Middleware Pipeline** — 9 middleware classes
- **Event Bus / Observer** — EventManager singleton
- **Plugin Architecture** — PluginInterface + manifest.json
- **Immutable Value Objects** — RequestContext, PluginManifest
- **State Machine** — StatusGuard for payment lifecycle
- **Adapter Pattern** — LegacyGatewayBridge

### 1.3 Verdict

The foundation is excellent. Core SOA layers (Repositories, modern Services, Plugin system, EventManager, Security, API Controllers) are production-ready. Legacy debt is concentrated in two areas: (A) admin controllers calling procedural helpers, and (B) the procedural `app/` layer.

---

## Part 2: Legacy Debt Inventory

### 2.1 Quantified Legacy Contamination in `src/`

| Legacy Pattern | Occurrences | Files Affected | Severity |
|---------------|-------------|----------------|----------|
| `getData()` calls | **243** | 30 | 🔴 HIGH |
| `updateData()`/`insertData()`/`deleteData()` | **199** | 24 | 🔴 HIGH |
| `canAccessPage()`/`hasPermission()` | **223** | 23 | 🔴 HIGH |
| `getCookie()`/`setsCookie()`/`sanitize_html()`/`clean_input()`/`get_env()`/`set_env()` | **107** | 29 | 🟡 MODERATE |
| `global $` keyword | **2** | 2 | 🟢 LOW |
| Missing `declare(strict_types=1)` | ~15% | ~18 | 🟡 MODERATE |

**Total: 772 legacy function calls across 29 files in `src/`**

### 2.2 File-by-File Legacy Inventory

#### A. Admin Controllers (28 files — `src/Controller/*.php`)

All follow the same legacy-wrapped pattern: `static handle()` method, extract from `RequestContext`, call procedural functions (`getData`, `insertData`, `canAccessPage`, `sanitize_html`), `echo json_encode()` directly.

**Highest-debt controllers:**

| Controller | getData | insert/update/delete | canAccessPage/hasPermission | Other Legacy | Total |
|-----------|---------|---------------------|---------------------------|-------------|-------|
| StaffController | 23 | 17 | 23 | 3 | 63+ |
| BrandController | 10 | 35 | 9 | 2 | 54+ |
| TransactionController | 27 | 9 | 11 | 2 | 47+ |
| SmsDataController | 15 | 11 | 12 | 11 | 37+ |
| ApiKeyController | 8 | 6 | 19 | 1 | 34+ |
| BalanceVerificationController | 10 | 7 | 16 | 1 | 33+ |
| CompanionApiController | 10 | 23 | 0 | 12 | 33+ |
| InvoiceController | 10 | 11 | 11 | 12 | 32+ |
| PaymentLinkController | 5 | 12 | 13 | 2 | 30+ |
| AddonController | 7 | 10 | 13 | 1 | 30+ |

#### B. Frontend Controllers (5 files — `src/Controller/Frontend/*.php`)

| Controller | Total Legacy Calls |
|-----------|-------------------|
| ApiController | 22+ |
| InvoiceCheckoutController | 12+ |
| PaymentLinkCheckoutController | 8+ |
| LegacyCheckoutController | 5+ |
| IpnController | 3+ |

#### C. Partially Modern Middleware (4 files)

| Middleware | Legacy Calls | Specifics |
|-----------|-------------|-----------|
| SessionMiddleware | 17 | getData ×9, getCookie ×4, setsCookie ×2, sanitize_html ×2 |
| CsrfMiddleware | 4 | sanitize_html ×2, clean_input ×1, get_env ×1 |
| PermissionMiddleware | 2 | canAccessPage ×1, hasPermission ×1 |
| TwoFactorMiddleware | 0 | Minor — receives pre-sanitized data |

#### D. Hybrid Services (6 files)

| Service | Legacy Calls | Specifics |
|---------|-------------|-----------|
| GatewayRendererService | 7 | getData ×7 |
| TransactionService | 9 | getData ×6, updateData ×3 |
| GatewayApiService | 2 | getData ×2 |
| MfsService | 2 | getData ×2 |
| LegacyIdempotencyBridge | 5 | getData ×2, insertData ×3 |
| PermissionService | 2 | hasPermission ×2 |

#### E. Legacy Wrapper: `src/Database/Database.php`

Contains `DB` class + `QueryBuilder` class. Calls `connectDatabase()` from `functions.php`. Redundant with `src/Core/Database.php` (modern PDO singleton).

#### F. `src/Http/Request.php`

Missing `declare(strict_types=1)`. Calls `clean_input()` internally.

### 2.3 Procedural `functions.php` (1,047 LOC, 75+ functions)

| Category | Functions | Used By src/? |
|----------|-----------|--------------|
| **DB CRUD** (6) | getData, insertData, updateData, deleteData, optimisticTransactionUpdate, limit_checker | YES — 243+ calls |
| **Cookie** (3) | getCookie, setsCookie, logoutCookie | YES — SessionMiddleware, AuthController |
| **Sanitization** (4) | sanitize_html, clean_input, escape_string (deprecated), safe_log | YES — 50+ calls |
| **Environment** (2) | get_env, set_env | YES — Settings, plugins, system |
| **Permission** (4) | hasPermission, canAccessPage, permissionSchema, countPermissions | YES — 223 calls |
| **Money Math** (9) | money_add, money_sub, money_mul, money_div, money_round, moneyToInt, intToMoney, money_sanitize, verifyPaymentTolerance | YES — via services |
| **Hook System** (4) | add_action, do_action, add_filter, apply_filters | DUPLICATE of EventManager |
| **Others** (45+) | URL, date, file, gateway, PDF, language, IPN helpers | Mixed |

### 2.4 Duplicate Systems

| Modern System | Legacy Duplicate | Impact |
|--------------|-----------------|--------|
| `EventManager` (src/Event/) | `add_action()`/`do_action()` in functions.php using `$GLOBALS` | Two hook buses in parallel |
| `src/Core/Database.php` (PDO singleton) | `src/Database/Database.php` (DB::table + QueryBuilder) | Two DB layers |
| `src/Http/Controller/` (API, modern) | `src/Controller/` (admin, legacy-wrapped) | Two controller conventions |
| `BaseRepository` (ORM-like) | `getData()`/`insertData()` (raw SQL helpers) | 442+ procedural calls remain |

### 2.5 `index.php` Inline Cron Logic (~320 LOC)

Business logic embedded in the front controller entry point:
- System update checker (~55 lines)
- SMS transaction verification (~120 lines)
- Currency auto-update with multi-curl (~70 lines)
- Balance verification (~30 lines)
- Webhook retry logic (~45 lines)

---

## Part 3: Integration Bottlenecks

### 3.1 Global Variable Bridge (Critical)

`adapter.php` (lines 259-271) exports `RequestContext` fields to 12 procedural globals:
```
$csrf_token, $global_user_login, $global_user_2fa, $global_two_fector_validate,
$global_user_response, $global_response_brand, $global_response_permission,
$global_permissions, $global_cookie_response, $global_brand_currency_code,
$global_brand_currency_symbol, $global_brand_currency_rate
```
ALL 45 admin dashboard views + 4 layout files depend on these. Cannot remove until views are refactored.

### 3.2 getData() Anti-Pattern

Returns a **JSON string** (not array), requiring `json_decode()` at every call site. 243 calls in `src/`. Each controller must be migrated as a unit.

### 3.3 Permission System Coupling

`canAccessPage()` and `hasPermission()` called 223 times across 23 files. `PermissionService` exists but delegates back to these procedural functions.

### 3.4 Dual Hook Systems

`EventManager` (plugins) and procedural hooks (`$GLOBALS['__actions']`/`$GLOBALS['__filters']`) run in parallel. Addons use procedural; plugins use EventManager.

---

## Part 4: Targeted Migration Blueprint

### Principles

1. Target ONLY legacy patterns inside `src/` — views/templates are a separate concern
2. Replace procedural function calls with Service/Repository calls
3. Zero new frameworks — use the existing Repository + Service + EventManager architecture
4. Each milestone is independently deployable — no big-bang rewrites
5. Views continue working via thin wrapper functions in `functions.php`

---

### Milestone 1: Eliminate Duplicate Systems & Add Missing strict_types

- DELETE `src/Database/Database.php` (redundant with `src/Core/Database.php`)
- DELETE procedural hook functions from `functions.php`
- ADD `declare(strict_types=1)` to all missing files in `src/`
- REFACTOR `src/Http/Request.php`: replace `clean_input()` with inline `trim()`

### Milestone 2: Create Modern Replacements for Procedural Helpers

New service files:
- `src/Service/AuthSessionService.php` — replaces getCookie, setsCookie, logoutCookie
- `src/Service/InputSanitizer.php` — replaces sanitize_html, clean_input
- `src/Service/EnvironmentService.php` — replaces get_env, set_env (with caching)
- `src/Service/PermissionGuard.php` — replaces canAccessPage, hasPermission
- `src/Service/CrudService.php` — replaces getData, insertData, updateData, deleteData (returns arrays, not JSON strings)

### Milestone 3: Migrate Core Middleware to Pure OOP

SessionMiddleware, CsrfMiddleware, PermissionMiddleware — replace all procedural calls with new services.

### Milestone 4: Migrate Admin Controllers (Batch 1 — 10 Low-Complexity)

DashboardController, SettingsController, ThemeController, GatewayController, CurrencyController, FaqController, DomainController, DeviceController, CheckoutController, PluginController

### Milestone 5: Migrate Admin Controllers (Batch 2 — 18 High-Complexity)

Remaining admin + frontend controllers.

### Milestone 6: Migrate Hybrid Services (6 files)

GatewayApiService, GatewayRendererService, MfsService, TransactionService, LegacyIdempotencyBridge, PermissionService.

### Milestone 7: Extract Cron Business Logic from index.php

5 new cron job classes: SystemUpdateJob, SmsVerificationJob, CurrencyUpdateJob, BalanceVerificationJob, WebhookRetryJob.

### Milestone 8: Gateway strict_types & Cleanup (46 files)

Add `declare(strict_types=1)`, replace direct cURL with HttpClient, replace `$_GET`/`$_POST` with Request.

### Milestone 9: Consolidate functions.php

Convert to thin wrappers delegating to services. Target: ~200 LOC from 1,047.

### Milestone 10: Test Coverage Expansion

New tests for CrudService, PermissionGuard, InputSanitizer, EnvironmentService, EventManager, CsrfMiddleware. Target: ~25% from ~5%.

---

## Final Targets

| Metric | Current | Target |
|--------|---------|--------|
| Legacy calls in `src/` | 772 | 0 |
| Mixed legacy/modern files | 48 | 0 |
| Duplicate systems | 5 | 0 |
| Test coverage | ~5% | ~25% |
| `declare(strict_types=1)` | ~85% | 100% |
| `functions.php` LOC | 1,047 | ~200 |
| `index.php` cron LOC | ~320 | 0 |
