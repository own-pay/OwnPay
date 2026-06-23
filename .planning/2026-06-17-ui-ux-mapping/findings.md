# Findings & Decisions

## Requirements
- Identify why the system throws a 500 error after the user replaced templates, CSS, and JS.
- Check all modified templates and map missing or mismatched data values between the backend and frontend.
- Generate a comprehensive mapping document showing what each new value does, where it is used, and what is missing/mismatched.
- Save the mapping file under `docs/ui-ux-data-mapping.md`.

## Technical Findings

### 1. Root Cause of 500 Error
Twig is configured with `'strict_variables' => true` in `config/services.php:221`. Under this strict mode, Twig throws a fatal syntax/runtime exception whenever a template tries to access an undefined variable or an undefined key/property in an array/object. Accessing the new design system (Neoxa UI) features without backend support immediately triggers these fatal errors.

### 2. Crucial Global Mismatches (Causes 500 on all pages)
- **Notification Panel (`templates/admin/layout/notification_panel.twig`):** Included in `layout/base.twig` (line 27), rendering on every page. It loops over `notifications_bell` (`{% for notif in notifications_bell %}`). However, `notifications_bell` is never injected into the template context by `AdminPageTrait` or individual controllers.
- **Sidebar Brand Dropdown (`templates/admin/layout/sidebar.twig`):** Accesses `active_brand.color`, `active_brand.initials`, `b.color`, `b.initials`, and `b.description` in the brand switcher and dropdown. The `op_merchants` database table has no `color`, `initials`, or `description` columns, and `BrandContext::getAllBrands()` only selects `id`, `name`, `slug`, `logo_path`, and `status`.

### 3. Dashboard Mismatches (`templates/admin/dashboard.twig`)
- **Payment Intents Loop (`payment_intents`):** Loops over `payment_intents` (line 155), but `DashboardController::index()` does not pass it to the view.
- **KPI Card Trends:** Accesses `dashboard.payments_trend_percent`, `dashboard.revenue_trend_percent`, and `dashboard.customer_trend_percent`. The controller only passes `revenue_trend` and `customer_trend` (which are strings like `"+12.4% vs last month"`).
- **Today's Payments KPI Card:** Accesses `dashboard.today_count` and `dashboard.today_trend_percent`, which are not calculated or passed by the controller.
- **Doughnut/Gauge Chart Snapshot:** Accesses `dashboard.monthly_revenue`, `dashboard.gauge_target`, and `dashboard.gauge_percent`. None are provided by the controller.
- **Revenue Line Chart Data:** Passes `dashboard.revenue_chart_today`, `dashboard.revenue_chart_7d`, `dashboard.revenue_chart_30d`, and `dashboard.revenue_chart` (annually) to JS. The controller only passes `chart_data` as a flat array of week objects.
- **Transaction Description Mismatch:** Loops over `dashboard.recent_tx` and renders `tx.description` (line 125). The controller's mapped array for `recent_tx` does not include a `description` key.

### 4. Dispute Details Mismatches (`templates/admin/disputes/show.twig`)
- **Customer Name & Email:** Renders `transaction.customer_name` and `transaction.customer_email` (lines 94, 97). However, `DisputeController::show()` loads the transaction via `findScoped()`, which does a simple SELECT on `op_transactions` without joining the `op_customers` table, leaving these fields missing.

### 5. Transaction Details Mismatches (`templates/admin/transactions/edit.twig`)
- **Gateway Name & IP Address:** Renders `txn.gateway_name` (line 18) and `txn.ip_address` (line 33). However, `gateway_name` is not in the `op_transactions` table (it has `gateway_slug`), and `ip_address` is not a column in `op_transactions` and is not populated by the controller.

### 6. Payment Link Details Mismatches (`templates/admin/payment-links/edit.twig`)
- **Shipping Address Checkbox:** Accesses `link.require_address` (line 49). No such column exists in the `op_payment_links` table, and it is not handled in `PaymentLinkController` or `PaymentLinkService`.

### 7. Fee Rules Details Mismatches (`templates/admin/fee-rules/create.twig` & `edit.twig`)
- **Brand Name Display:** Accesses `active_brand.name` (line 33 in `create.twig`). If brand context is not resolved or is global, `active_brand` is null, causing a fatal error when accessing `active_brand.name`.

### 8. Missing Routing/Feature
- **Contributors Page (`templates/admin/contributors.twig`):** The template exists and loops over `contributors`, but there is no corresponding route or controller in the backend application.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Only mapping, no code changes | User request explicitly limits the scope to mapping missing/mismatched data values and saving it to a file in the `docs` folder. |
| Rely on actual codebase analysis | Must verify all variables against database schemas, repository files, and controller methods to ensure 100% accuracy without guessing or hallucinating. |

## Resources
- [activechanges.txt](file:///c:/laragon/www/ownpay/docs/frontend_contribution/activechanges.txt)
- [twig_value.md](file:///c:/laragon/www/ownpay/docs/frontend_contribution/docs/twig_value.md)
- [services.php](file:///c:/laragon/www/ownpay/config/services.php)
- [schema.sql](file:///c:/laragon/www/ownpay/database/schema.sql)


