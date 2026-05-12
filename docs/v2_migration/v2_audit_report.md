# OwnPay V2 Migration Audit Report

**Date:** 2026-05-01
**Status:** COMPLETED
**Objective:** Comprehensive line-by-line audit of the OwnPay v0.1.0 codebase to verify V2 migration completeness, identify missing business logic, locate incomplete tasks, and detect security vulnerabilities.

---

## 1. Executive Summary

The V2 migration has successfully established the core architectural foundations (PSR-11 DI container, Twig templating, unified webhook router). However, the migration is **critically incomplete**. A significant number of legacy procedural files, directories, and dependencies remain in the codebase despite being marked as "Done" in the `task.md` tracker. 

Most alarmingly, there is a **fatal architectural regression**: legacy controllers and helper functions that were *not* deleted are attempting to call `CrudService`, which *was* deleted. This will result in application-crashing fatal errors if any of these legacy paths are invoked.

---

## 2. Incomplete Tasks & False Positives in `task.md`

The following tasks were incorrectly marked as `[x]` (Done) in `task.md` and require immediate remediation:

| Task ID | Description | Audit Finding |
|---------|-------------|---------------|
| **M10b** | Delete `app/admin/` | **FAILED.** Directory exists with `index.php`, `login.php`, `dashboard/`, etc. |
| **M10c** | Delete `app/modules/gateways/` | **FAILED.** Directory exists containing 46 legacy gateways. |
| **M10d** | Delete `app/modules/themes/` | **FAILED.** Directory exists. |
| **M10e** | Delete `app/modules/plugins/` | **FAILED.** Directory exists. |
| **M10f** | Delete `app/install/` | **FAILED.** Directory exists with `index.php` and `master_install.sql`. |
| **M10g** | Delete `errors/` | **FAILED.** Directory exists with `404.php`, `maintenance.php`, etc. |
| **M10h** | Delete `scripts/` | **FAILED.** Directory exists with `restructure.php`. |
| **M10j** | Delete `src/Core/helpers.php` | **FAILED.** File exists (21KB) and contains legacy procedural wrappers. |
| **M10k** | Delete `src/Core/ActionDispatcher.php`| **FAILED.** File exists (8.6KB). |
| **M10l** | Delete `src/Core/ContentLoader.php` | **FAILED.** File exists (4.2KB). |
| **M10n** | Delete `UpdaterService.php` | **FAILED.** File exists in `src/Service/System/`. |
| **M1** | Branding Sweep (`pp_`, `ap_`, etc.) | **FAILED.** Over 228 instances of legacy prefixes were found in the codebase. |

---

## 3. Missing Business Logic & Fatal Flaws

### 3.1 The `CrudService` Fatal Error
- **Finding:** `src/Service/System/CrudService.php` was successfully deleted (Task B21). However, `src/Core/helpers.php` and `src/Controller/Admin/BrandController.php` were *not* deleted.
- **Impact:** Both of these leftover files heavily rely on `\OwnPay\Service\System\CrudService`. Any execution path hitting `BrandController` or functions in `helpers.php` will trigger a `Class Not Found` PHP Fatal Error, crashing the application.
- **Missing Logic:** Complete removal of `BrandController.php` (replaced by `MerchantController.php` in V2) and complete removal of all references to `helpers.php`.

### 3.2 Legacy Webhook / IPN Redundancy
- **Finding:** While `UnifiedWebhookController` was implemented, legacy `IpnController.php.deprecated` files still exist in `src/Controller/Webhook/` and `src/Controller/Api/`.
- **Impact:** Architectural confusion and potential duplicate routing if older router files or legacy integrations are still pointing to IPN endpoints.

---

## 4. Security & Vulnerability Audit (OWASP)

### 4.1 Sensitive Data Exposure (OWASP Top 10: A01:2021-Broken Access Control)
- **Finding:** The `app/install/` directory is still present in the codebase. It contains `master_install.sql`.
- **Vulnerability:** If the web root is not strictly mapped to `public/`, an attacker can access `app/install/master_install.sql` to map the entire database schema and potentially extract default super-admin credentials.
- **Severity:** **HIGH**

### 4.2 Leftover Administrative Scripts (OWASP Top 10: A05:2021-Security Misconfiguration)
- **Finding:** The `scripts/` directory contains `restructure.php` and `replace_procedural.php`.
- **Vulnerability:** These scripts execute codebase mutations. If exposed to the web, an unauthenticated attacker could trigger codebase restructuring, leading to Denial of Service (DoS) or arbitrary code execution if the scripts contain unsafe filesystem operations.
- **Severity:** **HIGH**

### 4.3 Broken Authentication / Legacy Routes
- **Finding:** `app/admin/login.php` and `app/admin/2fa.php` still exist.
- **Vulnerability:** These legacy procedural files likely bypass the new V2 `Middleware` pipeline (CSRF, Rate Limiting, new Session handling). If accessible, they represent an authentication bypass or a vector for brute-force attacks.
- **Severity:** **CRITICAL**

### 4.4 `.env` vs `op-config.php` Configuration Gap
- **Finding:** `.env` is under-populated and `op-config.php` is still managing database configurations despite the pivot to use `.env` exclusively via `phpdotenv`.
- **Vulnerability:** Secrets might be hardcoded in leftover config files rather than securely injected via environment variables, risking credential leakage if PHP execution fails and files are served as plaintext.
- **Severity:** **MEDIUM**

---

## 5. Recommended Remediation Plan

To achieve true V2 parity and secure the system, the following immediate actions are required:

1. **Execute the Scorched-Earth Deletion Protocol:**
   - Run `rm -rf app/admin app/modules/gateways app/modules/themes app/modules/plugins app/install errors scripts`
   - Delete `src/Core/helpers.php`
   - Delete `src/Core/ActionDispatcher.php`
   - Delete `src/Core/ContentLoader.php`
   - Delete `src/Service/System/UpdaterService.php`
   - Delete `src/Controller/Admin/BrandController.php`
   - Delete `src/Controller/Webhook/IpnController.php.deprecated`
   - Delete `src/Controller/Api/IpnController.php.deprecated`

2. **Fix the CrudService Dependency Loop:**
   - Ensure absolutely no remaining controllers, services, or middleware depend on `CrudService`. All DB operations must route through `BaseRepository` implementations.

3. **Complete the Branding Sweep:**
   - Execute a strict regex search-and-replace for `\bap_` and `\bpp_` replacing them with `op_`. Focus specifically on database schema references, variable names, and Twig template attributes.

4. **Web Root Enforcement:**
   - Verify that the production server configuration (Nginx/Apache) strictly limits the document root to the `public/` directory, rendering the `src/` and leftover `app/` folders inaccessible from the internet.
