# Forensic Browser Audit Report: UI, UX, and Frontend Defect Log

This report documents the newly discovered UI/UX, functional, and database-integration defects uncovered during the exhaustive end-user browser simulation of the OwnPay dashboard, merchant management, brand configuration, invoices, payment gateways, and checkout views.

---

## State-Tracking Matrix: Visited vs. Unvisited URLs
- **Auth Module:**
  - `https://ownpay.test/admin/my-account` (Visited - OK)
- **Dashboard Module:**
  - `https://ownpay.test/admin` (Visited - OK, minor report-only CSP warnings)
- **Merchant Management / Brand Config Module:**
  - `https://ownpay.test/admin/brands` (Visited - OK)
  - `https://ownpay.test/admin/brands/create` (Visited - Form validation verified)
  - `https://ownpay.test/admin/brands/{id}/edit` (Visited - **CRITICAL STATUS DB TRUNCATION BUG UNCOVERED**)
- **Invoice Creation Module:**
  - `https://ownpay.test/admin/invoices` (Visited - OK)
  - `https://ownpay.test/admin/invoices/create` (Visited - Created successfully)
  - `https://ownpay.test/admin/invoices/{id}` (Visited - **CRITICAL WIPED-OUT INVOICE TOTALS BUG UNCOVERED**)
- **Gateway Settings Module:**
  - `https://ownpay.test/admin/gateways` (Visited - **CRITICAL BROKEN LOGO PATH BUG UNCOVERED**)
  - `https://ownpay.test/admin/gateways/create-manual` (Visited - OK)
  - `https://ownpay.test/admin/plugins` (Visited - **CRITICAL BRICKED PLUGINS SANDBOX SCANNER BUG UNCOVERED**)
- **Checkout View / Payment Links Module:**
  - `https://ownpay.test/admin/payment-links` (Visited - OK)
  - `https://ownpay.test/pay/{slug}` (Visited - **CRITICAL EMPTY CSRF TOKEN BUG UNCOVERED - UNUSABLE CHECKOUT**)

---

## Newly Discovered Defect Log

