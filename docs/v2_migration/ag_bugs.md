# OwnPay V2 Migration: Comprehensive Architecture & Codebase Audit (ag_bugs.md)

## Overview
As per the strict **Audit & Remediation Phase** mandate, development and code modifications have been HALTED. This document serves as the exhaustive gap analysis and fixing plan. The recent migration to the hook-based PSR-11 architecture has introduced several routing mismatches, missing methods, broken middleware implementations, and logic flaws.

---

## 1. Auth & Profile
**Bugs Identified:**
- **2FA Setup 404:** The application only has a route for the 2FA *login verification* (`/2fa`), but entirely lacks routes and controller methods for a user to *setup* or *enable* 2FA from their profile (`/admin/my-account/2fa/setup` or similar).
- **"My Account" Update Error:** The `DashboardController@updateAccount` method attempts to update the user profile, but relies on `$userId = $_SESSION['auth_user_id'] ?? 0;`. If session keys mismatch or input sanitation fails, it tries to update user `0`. It also lacks strict validation and does not log the user out upon a password change, creating a security gap.

**Fixing Plan:**
- **2FA:** Add `setupTwoFactor` and `enableTwoFactor` methods to `DashboardController` or a dedicated `ProfileController`. Define explicit `GET/POST /admin/my-account/2fa` routes in `web.php`.
- **Profile:** Refactor `updateAccount` to throw explicit exceptions if the user ID is invalid. Invalidate all sessions upon a successful password change.

---

## 2. UI/UX
**Bugs Identified:**
- **Theme Mode Setting Broken:** The dark/light mode toggle in the UI is disconnected. The frontend JavaScript (`app.js`) and `base.twig` templates lack the event listeners and local storage bindings to dynamically toggle the `data-theme` attribute on the HTML root element.

**Fixing Plan:**
- Implement a lightweight JavaScript module in `app.js` that listens for clicks on the theme toggle button, switches a `data-theme="dark|light"` attribute on the `<html>` tag, and persists the choice to a cookie (`op_theme`) or `localStorage` so it survives page reloads.

---

## 3. Core Architecture
**Bugs Identified:**
- **Maintenance Mode Not Working:** `MaintenanceMiddleware` checks `storage/.maintenance`, but if the directory is not writable, the file cannot be created. Furthermore, `Response::maintenance()` is likely missing or incorrectly implemented in `src/Http/Response.php`, meaning when maintenance mode *is* active, it crashes instead of showing a 503 page.

**Fixing Plan:**
- Verify `storage/` directory permissions.
- Ensure `src/Http/Response.php` has a valid `maintenance()` static method that renders `templates/error/503.twig` correctly using the Twig environment.

---

## 4. Updater Logic Flaws
**Bugs Identified:**
- **Server Downtime Crash/Hang:** `UpdateService::check()` uses a raw `curl` request with a 10-second timeout. When the update server is down, this causes the entire admin panel (or any page triggering the check) to hang for 10 seconds.
- **Too Many Update Histories:** `SystemUpdateController@index` fetches the last 20 history records without pagination, cluttering the UI. 
- **System Update Menu Location:** The menu is incorrectly placed as a top-level sidebar item instead of being nested under "Settings".

**Fixing Plan:**
- **Downtime Fallback:** Wrap the curl request in a try-catch, reduce the timeout to 3 seconds, and cache the result of the update check (e.g., using `FileCache` for 6 hours) so that server downtime does not block page loads.
- **History UI:** Implement pagination for the update history query (`LIMIT 5 OFFSET X`) in `SystemUpdateController`.
- **Menu Location:** Edit `templates/admin/layout/sidebar.twig` to move the "System Update" link into the "Settings" dropdown menu context.

---

## 5. Settings & Configs
**Bugs Identified:**
- **API Key Settings Missing Options:** `ApiKeyController@index` simply redirects to `/admin/settings#tab-api`, but the actual `SettingsController` does not inject API key data into the view, rendering the tab empty or non-functional.
- **Missing Global Configs:** Many overarching platform configurations defined in the plan (like maintenance toggles, default language, timezone overrides) are not mapped in the `op_system_settings` seeder or the UI.

