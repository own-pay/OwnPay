# Findings & Decisions

## Requirements
- Identify all missing admin UI views/fields where backend logic exists but no GUI is present.
- Create a comprehensive markdown report listing all identified missing GUI features or form fields.

## Research Findings
### 1. Database Schema & Tables Checked
- `op_merchants` (Brand profile options: `timezone`, `default_currency`, `status`)
- `op_roles`, `op_permissions`, `op_role_permissions`, `op_merchant_users`
- `op_domains` (`type` ENUM: `checkout`, `admin`, `api`; `verification_token`, `dns_verified`, `ssl_status` ENUM, `is_primary`)
- `op_manual_gateways` (`sms_verification`, `sms_sender_pattern`, `sms_regex_template` fields present in schema)
- `op_system_settings` (runtime settings with `merchant_id` option for brand override)
- `op_transactions` (`method` ENUM, `status` ENUM, generated columns for `invoice_id` and `payment_link_id`)
- `op_login_attempts` (tracks success/failure per email/IP)
- `op_webhooks` & `op_webhook_events` & `op_webhook_deliveries` (designed for multiple webhooks, events, logs)
- `op_paired_devices`, `op_sms_templates`, `op_sms_parsed`
- `op_comm_log` (channels: `sms`, `email`, `telegram`, `webhook`)
- `op_webhook_deliveries` (tracks inbound & outbound webhook deliveries)
- `op_refunds` (tracks refund entries)
- `op_disputes` (`evidence` JSON DEFAULT NULL)
- `op_merchant_users` (fields: `phone`, `username`, `avatar_path`, `status` ENUM, `two_factor_enabled`)

### 2. Routes & Templates Discovery
- Checked `config/routes/web.php` routes. Routes exist for:
  - `/admin/audit-log` or `/admin/activities` -> `ActivitiesController@index`
  - `/admin/audit-integrity` -> `AuditIntegrityController@scan`
  - `/admin/webhooks/events` -> `WebhookEventController@index`
  - `/admin/webhooks/events/{id}/logs` -> `WebhookEventController@logs`
  - `/admin/webhooks/events/{id}/replay` -> `WebhookEventController@replay`
  - `/admin/balance-verification` -> `BalanceVerificationController@index`
  - `/admin/sms-data` -> `SmsDataController@index`
  - `/admin/sms-center` -> `SmsTemplateAdminController@index`
  - `/admin/developer` -> `DeveloperController@index`
  - `/admin/settings/{tab}` -> `SettingsController@tab`
- Checked `templates/admin/` files and directories, finding:
  - `activities.twig`, `audit_integrity.twig`, `balance-verification.twig`, `sms-data.twig`, `reports.twig`
  - Folders for `brands`, `customers`, `dashboard`, `developer`, `devices`, `disputes`, `domains`, `fee-rules`, `gateways`, `invoices`, `layout`, `ledger`, `payment-links`, `plugins`, `roles`, `settings`, `sms-center`, `staff`, `themes`, `transactions`, `webhooks`, `addons`.

### 3. Verification of Brand Profile / Appearance Settings (`templates/admin/brands/edit.twig`)
- Found that `templates/admin/brands/edit.twig` contains fields for Business Name, Contact Email, Phone, Status, Timezone, Default Currency, Custom Domain, Brand Logo, Brand Favicon, Primary Theme Color, Accent Theme Color, Support Contact Email, Custom Checkout Footer Text, show_powered_by, Custom CSS, and Custom JS.
- **Missing GUI Field (Brand Profile):** There is NO drop-down or selection field in the UI for the brand's default language. According to `ADMIN-PANEL-MAP.md` Part 2.1, "Default Language | The language this brand's checkout shows to customers" should be customizable per brand.
- **Missing GUI Fields (Brand Checkout Message Overrides):** `ADMIN-PANEL-MAP.md` Part 2.2 states: "Checkout Success/Pending/Failed Message | Override the global messages specifically for this brand". None of these fields exist in the brand edit visual customization section.

