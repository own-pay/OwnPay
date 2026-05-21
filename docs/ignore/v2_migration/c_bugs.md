# OwnPay v0.1.0 — Comprehensive Bug Audit & Fixing Plan

> **Date:** 2026-05-01 | **Status:** AUDIT COMPLETE — Awaiting Approval Before Fixes
> **Rule:** DO NOT WRITE ANY CODE FIXES UNTIL THIS DOCUMENT IS APPROVED.

---

## Executive Summary

9 user-reported failures. Deep-scan found **18 total bugs** across 4 root-cause categories:

| Category | Count | Severity |
|----------|:-----:|----------|
| A. Schema column mismatches | 4 | **CRITICAL** — causes 6+ user-reported failures |
| B. Route→method mismatches | 2 | **HIGH** — causes 404s |
| C. Undefined variables / crashes | 1 | **HIGH** — crashes update page |
| D. Maintenance mode broken | 1 | **HIGH** — locks out entire app |
| E. Theme toggle incompatible | 1 | **MEDIUM** — dark/light broken |
| F. Settings architecture gaps | 2 | **MEDIUM** — missing config UI |
| G. Update system issues | 3 | **MEDIUM** — wrong URL, no fallback |
| H. Domain/brand UX gaps | 1 | **MEDIUM** — missing UI |
| I. Dead code | 2 | **LOW** — cosmetic |

**Pattern:** Schema defines columns `totp_secret_enc` / `two_factor_enabled` / `name`, but PHP code uses `totp_secret` / `totp_enabled` / `business_name`. Three separate codebase iterations left inconsistent references.

---

## A. Schema Column Mismatches (CRITICAL)

### BUG-A1: 2FA columns — `totp_secret` vs `totp_secret_enc`, `totp_enabled` vs `two_factor_enabled`

**User symptom:** 2FA setup throws 404/crash. "My Account" update throws error.

**Root cause:**
- `database/schema.sql` line 73-74 defines: `totp_secret_enc VARCHAR(500)`, `two_factor_enabled TINYINT(1)`
- All PHP code references: `totp_secret`, `totp_enabled`
- SQL queries select/update non-existent columns → MySQL error → controller crash

**Affected files:**
| File | Lines | Wrong column |
|------|-------|-------------|
| `src/Controller/Admin/TwoFactorSetupController.php` | 31, 37, 38, 45, 54, 55, 67, 69, 74, 79, 98 | `totp_secret`, `totp_enabled` |
| `src/Controller/Admin/DashboardController.php` | 124, 125 | `totp_enabled` |

**Fix plan:**
- **Option A (recommended):** Rename schema columns to match code. Change `totp_secret_enc` → `totp_secret`, `two_factor_enabled` → `totp_enabled`. Simpler — less PHP to change.
- **Option B:** Update all PHP references to match schema. More files touched.
- Choose one direction, apply consistently. Also update `src/Security/LogSanitizer.php` line 16 if needed.

---

### BUG-A2: `business_name` vs `name` in `op_merchants`

**User symptom:** Domain page error, checkout page crashes, balance verification fails.

**Root cause:**
- `database/schema.sql` line 16 defines: `name VARCHAR(200)`
- 4 files still query `business_name` — column doesn't exist → MySQL error

**Affected files:**
| File | Lines | Query |
|------|-------|-------|
| `src/Controller/Admin/DomainController.php` | 26-27 | `SELECT business_name FROM op_merchants` |
| `src/Controller/Checkout/CheckoutController.php` | 33, 103 | `m.business_name as merchant_name` |
| `src/Cron/BalanceVerificationJob.php` | 31 | `SELECT id, business_name FROM op_merchants` |

**Fix plan:** Replace `business_name` → `name` in all 4 files. 6 total line changes.

---

### BUG-A3: `instructions` column is JSON but controller writes plain string

**User symptom:** Gateway store (manual) error. Instructions not saved correctly.

**Root cause:**
- `database/schema.sql` line 166 defines `instructions JSON DEFAULT NULL`
- `GatewayController` lines 98, 145: `InputSanitizer::string($data['instructions'])` writes plain text to a JSON column
- MySQL may reject or misinterpret non-JSON value

**Affected files:**
| File | Lines | Issue |
|------|-------|-------|
| `src/Controller/Admin/GatewayController.php` | 98, 145 | Writes string to JSON column |