### UI-001: Brand Status Database Truncation Crash
- **Target Component:** Merchant Management / Brand Configuration
- **Exact File & Line Numbers:**
  - Twig Template: [edit.twig](file:///c:/laragon/www/ownpay/templates/admin/brands/edit.twig#L33)
  - Database Schema: [ownpay.sql](file:///c:/laragon/www/ownpay/database/ownpay.sql#L440)
- **Flaw Description:**
  In the Brand Edit form, the HTML status combobox includes an option with `value="inactive"` (`<option value="inactive" ...>Inactive</option>`). However, the `op_merchants` status column in the database is defined as `enum('active','suspended','pending')`. The value `inactive` is missing from the database ENUM definition.
- **Exploit Scenario / Failure Mode:**
  When an admin edits a Brand (e.g. Test Brand) and selects the "Inactive" status, the database layer throws a strict mode warning/error: `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'status' at row 1`, crashing the application and displaying a raw PDO exception to the user.
- **Architectural Fix Plan:**
  1. Add `'inactive'` to the status ENUM list of the `op_merchants` table via a database migration.
  2. Alternatively, update `edit.twig` to use the database-compatible `suspended` value instead of `inactive` to represent the deactivated state.

---

### UI-002: Wiped-Out Invoice Subtotal and Total on Update
- **Target Component:** Invoice Creation / Invoice Management
- **Exact File & Line Numbers:**
  - Service Layer: [InvoiceService.php](file:///c:/laragon/www/ownpay/src/Service/Payment/InvoiceService.php#L123-L126)
  - Twig Template: [edit.twig](file:///c:/laragon/www/ownpay/templates/admin/invoices/edit.twig#L59-L61)
- **Flaw Description:**
  In `InvoiceService::update()`, the subtotal is calculated as: `$subtotal = array_sum(array_column($data['items'] ?? [], 'total'));`. However, the line item form fields are named `items[{{ i }}][description]`, `items[{{ i }}][quantity]`, and `items[{{ i }}][amount]`. Since there is no form input field named `total` for line items, the submitted `$data['items']` does not contain the `total` key. Thus, `array_sum(empty)` resolves to `0.00`, overwriting the parent invoice's subtotal and total in `op_invoices` to `0.00` BDT on save.
  Additionally, `InvoiceService::update()` lacks any database operations to update, delete, or sync the line items in the `op_invoice_items` table.
- **Exploit Scenario / Failure Mode:**
  Editing and saving any existing invoice successfully overwrites its subtotal and total to `0.00` in the database, breaking invoice tracking, billing reports, and ledger journals.
- **Architectural Fix Plan:**
  1. Update `InvoiceService::update()` to dynamically compute line item totals from the submitted quantity and amount, replicating the `create()` method logic:
     ```php
     $subtotal = 0;
     foreach ($data['items'] as &$item) {
         $qty = max(1, (int)($item['quantity'] ?? 1));
         $price = (float)($item['unit_price'] ?? $item['amount'] ?? 0);
         $item['quantity'] = $qty;
         $item['unit_price'] = $price;
         $item['total'] = $qty * $price;
         $subtotal += $item['total'];
     }
     ```
  2. Implement database queries inside `InvoiceService::update()` to delete existing `op_invoice_items` for the invoice and re-insert the updated line items.

---

### UI-003: Broken Manual Gateway Logo Path (Relative Path Bug)
- **Target Component:** Gateway Settings
- **Exact File & Line Numbers:**
  - Twig Template: [index.twig](file:///c:/laragon/www/ownpay/templates/admin/gateways/index.twig#L115)
- **Flaw Description:**
  The `index.twig` template renders a manual gateway's logo using: `<img src="{{ mg.logo_path }}" ...>`. The uploaded files are saved in the database under a relative directory schema (e.g. `gateways/2026/05/filename.jpg`). Because there is no leading slash `/` or public base URL prefix (such as `/uploads/` or `/storage/`), the browser resolves the image relative to the current URL pathname `/admin/gateways/`.
- **Exploit Scenario / Failure Mode:**
  Manual gateway logos are displayed as broken image icons. The browser makes requests to `https://ownpay.test/admin/gateways/gateways/2026/05/...` which returns a `404 Not Found` response.
- **Architectural Fix Plan:**
  Prefix the rendered image path with a leading slash `/storage/` or `/uploads/` depending on how the virtual host is configured, or ensure absolute URLs are saved:
  ```html
  <img src="/storage/{{ mg.logo_path }}" ...>
  ```

---

### UI-004: All Plugins Bricked with "Error" Status
- **Target Component:** Plugin System / API Gateways
- **Exact File & Line Numbers:**
  - Loader Layer: [PluginLoader.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginLoader.php#L160-L208)
  - Sandbox Definition: [PluginSandbox.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginSandbox.php#L81-L93)
- **Flaw Description:**
  During plugin activation/boot in `PluginLoader::loadPlugin()`, the code scans all PHP files inside the plugin directory and blocks standard built-in PHP functions like `header()`, `fwrite()`, `ini_set()`, `setcookie()`, and `mail()`.
- **Exploit Scenario / Failure Mode:**
  Every single activated plugin (bKash API, SSLCommerz, Stripe, Telegram Bot, Mail Gateway, SMS Gateway) instantly enters a bricked "Error" status upon activation. Because real-world integrations require operations like `header()` for redirects or `fwrite()` for standard streams, the security scanner blocks them and throws a `RuntimeException`, rendering the plugin architecture completely non-functional.
- **Architectural Fix Plan:**
  1. Revise the dangerous function list in `PluginSandbox::isDangerousFunction()` to allow standard, non-harmful operations like `header()`, `fwrite()`, `ini_set()`, and `setcookie()`, while retaining blocks on OS-level execution (`exec`, `shell_exec`, etc.).
  2. Transition to runtime-based capability checking instead of static code scans.

---

### UI-005: Empty CSRF Token Rendering Dynamic Checkout Dead and Unusable
- **Target Component:** Checkout View / Payment Links
- **Exact File & Line Numbers:**
  - Controller Layer: [PaymentLinkCheckoutController.php](file:///c:/laragon/www/ownpay/src/Controller/Checkout/PaymentLinkCheckoutController.php#L71)
- **Flaw Description:**
  In `PaymentLinkCheckoutController.php`, the controller attempts to retrieve the CSRF token from the session using: `$csrf = $_SESSION['csrf_token'] ?? '';`. However, the rest of the application (including the global CSRF middleware, security helpers, and Twig engines) stores the token under the key `$_SESSION['_csrf_token']` (with a leading underscore).
- **Exploit Scenario / Failure Mode:**
  When a customer visits a dynamic-amount payment checkout page, the CSRF token input field in the payment form is rendered with an empty value (`<input type=\"hidden\" name=\"_csrf_token\" value=\"\">`). Submitting the form immediately fails with a `403 Forbidden: CSRF validation failed` error, completely blocking all payment entries and rendering the checkout pipeline dead.
- **Architectural Fix Plan:**
  Change `PaymentLinkCheckoutController.php` to extract the correct CSRF token from the session or using the standard security helper:
  ```diff
  - $csrf = $_SESSION['csrf_token'] ?? '';
  + $csrf = \OwnPay\Security\SecurityHelpers::csrfToken();
  ```