### 4. Verification of Custom Domains Settings (`templates/admin/domains/index.twig`)
- Found that `templates/admin/domains/index.twig` lists mapped domains with columns for Domain, Brand, Verification Token, Status, and Verify/Delete buttons.
- **Missing GUI Field (Domain Type):** The domain creation form modal contains only a "Domain Name" input field. The table field `type` (ENUM checkout/admin/api) from `op_domains` is not editable or selectable in the GUI.
- **Missing GUI Control (Primary Domain Toggle):** No control or action exists to mark a domain as primary, corresponding to the `is_primary` boolean column in `op_domains`.
- **Missing GUI Display (SSL Status):** The SSL status (`ssl_status` column in `op_domains`) is completely omitted from the domains list.
- **Missing GUI Field (Redirect URL):** The `redirect_url` column in `op_domains` is not editable or viewable in the domains UI.

### 5. Verification of Manual Gateways Settings (`templates/admin/gateways/edit-manual.twig` & `create-manual.twig`)
- Found that these templates manage name, slug, instructions, logo/qr-code, colors, min/max limits, `sms_verification` checkbox, and custom input fields.
- **Missing GUI Fields (SMS Parsing Fields):** Although there is a checkbox to "Enable SMS Verification", there are NO input fields to specify `sms_sender_pattern` and `sms_regex_template` (columns in `op_manual_gateways` table) inside the forms. These are completely missing from the GUI.

### 6. Mobile Config API Discrepancy (Global 1.7 / Brand 2.9)
- `ConfigController.php` queries the `sms` settings group for:
  - `positive_keywords`
  - `negative_keywords`
  - `filter_rules_check_interval_hours`
- However, `SettingsController.php` saves these values inside the `general` settings group with prefix names: `sms_positive_keywords`, `sms_negative_keywords`, `sms_filter_rules_check_interval_hours`. Because of this key mismatch, the companion mobile app receives only default hardcoded fallback keyword values rather than the admin-configured ones.

### 7. Verification of Webhooks Settings (`templates/admin/developer/index.twig`)
- Under the "Webhooks / IPN" tab, there is a single URL field and secret key field saved under the `general` settings group.
- **Missing GUI (Multiple Webhook Management):** The database schema defines `op_webhooks` supporting multiple webhook endpoints per brand. The application has `WebhookRepository` and `WebhookService`, but there is no CRUD GUI (or controller actions) to manage the multiple webhook endpoints stored in the `op_webhooks` table. The GUI restricts the user to a single global/brand setting saved in runtime settings.

### 8. Verification of Outbound Communication Logs / SMS Queue (`templates/admin/sms-center/index.twig`)
- Outbound SMS queue is loaded from `op_comm_log` where `channel = 'sms'`.
- **Missing GUI (General Communication Log):** While the SMS queue is shown under the SMS Center tab, there is no generic Communication Log view (as listed in `ADMIN-PANEL-MAP.md` Part 3: "Communication Log | History of every email and SMS sent by this brand"). Outbound emails (where `channel = 'email'` in `op_comm_log`) are not viewable anywhere in the Admin panel.
- **Missing GUI Actions (Retry Queue):** `CommLogRepository` implements `retrySms(id, merchantId)`, but there are no GUI controls or routes to trigger retrying a failed SMS from the SMS Center queue list.

### 9. Verification of Webhook Deliveries Log (`templates/admin/webhooks/events.twig`)
- Webhook Events / DLQ tab loads from the `op_webhook_events` table (for queued webhook dispatch jobs).
- **Missing GUI (Webhook Deliveries Log):** Outbound and inbound raw webhook deliveries are logged into the `op_webhook_deliveries` table. Although the Merchant API supports listing these via `WebhookController::deliveries()`, there is NO GUI template or view (nor admin routes) in the Admin panel to display this history as listed in `ADMIN-PANEL-MAP.md` Part 3: "Webhook Deliveries | Log of every outbound event notification sent to the brand's webhook endpoints".