**Fix plan:** Encode instructions as JSON array/object before insert/update. Example:
```php
'instructions' => json_encode(['steps' => array_filter(array_map('trim', explode("\n", $data['instructions'] ?? '')))]),
```
Or change schema to `TEXT` if structured JSON instructions aren't needed yet.

---

### BUG-A4: `permissions` column doesn't exist on `op_merchant_users`

**User symptom:** Staff store error. Staff edit page crashes.

**Root cause:**
- `database/schema.sql` `op_merchant_users` table has NO `permissions` column
- `StaffController` line 82: `$user['permissions'] = json_decode($user['permissions'] ?? '[]', true);`
- Line 88: `'permissions' => json_encode($data['permissions'] ?? [])`
- Reading/writing non-existent column

**Affected files:**
| File | Lines | Issue |
|------|-------|-------|
| `src/Controller/Admin/StaffController.php` | 82, 88 | Reads/writes non-existent column |

**Fix plan:**
- **Option A:** Add `permissions JSON DEFAULT NULL` column to `op_merchant_users` in schema.sql. Staff permissions stored per-user.
- **Option B:** Use existing `op_role_permissions` table (already in schema). StaffController should read permissions from role, not user row.
- Recommended: Option A (simpler, matches controller logic).

---

## B. Route-to-Method Name Mismatches (HIGH)

### BUG-B1: Plugin install page — route calls `installForm`, method is `installPage`

**User symptom:** Plugin/theme installation throws 404. `GET /admin/plugins/install` → method not found.

**Root cause:**
- `config/routes/web.php` line 143: `'Admin\\PluginController@installForm'`
- `src/Controller/Admin/PluginController.php` line 76: method named `installPage()`
- Router cannot find `installForm` → 404

**Fix plan:** Rename method `installPage()` → `installForm()` in PluginController, OR update route to use `installPage`. Recommend matching route: rename method.

---

### BUG-B2: Missing `CronController` class

**User symptom:** Cron endpoint `GET /cron/{secret}` returns 500.

**Root cause:**
- `config/routes/web.php` line 168: `'Page\\CronController@run'`
- No `src/Controller/Page/CronController.php` file exists
- Router cannot instantiate class → fatal error

**Fix plan:** Create `src/Controller/Page/CronController.php` with `run()` method that:
1. Validates `{secret}` against env/config value
2. Instantiates `CronJobRunner`
3. Runs due jobs
4. Returns plain text response ("OK: 3 jobs run")

---

## C. Undefined Variables (HIGH)

### BUG-C1: `SystemUpdateController::index()` — `$db` undefined

**User symptom:** System Update page crashes with 500 error.

**Root cause:**
- Line 21: `$history = $db->fetchAll("SELECT * FROM op_update_history ORDER BY id DESC LIMIT 20");`
- Line 26: `$autoUpdate = $db->fetchOne(...)`
- `$db` is never assigned. Must be `$this->c->get(\OwnPay\Core\Database::class)`

**Affected file:** `src/Controller/Admin/SystemUpdateController.php` lines 21, 26

**Fix plan:** Add at top of `index()`:
```php
$db = $this->c->get(\OwnPay\Core\Database::class);
```

---

## D. Maintenance Mode Broken (HIGH)

### BUG-D1: MaintenanceMiddleware blocks ALL requests with no escape hatch

**User symptom:** Maintenance mode not working. Once enabled, no way to disable it from UI.

**Root cause:**
- `config/middleware.php`: `MaintenanceMiddleware` in `global` group → runs on ALL requests
- `src/Middleware/MaintenanceMiddleware.php`: returns 503 for everything when `.maintenance` file exists
- No bypass for authenticated admin users
- No bypass for `/admin/system-update` route (needed to disable maintenance)
- Admin cannot access system-update page to turn off maintenance → permanent lockout

**Fix plan:** Update `MaintenanceMiddleware::handle()` to:
1. Skip if request path starts with `/admin/system-update` AND user has active admin session
2. Skip if user is authenticated as superadmin (`$_SESSION['is_superadmin']`)
3. Allow admin panel access during maintenance (only block public/checkout/API routes)

```php
// Skip for admin routes when authenticated
if (str_starts_with($request->path(), '/admin') && !empty($_SESSION['auth_user_id'])) {
    return $next($request);
}
```

---

## E. Theme Toggle Broken (MEDIUM)

