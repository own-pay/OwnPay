# Comprehensive Audit & Remediation Plan: OwnPay V2 Migration
**Document Status:** Pending User Approval
**Date:** May 1, 2026

## 1. Executive Summary
A deep-dive architectural audit of the OwnPay V2 admin dashboard has revealed a systemic misalignment between the routing layer (`config/routes/web.php`), the frontend form actions (`templates/admin/`), and the backend Controllers. 

The "whack-a-mole" phenomenon was caused by superficial HTML form tweaks that did not address the root cause: **Backend controllers are missing the actual POST handler methods (`store`, `update`, `toggle`, etc.) completely, or they are severely misnamed.**

Furthermore, critical architectural gaps exist in middleware enforcement, HTTP timeout handling, and settings configuration.

---

## 2. Exhaustive Bug List & Root Cause Analysis

### A. Auth & Profile
*   **Bug:** 2FA setup throws 404.
    *   *Root Cause:* `web.php` lacks routes for `/admin/my-account/2fa/setup` and `/admin/my-account/2fa/disable`. `DashboardController` lacks 2FA secret generation and verification logic.
*   **Bug:** "My Account" update throws an error.
    *   *Root Cause:* The recently added `updateAccount` method relies on `type` (profile/password), but lacks strict validation, and fails if the `auth_user_id` session is not properly cast, leading to SQL execution errors.

### B. UI/UX
*   **Bug:** Theme mode setting is completely broken (cannot toggle dark/light).
    *   *Root Cause:* Zero persistence logic. There is no Javascript listener to toggle a `.dark` class, no cookie storage, and no backend API to save the user's preference to `op_merchant_users`.

### C. Core Architecture
*   **Bug:** Maintenance mode is not working.
    *   *Root Cause:* The `op_system_settings` table contains a maintenance flag, but there is **no HTTP Middleware** injected into the `Router` to intercept requests and return a 503 response.

### D. Updater Logic Flaws
*   **Bug:** Too many update histories showing.
    *   *Root Cause:* `SystemUpdateController@index` fetches the last 20 records without grouping by version or status, causing UI spam on multiple failed attempts.
*   **Bug:** Update server is DOWN; lacks timeout/fallback logic.
    *   *Root Cause:* `UpdateService@check` uses synchronous `curl` with a 10s timeout that blocks the PHP thread. It lacks a `try/catch` fallback to display cached version info, causing the dashboard to hang or crash when the remote server is unreachable.
*   **Bug:** System Update menu is in the wrong place.
    *   *Root Cause:* Hardcoded in `sidebar.twig` as a top-level item under "System" rather than nested inside the "Settings" UI tree.

### E. Settings & Configs
*   **Bug:** API key settings have NO configuration options.
    *   *Root Cause:* `ApiKeyController` only generates a basic hash. The database schema and UI are missing advanced fields for `scopes`, `permissions`, `IP whitelists`, and `expires_at`.
*   **Bug:** Massive amounts of settings missing.
    *   *Root Cause:* The `SettingsController` only loads a generic view. There are no UI tabs or backend logic to handle SMTP config, global webhooks, system security policies, or cron monitoring.

### F. Ecosystem & Modules
*   **Bug:** Theme installation throws 404.
    *   *Root Cause:* `web.php` has no POST route for `/admin/themes/install` or `/admin/themes/upload`.
*   **Bug:** Plugin installation throws an error.
    *   *Root Cause:* `PluginController@upload` fails because it does not properly validate ZIP mime types or handle directory write permissions gracefully.

### G. Merchants & Brands
*   **Bug:** Brand edit throws 404.
    *   *Root Cause:* Controller missing the `update` method mapped in `web.php`.
*   **Bug:** Custom domains missing/incorrect mapping.
    *   *Root Cause:* `DomainController` handles domains independently. The `Brand` edit UI lacks a "Domains" tab to associate `op_domains.merchant_id` visually, making domain mapping orphaned from the brand context.

### H. CRUD/Store Failures
*   **Bug:** Domain, Device, Staff, Gateway (manual), and Invoice store errors.
    *   *Root Cause:* **Systemic Controller Deficit.** `web.php` expects methods like `@store` and `@update`, but `StaffController`, `GatewayController`, `InvoiceController`, etc., only possess the GET rendering methods (`create`, `edit`). When a form is submitted, the Router throws a `Method not found` RuntimeException.

### I. Action Failures
*   **Bug:** "Payment link stop" action throws an error.
    *   *Root Cause:* `PaymentLinkController` is missing a `toggleStatus` method, and `web.php` lacks the corresponding `/admin/payment-links/{id}/toggle` route.

---

## 3. Strict Remediation Plan (How We Fix It)

We will execute the fixes in isolated, logical phases to guarantee zero regression:

### Phase 1: Controller & Route Synchronization (The 404 Killers)
1.  **Audit & Inject:** Scan `web.php` and inject all missing `store`, `update`, `toggle`, and `delete` methods into `StaffController`, `GatewayController`, `InvoiceController`, `BrandController`, and `PaymentLinkController`.
2.  **Standardize Signatures:** Ensure all methods use `$req->getAttribute('id')` for parameters rather than expecting them via method arguments (which the router doesn't supply correctly).

### Phase 2: Architectural Middleware & API Fortification
1.  **Maintenance Middleware:** Create `MaintenanceMiddleware.php`, register it globally, and have it check `SettingsRepository`. If active, return a 503 template.
2.  **API Key Upgrades:** Alter `op_api_keys` to include `scopes` (JSON) and `expires_at`. Update `ApiKeyController` and the UI to support granular permissions.
3.  **Payment Link Toggle:** Implement the `toggleStatus` endpoint and bind it to the UI stop/start button.

### Phase 3: The Updater & Settings Overhaul
1.  **Async/Safe Updater:** Refactor `UpdateService@check` to catch curl exceptions, return cached latest version from `op_system_settings`, and prevent UI hanging.
2.  **History Deduplication:** Modify the query in `SystemUpdateController` to group by version and only show the latest attempt per version.
3.  **Settings UI Consolidation:** Move "System Update" into a tab inside `settings/index.twig`. Build out the missing settings forms (SMTP, Security, App Branding) and map them to `SettingsController@save`.

### Phase 4: UX & Brand Domain Mapping
1.  **Theme Toggle:** Implement a vanilla JS script to toggle a `data-theme` attribute on the `<html>` tag, save it to `localStorage`, and optionally sync via an AJAX call to a new `/admin/my-account/theme` endpoint.
2.  **Brand Domains:** Add a "Linked Domains" section to `templates/admin/brands/edit.twig` that pulls from `DomainRepository`, allowing users to map domains directly within the brand context.