### 10. Checkout Message Overrides & Group Mismatch (Part 4 / Global 1.5)
- **Missing GUI Fields & Loader (Brand Level):** `ADMIN-PANEL-MAP.md` Part 2.2 states each brand can override checkout success/pending/failed messages. However, `BrandThemeService::getBrandTheme` only fetches settings in the `theme` group, completely ignoring any brand-specific checkout message overrides. Additionally, the brand edit template (`edit.twig`) lacks inputs for these overrides.
- **Global Settings Group Mismatch:** `SettingsController::saveCheckout` saves global checkout messages (`checkout_success_msg`, `checkout_pending_msg`, `checkout_failed_msg`) to the `checkout` settings group. However, `CheckoutController.php` (line 377) attempts to load `checkout_success_msg` from the `general` group, causing checkout forms using `CheckoutController` to show empty or default hardcoded messages instead of the global configured ones.

### 11. Ledger / Accounting GUI Bug & Schema Mismatch (`templates/admin/ledger/index.twig`)
- **Missing GUI Details (Double-Entry View):** The ledger template is a flat list of transaction rows rather than displaying standard debit/credit journal entries from `op_ledger_entries`. There is no detailed view to show standard accounting journal breakdowns.
- **Backend Schema Mismatch / Bug:** The template tries to output `item.event_type`, `item.currency`, `item.total_amount`, and `item.status`. However, `LedgerRepository::entriesPaginated` SQL query only selects `lt.id`, `lt.uuid`, `lt.description`, `lt.reference_type`, `lt.reference_id`, `lt.created_at`, and `entries_summary`. It does NOT fetch `event_type`, `currency`, `total_amount`, or `status`, making these columns completely blank in the GUI.

### 12. Missing Refunds GUI & Controller (Part 3)
- **Missing GUI & Admin Routes:** In the database, the `op_refunds` table exists, and an API controller (`Api\RefundController.php`) is implemented to handle refunds for the Merchant API. However, there is no Admin GUI template, no admin-level `RefundController.php`, and no web routes in `config/routes/web.php` to display or manage refunds in the Admin panel.

### 13. Verification of Disputes (`templates/admin/disputes/show.twig`)
- **Missing GUI for Structured Evidence:** The database table `op_disputes` contains a JSON-typed `evidence` column for structured evidence data (which is standard for payment gateways). However, the Admin resolution UI only provides a single plain text area (`resolution` form field) to write raw text resolution notes, lacking fields or buttons to attach structured evidence fields or upload files (such as receipt images or shipping tracking).

### 14. Verification of Team / Staff Settings (`templates/admin/staff/edit.twig`)
- Found that `templates/admin/staff/edit.twig` handles Name, Email, Password, and Role selection.
- **Missing GUI Control (Staff Status Toggle):** The database table `op_merchant_users` contains a `status` ENUM ('active', 'suspended', 'pending'). However, the Staff Edit GUI form lacks any select dropdown or status toggle to activate or suspend staff members.
- **Missing GUI Action (2FA Administrative Override / Reset):** Admin users have no button or option in the GUI to disable or reset 2FA for a staff member who has lost their authenticator, even though `two_factor_enabled` is boolean in the database and security admins usually require this override.
- **Missing GUI Fields (Profile details):** The table `op_merchant_users` has columns for `phone`, `username`, and `avatar_path`, but these fields are omitted from the Staff Edit GUI form.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Audit specific template directories | To check if files corresponding to schema fields and controllers exist. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|









## Resources
- [ADMIN-PANEL-MAP.md](file:///c:/laragon/www/ownpay/docs/frontend_contribution/ADMIN-PANEL-MAP.md)
- [schema.sql](file:///c:/laragon/www/ownpay/database/schema.sql)
- [web.php](file:///c:/laragon/www/ownpay/config/routes/web.php)