### BUG-E1: JS sets `data-theme` attribute but Tailwind expects `class="dark"`

**User symptom:** Dark/light mode toggle does nothing visible.

**Root cause:**
- `public/assets/js/admin.js` lines 144-161: sets `htmlEl.setAttribute('data-theme', theme)`
- `tailwind.config.js` line 11: `darkMode: 'class'` — expects `class="dark"` on `<html>`
- These are incompatible mechanisms. CSS uses Tailwind's `dark:` variants which activate via `class`, not `data-theme`

**Affected files:**
| File | Lines | Mechanism |
|------|-------|-----------|
| `public/assets/js/admin.js` | 144-161 | `data-theme` attribute |
| `tailwind.config.js` | 11 | `darkMode: 'class'` |

**Fix plan:** Change `admin.js` `applyTheme()` function:
```javascript
function applyTheme(theme) {
    if (theme === 'dark') {
        htmlEl.classList.add('dark');
    } else {
        htmlEl.classList.remove('dark');
    }
    localStorage.setItem(THEME_KEY, theme);
    var icon = document.getElementById('theme-toggle-icon');
    if (icon) { icon.textContent = theme === 'dark' ? '☀' : '☾'; }
}
```
And update the read:
```javascript
var savedTheme = localStorage.getItem(THEME_KEY);
if (savedTheme) { applyTheme(savedTheme); }
```

---

## F. Settings Architecture Issues (MEDIUM)

### BUG-F1: SettingsController queries flat `key_name` without `group_name`

**User symptom:** Settings may not load correctly. Some settings invisible.

**Root cause:**
- `SettingsController::index()` line 21: `SELECT key_name, value FROM op_system_settings` (flat, no group)
- `SettingsRepository` uses `group_name` + `key_name` for grouped queries
- `SettingsController::save()` lines 52-59: writes without `group_name`
- Schema table has `group_name` column with UNIQUE constraint on `(group_name, key_name)`
- Saving from settings page creates entries with `group_name = 'general'` (default)
- But the flat SELECT may conflict with grouped entries from other features (plugins, etc.)

**Fix plan:** Update SettingsController to use grouped approach:
```php
$rows = $db->fetchAll("SELECT group_name, key_name, value FROM op_system_settings WHERE group_name = 'general'");
```
And `save()` should include `group_name`:
```php
$db->update("INSERT INTO op_system_settings (group_name, key_name, value) VALUES ('general', :k, :v) ON DUPLICATE KEY UPDATE value = :v2", [...]);
```

---

### BUG-F2: API key settings have no configuration options

**User symptom:** API key page shows list only. No scopes, rate limits, or permissions configuration.

**Root cause:**
- `SettingsController::index()` loads API keys for display
- `templates/admin/settings/index.twig` likely renders a simple list
- No form fields for API key configuration (scopes, rate limits, allowed IPs, expiry)

**Fix plan:** Add API key configuration section to settings template:
1. Generate key with configurable scopes (read, write, admin)
2. Rate limit per key
3. Allowed IP whitelist
4. Key expiry date
5. Revoke/regenerate actions
Requires `templates/admin/settings/index.twig` enhancement + `ApiKeyService` method updates.

---

## G. Update System Issues (MEDIUM)

### BUG-G1: Update checker URL wrong + no visible fallback

**User symptom:** Update check silently fails. User sees nothing — no error, no "up to date" message.

**Root cause:**
- `src/Update/UpdateService.php` line 49: URL is `https://updates.ownpay.dev/api/v1/check`
- Correct URL per plan §6.1: `https://update.ownpay.org/update.json`
- `update.ownpay.org` is the release metadata server (OwnPay is self-hosted software — business owners host it on their own servers, update server checks for new releases)
- When server unreachable, `check()` catches exception and returns `['available' => false]` silently
- BUT `SystemUpdateController::index()` crashes first due to BUG-C1 (`$db` undefined)
- User never sees any feedback

**Fix plan:**
1. Fix BUG-C1 first (add `$db` variable)
2. Change URL to `https://update.ownpay.org/update.json`
3. Add `ConnectionException` handling in `SystemUpdateController::check()`:
```php
} catch (\OwnPay\Service\System\HttpConnectionException $e) {
    $_SESSION['flash_error'] = 'Unable to reach update server. Check your internet connection and try again.';
}
```
4. Cache last successful check with timestamp. Show "Last checked: X minutes ago" even when offline.

