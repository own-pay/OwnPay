# OwnPay UI/UX Data Mapping & 500 Error Analysis

This document maps the variables and structures introduced in the new UI/UX design (Neoxa Design System) against the actual OwnPay PHP backend controllers and MySQL database schema. It lists all missing and mismatched data values that cause page crashes, explains what each variable is intended to do, and details the backend changes required to resolve the errors.

---

## 1. Root Cause of the 500 Error
The 500 Server Error is caused by Twig's strict variables configuration in the backend:
- File: `config/services.php` (Line 221):
  ```php
  'strict_variables' => true,
  ```
- **Behavior:** With `strict_variables` set to `true`, Twig throws a fatal runtime exception whenever a template accesses an undefined variable, a null variable, or a key that does not exist in an array. 
- **Trigger:** The designer added **82+ new mock keys** to the frontend sandbox (under `docs/frontend_contribution/mock_data.json`) to populate the Neoxa UI. The actual PHP controllers do not pass these variables or array keys to the Twig rendering context, leading to fatal crashes across the entire administrative panel.

---

## 2. Crucial Global Mismatches (Affects Every Admin Page)
Because these templates are included in the base layout (`layout/base.twig`), these mismatches cause a 500 error on **every single admin page**.

### A. Slide-Out Notification Panel
- **Template File:** [notification_panel.twig](file:///c:/laragon/www/ownpay/templates/admin/layout/notification_panel.twig)
- **Controller/Trait:** [AdminPageTrait.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/AdminPageTrait.php) (`renderAdminPage`)
- **Mismatched Variable:** `notifications_bell`
- **Description:** An array of recent notification objects (ID, type, title, message, time, read, icon) looped over on line 7 (`{% for notif in notifications_bell %}`).
- **Current Status:** **Missing**. It is never queried or passed to the context.
- **Required Backend Change:** Update `AdminPageTrait::renderAdminPage()` to query recent logs (e.g., from `op_audit_logs`, `op_comm_log`, or a future notifications table) and pass them as `notifications_bell`.

### B. Sidebar Brand Switcher & Dropdown
- **Template File:** [sidebar.twig](file:///c:/laragon/www/ownpay/templates/admin/layout/sidebar.twig)
- **Service Class:** [BrandContext.php](file:///c:/laragon/www/ownpay/src/Service/Brand/BrandContext.php) (`getAllBrands` and `getActiveBrand`)
- **Mismatched Keys:** 
  - `active_brand.color` (Line 21) & `b.color` (Line 40)
  - `active_brand.initials` (Line 22) & `b.initials` (Line 41)
  - `b.description` (Line 45)
- **Description:** Visual customization parameters (brand custom color, initials avatar, and description) for the brands listed in the dropdown switcher.
- **Current Status:** **Missing**. The `op_merchants` database table does not contain `color`, `initials`, or `description` columns. `BrandContext::getAllBrands()` only selects `id, name, slug, logo_path, status`.
- **Required Backend Change:** Add columns to `op_merchants` via a migration, or save these properties under the merchant `settings` JSON column and extract them in `BrandContext`.

---

## 3. Page-Specific Mismatches

### A. Dashboard Page
- **Template File:** [dashboard.twig](file:///c:/laragon/www/ownpay/templates/admin/dashboard.twig)
- **Controller Class:** [DashboardController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/DashboardController.php) (`index` method)
- **Mismatches:**
  1. `payment_intents` (Line 155): Loops over payment intents to render the "Recent Payment Intents" list. **Missing** (the controller does not pass `payment_intents` to this view).
  2. `dashboard.payments_trend_percent` (Line 34): Trend percentage for payments card. **Missing** (the controller passes `revenue_trend` and `customer_trend` as full strings, but doesn't pass this key).
  3. `dashboard.revenue_trend_percent` (Line 48): Trend percentage for revenue card. **Missing**.
  4. `dashboard.customer_trend_percent` (Line 60): Trend percentage for new customers card. **Missing**.
  5. `dashboard.today_count` (Line 69) & `dashboard.today_trend_percent` (Line 74): Metrics for the "Today's Payments" KPI card. **Missing**.
  6. `dashboard.monthly_revenue` (Line 142) & `dashboard.gauge_target` (Line 143): Snapshot details for the monthly revenue gauge label. **Missing**.
  7. `dashboard.gauge_percent` (Line 250): Gauge doughnut fill percentage. **Missing**.
  8. `dashboard.revenue_chart_today` (Line 191), `dashboard.revenue_chart_7d` (Line 192), `dashboard.revenue_chart_30d` (Line 193), `dashboard.revenue_chart` (Line 194): Datasets passed to Chart.js. **Missing** (the controller only passes a week-based `chart_data` array).
  9. `tx.description` (Line 125): Loop variable inside `dashboard.recent_tx`. **Mismatched Key** (the mapped array in the controller does not include a `description` key).
- **Required Backend Change:** Calculate today's volume, monthly revenue targets, percent metrics, and query the line chart datasets for different time intervals in `DashboardController::index()`. Populate the `description` key in `recent_tx` and query recent payment intents.

### B. Dispute Details Page
- **Template File:** [show.twig](file:///c:/laragon/www/ownpay/templates/admin/disputes/show.twig)
- **Controller Class:** [DisputeController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/DisputeController.php) (`show` method)
- **Mismatched Keys:** `transaction.customer_name` (Line 94) & `transaction.customer_email` (Line 97)
- **Description:** Customer details for the transaction associated with the dispute.
- **Current Status:** **Missing**. `DisputeController::show()` fetches the transaction using `findScoped()`, which does a simple SELECT on `op_transactions` without joining the `op_customers` table.
- **Required Backend Change:** Modify `DisputeController::show()` to load the customer details associated with the transaction (using `CustomerRepository` and decrypting the fields).

### C. Transaction Details Page
- **Template File:** [edit.twig](file:///c:/laragon/www/ownpay/templates/admin/transactions/edit.twig)
- **Controller Class:** [TransactionController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/TransactionController.php) (`show` method)
- **Mismatched Keys:** `txn.gateway_name` (Line 18) & `txn.ip_address` (Line 33)
- **Description:** Human-readable name of the gateway used and customer IP address.
- **Current Status:** **Missing**. `gateway_name` is not in `op_transactions` (only `gateway_slug` exists) and is not joined in the controller. `ip_address` does not exist as a column in the `op_transactions` table.
- **Required Backend Change:** In `TransactionController::show()`, look up the gateway name in the gateway repositories and populate it on the `txn` array. Read the IP address from the transaction metadata JSON and assign it to the `ip_address` key.

### D. Payment Link Edit Page
- **Template File:** [edit.twig](file:///c:/laragon/www/ownpay/templates/admin/payment-links/edit.twig)
- **Controller Class:** [PaymentLinkController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/PaymentLinkController.php)
- **Mismatched Key:** `link.require_address` (Line 49)
- **Description:** A boolean setting determining whether the checkout flow requires customer shipping address.
- **Current Status:** **Missing**. No `require_address` column exists in the `op_payment_links` database table, and it is not handled in the controller or service layer.
- **Required Backend Change:** Add a `require_address` (TINYINT) column to `op_payment_links` via migration, and update `PaymentLinkController` and `PaymentLinkService` to store and load it.

### E. Fee Rules Creation & Edit Pages
- **Template Files:** `fee-rules/create.twig` & `fee-rules/edit.twig`
- **Controller Class:** [FeeRuleController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/FeeRuleController.php)
- **Mismatched Key:** `active_brand.name` (Line 33 in `create.twig`)
- **Description:** Displays the active brand name as a read-only field when creating a brand-scoped fee rule.
- **Current Status:** **Missing/Null**. In global view or if the context is unresolved, `active_brand` is null, causing a crash when Twig attempts to access `active_brand.name`.
- **Required Backend Change:** Enforce fallback values in Twig (e.g. `{{ active_brand.name|default('System') }}`) or ensure a safe dummy structure is passed to the view.

---

## 4. Missing Pages / Routing

### A. Contributors Page
- **Template File:** `templates/admin/contributors.twig`
- **Route / Controller:** **None**.
- **Description:** The template exists and loops over `contributors`, but there is no matching route in `config/routes/web.php` or backend controller to serve this page.
- **Required Backend Change:** Map `/admin/contributors` in `config/routes/web.php` and define a controller action to render the page with contributor statistics.

---

## 5. Overview Mapping Table

| Template File | Variable / Array Key | Type / Source | Intended Function | Status | Required Backend Action |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **base.twig** / **notification_panel.twig** | `notifications_bell` | Array (Object) | Lists top navbar notifications | **Missing** | Query recent audits/comms in `AdminPageTrait` and pass to view |
| **sidebar.twig** | `active_brand.color` / `b.color` | String (Hex) | Custom color for brand switcher visual identity | **Missing** | Add color column to `op_merchants` table or settings JSON |
| **sidebar.twig** | `active_brand.initials` / `b.initials` | String (2 chars) | Brand avatar initials | **Missing** | Add column or compute initials dynamically from merchant name |
| **sidebar.twig** | `b.description` | String | Short brand description | **Missing** | Add description column to `op_merchants` |
| **dashboard.twig** | `payment_intents` | Array (Object) | Recent payment intents list | **Missing** | Fetch from `PaymentIntentRepository` in `DashboardController` |
| **dashboard.twig** | `dashboard.payments_trend_percent` | String | Subtitle trend badge | **Missing** | Calculate trend in `DashboardController` and pass separately |
| **dashboard.twig** | `dashboard.revenue_trend_percent` | String | Subtitle trend badge | **Missing** | Pass revenue trend percentage |
| **dashboard.twig** | `dashboard.customer_trend_percent` | String | Subtitle trend badge | **Missing** | Pass customer trend percentage |
| **dashboard.twig** | `dashboard.today_count` | Integer | Today's payment count | **Missing** | Count today's transactions in repository and pass |
| **dashboard.twig** | `dashboard.today_trend_percent` | String | Subtitle trend badge | **Missing** | Calculate today's volume trend and pass |
| **dashboard.twig** | `dashboard.monthly_revenue` | String | Gauge center label value | **Missing** | Calculate current month's revenue and pass |
| **dashboard.twig** | `dashboard.gauge_target` | String | Gauge center target label | **Missing** | Read monthly revenue target from settings and pass |
| **dashboard.twig** | `dashboard.gauge_percent` | Integer (0-100) | Doughnut chart percentage | **Missing** | Compute percentage of target met and pass |
| **dashboard.twig** | `dashboard.revenue_chart_*` | Array (JSON) | Multi-range Chart.js datasets | **Missing** | Construct Today/7d/30d/All datasets in controller and pass |
| **dashboard.twig** | `tx.description` | String | Transaction column value | **Missing** | Populate `description` in recent transaction mapping |
| **disputes/show.twig** | `transaction.customer_name` | String | Customer name | **Missing** | Join `op_customers` table in dispute transaction query |
| **disputes/show.twig** | `transaction.customer_email` | String | Customer email | **Missing** | Join `op_customers` table in dispute transaction query |
| **transactions/edit.twig** | `txn.gateway_name` | String | Gateway display name | **Missing** | Query gateway repository in `TransactionController::show` |
| **transactions/edit.twig** | `txn.ip_address` | String | Customer IP address | **Missing** | Add IP column to `op_transactions` or parse from metadata |
| **payment-links/edit.twig** | `link.require_address` | Boolean (Tinyint) | Requires shipping address | **Missing** | Add `require_address` column to `op_payment_links` table |
| **fee-rules/create.twig** | `active_brand.name` | String | active brand name label | **Null Crash** | Fallback in Twig via default filter or pass safe object |
| **contributors.twig** | *Entire template* | Route / Controller | Renders contributor logs | **Missing Route** | Add route to `config/routes/web.php` and controller |
