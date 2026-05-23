---
trigger: always_on
---

# Developer Workflows & Implementation Rules

This ruleset governs standard developer workflows, installer wizard logic, page/service addition procedures, and highly specific implementation gotchas to prevent regressions across the OwnPay codebase.

## 1. Common Developer Workflows

### 1.1 Adding a New Admin Page & Route
To introduce a new admin-level panel or CRUD interface:
1. **Controller Creation:** Create the controller in `src/Controller/Admin/` extending the standard base and utilizing `OwnPay\Controller\Admin\AdminPageTrait`.
2. **Dependency Injection:** Inject required repositories and services using the PSR-11 constructor format (autowired).
3. **Route Declaration:** Map the route strictly under the `/admin/` prefix inside `config/routes/web.php` and assign it to the `admin` middleware group.
4. **RBAC Mapping:** Explicitly map permissions inside `OwnPay\Middleware\PermissionMiddleware::resolvePermission()`.
5. **Sidebar Entry:** Place the sidebar link under the correct section in `templates/admin/layout/sidebar.twig`.

### 1.2 Admin Sidebar Structure (v0.1.0)
The sidebar navigation links MUST strictly follow this exact order:
```
Dashboard → Payments → Gateways → People → Mobile & SMS → Reports & Finance → Developers → Appearance → System → Account
```
* **People:** Contains Brands, Customers, Staff, Roles & Permissions.
* **Developers:** Contains API Keys, Endpoint Reference, Webhooks/IPN, Documentation, Rate Limits.
* **Reports:** Contains Reports, Audit Log, Balance Verification.
* **Appearance:** Contains Branding, Landing Page, Themes.

### 1.3 Adding Repositories & Services
* **Repositories:** Create in `src/Repository/` extending `BaseRepository`. If scoped by brand, the class MUST use `TenantScope`. The container registers the class automatically via PSR-4 autowiring.
* **Services:** Create in `src/Service/{Domain}/`. If the constructor requires non-autowirable parameters, register the service explicitly in `config/services.php`.

---

## 2. Installer Wizard Architecture

All installer actions are controlled via `OwnPay\Controller\Install\InstallerController.php` implementing a 4-step workflow:

| Step | Controller Method | Purpose & Guardrails |
|---|---|---|
| **1** | `show(?step=1)` | Verifies write permissions on critical directories and checks PHP extensions. |
| **2** | `testDatabase()` | Probes database credentials, constructs the schema, and stores values temporarily in `storage/.env.temp`. |
| **3** | `createAdmin()` | Bootstraps the first superadmin user and default brand. Blocks if `.env.temp` is missing. |
| **4** | `finalize()` | Generates critical environment keys, writes the final `.env`, seeds settings, and generates the `storage/.installed` locking marker. |

### Security & Boot constraints for the Installer
* **Database Independence:** The `install` middleware group must **never** reference any database-dependent middleware (e.g., Session, Settings, or Database-driven Rate Limiters) as the database does not exist during initial setup.
* **Rate Limiter Bypass:** `RateLimiterMiddleware` must encapsulate database hits inside a try/catch block to silently bypass errors when the database is unavailable.
* **Base64 Key Parsing:** **Never** use `parse_ini_file()` to parse the final `.env` file. Keys like `APP_KEY` and `ENCRYPTION_KEY` contain base64 `=` characters which break the PHP ini parser. Use `vlucas/phpdotenv` or read from `storage/.env.temp` during installation.
* **Locking:** Ensure `/install/*` routes return an immediate redirect/404 if `storage/.installed` exists.

---

## 3. Specific Gotchas & Bug Prevention

### 3.1 Twig Enum Alignment on Status Forms
When writing or editing Merchant (Brand) status forms:
* The status input options must strictly map to the `op_merchants` status column enum values: `('active', 'suspended', 'pending')`.
* **Never** use the option label `"inactive"` as it does not exist in the database schema and will trigger PDO truncation warnings and crash upon saving.

### 3.2 Manual Gateway Logo Prefixes
All manual payment gateway logo paths (`logo_path`) and QR code paths (`qr_path`) rendered in Twig templates (such as `manual-gateway.twig`, `edit-manual.twig`) MUST be prefixed with `/storage/`:
```html
<!-- Correct -->
<img src="/storage/{{ mg.logo_path }}" alt="Logo">

<!-- Incorrect - leads to 404 broken image paths on nested admin routes -->
<img src="{{ mg.logo_path }}" alt="Logo">
```

### 3.3 Dynamic Invoice Calculations & Line Items Purging
`InvoiceService::update()` MUST dynamically recalculate subtotals and totals from the current request inputs rather than saving static values.
* Loop over the line items to extract and compute the quantity and price variables.
* **Purge & Sync:** The update routine must purge old line items from `op_invoice_items` and insert fresh ones to avoid orphan records or stale line item arrays.
* Saving an invoice without performing this dynamic update will cause subtotals/totals to overwrite to `0.00` BDT.

### 3.4 Device Pairing Token & Notification UUID Casting
* **Pairing Fallback:** `DevicePairingService` must query the `created_by` or `admin` owner column when mapping JWT claims. If the column is null, it must gracefully fallback to the default superadmin ID `1` to prevent pairing crashes.
* **Acknowledge Isolation:** Notification acknowledgments in `MobileNotificationRepository::acknowledgeIds()` must always filter by `$deviceUuid` to prevent Cross-Brand/Cross-Device IDOR.
* **String Casting on UUIDs:** When retrieving `device_id` from request attributes in API/Mobile controllers (such as `NotificationController`), always cast it strictly to `(string)`. Casting a UUID string to an `(int)` results in `0`, bricking all device-specific query filters.

### 3.5 Plugin Management & Slug Enrichment
* **Two-Pass Enrichment:** When listing plugins, the name must be resolved via a two-pass enrichment: first checking the database `op_plugins.manifest` JSON, and falling back to the filesystem using `PluginLoader::discover()`. Prefer filesystem names.
* **Theme Slug Mismatch:** The default theme plugin `own-pay-theme` has a manifest slug of `own-pay`. The system settings `active_theme` and the database records must match `own-pay` exactly, not `own-pay-theme`.