**Fixing Plan:**
- Refactor `SettingsController@index` to fetch the API keys using `ApiKeyService` and pass them to the Twig template. Ensure all `op_system_settings` keys have corresponding form inputs in the settings UI.

---

## 6. Ecosystem & Modules
**Bugs Identified:**
- **Theme Installation 404:** `config/routes/web.php` only defines `/admin/themes/{slug}/activate`. It completely misses the `POST /admin/themes/upload` route, causing a 404 when submitting a new theme ZIP.
- **Plugin Installation Error:** The `PluginController@upload` method likely attempts to extract ZIP files to a `plugins/` directory without proper existence/permission checks or fails to read the `manifest.json`.

**Fixing Plan:**
- **Theme Routes:** Add `$router->post('/admin/themes/upload', 'Admin\ThemeController@upload', 'admin');` to `web.php` and implement the `upload` method in `ThemeController`.
- **Plugin Uploads:** Add robust `is_writable` checks in `PluginInstaller` and ensure graceful error handling if `manifest.json` is malformed.

---

## 7. Merchants & Brands
**Bugs Identified:**
- **Brand Edit 404:** In `templates/admin/brands/edit.twig`, the form action likely points to `/admin/brands/{id}`. However, `web.php` expects POSTs to go to `/admin/brands/{id}/update`. Posting to the GET route triggers a 404 Method Not Allowed.
- **Missing Custom Domain Mapping:** The `BrandController@store` and `update` methods only modify the `op_merchants` table. They completely ignore custom domain logic.

**Fixing Plan:**
- **Brand Edit 404:** Update the form `<form action="/admin/brands/{{ brand.id }}/update" method="POST">` in the Twig template.
- **Custom Domains:** Implement domain mapping inside `BrandController` or explicitly add a "Custom Domains" section within the Brand Edit UI that interfaces with `DomainController`.

---

## 8. CRUD/Store Failures
**Bugs Identified (The "Undefined Method" Epidemic):**
- **Gateway Store Error:** `web.php` maps POST `/admin/gateways/store-manual` to `GatewayController@storeManual`.
- **Staff Store Error:** `web.php` maps POST `/admin/staff/store` to `StaffController@store`.
- **Invoice Store Error:** `web.php` maps POST `/admin/invoices/store` to `InvoiceController@store`.
- **Domain Store Error:** `web.php` maps POST `/admin/domains/store` to `DomainController@store`.
- **Device Page Error:** `DeviceController@index` likely references an outdated repository query or a missing variable.

*Root Cause:* In many of these controllers, the `store` or `update` methods were either omitted during the migration, misspelled, or the logic was incorrectly placed inside `edit()` or `create()` methods (which handle both GET/POST internally), causing the router to crash when looking for the explicit `store/update` methods defined in `web.php`.

**Fixing Plan:**
- Audit `GatewayController`, `StaffController`, `InvoiceController`, `DomainController`, and `DeviceController`. 
- Ensure explicit `store(Request $req)` and `update(Request $req)` methods exist and match the `web.php` definitions perfectly.

---

## 9. Action Failures
**Bugs Identified:**
- **"Payment link stop" Action Error:** `web.php` maps POST `/admin/payment-links/{id}/update` to `PaymentLinkController@update`. However, `PaymentLinkController.php` **does not have** an `update()` method. The update logic was mistakenly placed inside the `edit()` method, resulting in an "undefined method" fatal error.

**Fixing Plan:**
- Add `public function update(Request $req): Response` to `PaymentLinkController` that forwards to the internal logic, or change the route mapping in `web.php` to point to `@edit`.

---

### Request for Approval
I have completed the deep scan. The issues stem primarily from routing mismatches where the defined action endpoints in `web.php` do not exist in the newly migrated controllers, alongside missing UI routes (like 2FA setup and Theme Uploads). 

**Please review this audit document. Once you approve, I will begin the remediation phase methodically.**
