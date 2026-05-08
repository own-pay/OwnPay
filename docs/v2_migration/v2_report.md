# V2 Post-Migration Codebase Audit
**Status:** Architecture stable. Implementation messy.

## 1. Dead Code (Resolved)
- Legacy `app/` → gone.
- `src/Http/Controller/` → gone.
- Global routes/legacy kernels → gone.
- PSR-11 + Route Pipeline → active.

## 2. Structural Mess (Action Required)

### A. Fat Controllers / Repo Bypass
- **Issue:** 114+ raw `$db->fetchOne()` / `$db->update()` calls inside `src/Controller/*`.
- **Why bad:** Domain logic leak. Repositories (`SettingsRepository`, `LedgerRepository`) exist but ignored. 
- **Fix:** Move SQL → Repositories. Controllers → DI Repositories only.

### B. Dependency Injection Violations
- **Issue:** `new` keyword bypassing container.
  - `src/Service/Sms/SmsParserService.php` → `new MobileNotificationService()`
  - `src/Gateway/WebhookInboundProcessor.php` → `new PaymentService()`
- **Why bad:** Hard coupling. Breaks tests/mocking.
- **Fix:** Inject via `__construct()`.

### C. Missing DTOs / Validation
- **Issue:** `$_POST` / `$req->post()` arrays passed raw. `InputSanitizer` used sporadically.
- **Why bad:** Unsafe. Schema changes break random controllers.
- **Fix:** Implement strict Request/DTO objects.

### D. File / Class Disorganization
- **Issue:** `src/Core/Database.php` handles everything. `SettingsController` manually builds SQL strings.
- **Fix:** Abstract logic out of controllers.

## Summary
Core V2 skeleton good. Meat on bones bad. Refactor controllers → Repositories + DI strictness. No more raw SQL in HTTP layer.