---

### BUG-G2: Too many update histories showing

**User symptom:** Update history table shows stale/duplicate entries.

**Root cause:**
- `SystemUpdateController::index()` fetches `LIMIT 20` — no pagination
- Failed/incomplete updates leave stale entries with status `started`, `backup_created`, etc.
- No cleanup of entries older than X days

**Fix plan:**
1. Add pagination (only show 5-10 per page)
2. Filter to meaningful statuses: `completed`, `failed`, `rolled_back` only
3. Group consecutive "checking" entries into single row
4. Auto-purge entries older than 90 days

---

### BUG-G3: System Update menu placement (NOT A BUG)

**User report:** "System Update menu in wrong place"

**Finding:** `templates/admin/layout/sidebar.twig` line 104 places it under Settings sub-nav. This IS the correct location per plan. Already implemented correctly.

**No fix needed.**

---

## H. Domain/Brand Custom Domain UX Gap (MEDIUM)

### BUG-H1: No custom domain management inside brand edit page

**User symptom:** "Where do I add custom domains for a brand?" Brand edit page has no domain tab.

**Root cause:**
- `BrandController@show()` loads brand data + primary domain info
- `templates/admin/brands/edit.twig` has no domain management tab
- Domain management exists only at `/admin/domains` (separate page)
- Not intuitive — users expect domains within brand editing

**Fix plan:**
1. Add "Custom Domain" tab to `templates/admin/brands/edit.twig`
2. Show current domain mapping (from `op_domains` WHERE `merchant_id = :id`)
3. "Add Domain" input + "Verify DNS" button inline
4. DNS instructions card (point A record to server IP)
5. Verification status badge
6. Backend: `BrandController@show()` already loads domain data — just needs template tab

**Database:** `op_domains` table already has `merchant_id` FK. No schema changes needed.

---

## I. Dead Code / Cosmetic (LOW)

### BUG-I1: `op_custom_domains` references — ALREADY FIXED

Grep confirms zero remaining references to `op_custom_domains`. No action needed.

---

### BUG-I2: Dead `renderAdminPage_OLD_unused` methods

Leftover dead code in 4 controllers:
- `src/Controller/Admin/SystemUpdateController.php` lines 112-119
- `src/Controller/Admin/PluginController.php` lines 216-227
- `src/Controller/Admin/ThemeController.php` lines 168-177
- `src/Controller/Admin/DomainController.php` lines 93-100

**Fix plan:** Delete all `renderAdminPage_OLD_unused` methods. Pure cleanup.

---

## User-Reported Bug → Root Cause Mapping

| # | User Report | Root Cause Bug(s) |
|---|-------------|-------------------|
| 1 | 2FA setup throws 404 | **BUG-A1** (schema column mismatch `totp_secret` / `totp_enabled`) |
| 2 | "My Account" update throws error | **BUG-A1** (same — `totp_enabled` column) |
| 3 | Theme mode toggle broken | **BUG-E1** (JS `data-theme` vs Tailwind `class="dark"`) |
| 4 | Maintenance mode not working | **BUG-D1** (blocks ALL requests, no escape hatch) |
| 5 | Too many update histories | **BUG-G2** (no pagination/filtering) |
| 6 | Update checker crashes/hangs | **BUG-C1** (`$db` undefined) + **BUG-G1** (wrong URL) |
| 7 | System Update menu wrong place | **BUG-G3** (NOT A BUG — already correct) |
| 8 | API key settings no config | **BUG-F2** (missing UI) |
| 9 | Theme installation 404 | **BUG-B1** (route→method name mismatch `installForm` vs `installPage`) |
| 10 | Plugin installation error | **BUG-B1** (same) |
| 11 | Brand edit throws 404 | Verify template exists at `templates/admin/brands/edit.twig` — template DOES exist. May be routing issue or schema mismatch |
| 12 | Custom domains for brand missing | **BUG-H1** (no domain tab in brand edit) |
| 13 | Domain store error | **BUG-A2** (`business_name` column doesn't exist) |
| 14 | Device page error | Likely schema mismatch — needs investigation in `DevicePairingService` |
| 15 | Staff store error | **BUG-A4** (`permissions` column doesn't exist) |
| 16 | Gateway store (manual) error | **BUG-A3** (JSON vs string for instructions) |
| 17 | Invoice store error | Needs investigation — may be service-level schema mismatch |
| 18 | Payment link stop action error | Needs investigation — no "stop" action route found in routes |

---

## Recommended Fix Order (Priority)

### Phase 1: Critical — Fixes 5 bugs, unblocks core functionality

| Step | Bug | Change | Files |
|------|-----|--------|-------|
| 1 | A1 | Rename schema columns `totp_secret_enc` → `totp_secret`, `two_factor_enabled` → `totp_enabled` | `database/schema.sql` |
| 2 | A2 | Replace `business_name` → `name` in 3 PHP files | `DomainController`, `CheckoutController`, `BalanceVerificationJob` |
| 3 | A4 | Add `permissions JSON DEFAULT NULL` to `op_merchant_users` | `database/schema.sql` |
| 4 | C1 | Add `$db = $this->c->get(Database::class)` | `SystemUpdateController.php` |

### Phase 2: High — Fixes 404s and lockouts

| Step | Bug | Change | Files |
|------|-----|--------|-------|
| 5 | B1 | Rename `installPage()` → `installForm()` | `PluginController.php` |
| 6 | B2 | Create `CronController.php` with `run()` | New file `src/Controller/Page/CronController.php` |
| 7 | D1 | Add admin bypass to MaintenanceMiddleware | `MaintenanceMiddleware.php` |

### Phase 3: Medium — UX fixes

| Step | Bug | Change | Files |
|------|-----|--------|-------|
| 8 | E1 | Fix theme toggle to use `classList.add/remove('dark')` | `public/assets/js/admin.js` |
| 9 | G1 | Fix URL to `update.ownpay.org/update.json`, add fallback message | `UpdateService.php`, `SystemUpdateController.php` |
| 10 | G2 | Add pagination + status filter to update history | `SystemUpdateController.php` |
| 11 | H1 | Add domain tab to brand edit template | `templates/admin/brands/edit.twig` |
| 12 | A3 | Fix instructions to write JSON | `GatewayController.php` |

### Phase 4: Low — Cleanup

| Step | Bug | Change | Files |
|------|-----|--------|-------|
| 13 | I2 | Delete dead `renderAdminPage_OLD_unused` methods | 4 controller files |
| 14 | F1 | Unify settings query approach | `SettingsController.php` |
| 15 | F2 | Build API key configuration UI | `templates/admin/settings/index.twig` |

### Phase 5: Investigation Needed

| Item | Symptom | Next Step |
|------|---------|-----------|
| Brand edit 404 | User says brand edit throws 404 | Test route manually — template exists, route exists. May be CSRF or middleware issue |
| Device page error | User reports error | Audit `DevicePairingService` for schema mismatches |
| Invoice store error | User reports error | Audit `InvoiceService::create()` for schema mismatches |
| Payment link stop action | User reports error | No "stop" route found — need to add `POST /admin/payment-links/{id}/stop` route + action |

---

## Architectural Gap Analysis

### Update Checker — Graceful Degradation

**Current:** When `update.ownpay.org` is unreachable, `UpdateService::check()` catches the exception and returns `['available' => false]`. The controller then shows "You are on the latest version" — misleading.

**Proposed:**
1. Return a third state: `['available' => false, 'error' => 'connection_failed']`
2. `SystemUpdateController::check()` detects `error` key and shows:
   > "Unable to reach update server. Your internet connection may be down, or the update server may be temporarily unavailable. Last successful check: 2026-05-01 14:30."
3. Cache last successful check timestamp in `storage/cache/update_check.json`
4. Never show "You are on the latest version" unless we actually got a successful response

### Custom Domain Mapping for Brands

**Database:** Already implemented correctly.
- `op_domains` table has `merchant_id` FK → maps domains to brands
- `is_primary`, `dns_verified`, `ssl_status`, `redirect_url` fields all exist
- `DomainService` + `DnsVerifier` services exist and work

**Missing:** UI integration. Domains managed via separate `/admin/domains` page, not within brand context.

**Proposed:**
1. Add "Custom Domain" tab to `templates/admin/brands/edit.twig`
2. Load existing domains for this brand in `BrandController@show()`
3. Inline domain add/verify/remove within brand edit
4. Keep `/admin/domains` as global domain management (cross-brand view for superadmin)

---

**END OF AUDIT — Awaiting approval before any code changes.**
