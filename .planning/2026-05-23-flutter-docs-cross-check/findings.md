# Findings: OwnPay PHP Backend Codebase & Mobile App Docs Cross-Check

## Requirements

Verify the status of the OwnPay backend codebase (PHP) against the Mobile App design specifications (`docs/v2/mobile_app/plan.md` and `todo.md`) to determine if the documentation is up-to-date, backdated, or incomplete.

---

## Research Findings

### 1. Overall Implementation Status

The OwnPay PHP backend codebase is **extremely well-developed, fully implemented, and clean**, but the companion checklist in [todo.md](file:///C:/laragon/www/ownpay/docs/v2/mobile_app/todo.md) is **backdated and incomplete** (under-reporting completed backend work):

- **Web Admin UI is fully completed:** [todo.md](file:///C:/laragon/www/ownpay/docs/v2/mobile_app/todo.md#L77-L80) marks the SMS Templates, SMS Queue, and Unparsed SMS admin pages as pending/deferred (`[ ]`). However, in the codebase, the [SmsTemplateAdminController.php](file:///C:/laragon/www/ownpay/src/Controller/Admin/SmsTemplateAdminController.php) and [SmsDataController.php](file:///C:/laragon/www/ownpay/src/Controller/Admin/SmsDataController.php) are fully implemented, and their respective Twig templates (`index.twig`, `edit.twig`, and `sms-data.twig` in `templates/admin/sms-center/` and `templates/admin/`) exist and are active.
- **REST APIs are fully completed:** The backend routes in [api.php](file:///C:/laragon/www/ownpay/config/routes/api.php) and controllers (e.g. `DeviceController`, `SmsController`, `NotificationController`, `DashboardController`, `ConfigController`) fully implement all pairing, heartbeat, SMS decryption/parsing, notification polling/ack, and dashboard summary endpoints.

---

### 2. Major Documentation Discrepancies (Schema Level)

There is a **significant schema-level mismatch** between the proposed design in [plan.md](file:///C:/laragon/www/ownpay/docs/v2/mobile_app/plan.md) and the actual database implementation of the SMS Template parsing engine:

#### The `op_sms_templates` Schema Mismatch

* **As Documented ([plan.md:L205-220](file:///C:/laragon/www/ownpay/docs/v2/mobile_app/plan.md#L205-L220)):**
  The design documentation outlines a unified regex template format with named capture groups in a single column (`regex_pattern` text) and extra metadata columns:
  - `regex_pattern` (TEXT)
  - `transaction_type` (VARCHAR)
  - `provider_name` (VARCHAR)
  - `currency` (VARCHAR)
  - `is_active` (TINYINT)
- **As Implemented in Codebase ([schema.sql:L650-665](file:///C:/laragon/www/ownpay/database/schema.sql#L650-L665) and [SmsTemplateRepository.php](file:///C:/laragon/www/ownpay/src/Repository/SmsTemplateRepository.php)):**
  The codebase uses a split-regex table design:
  - `amount_regex` (VARCHAR)
  - `trx_id_regex` (VARCHAR)
  - `sender_regex` (VARCHAR)
  - `gateway_slug` (VARCHAR) - replaces `provider_name`
  - `status` (ENUM) - replaces `is_active`
  - *Note:* The database table completely lacks `regex_pattern`, `transaction_type`, and `currency`.

#### Downstream Code Adaptations

- **Hybrid Parser Design:** Because of this mismatch, [SmsRegexParser.php](file:///C:/laragon/www/ownpay/src/Service/Sms/SmsRegexParser.php) has a hybrid implementation that attempts to read `$template['regex_pattern']` first, and if not present, falls back to the database-defined separate fields (`amount_regex`, `trx_id_regex`, `sender_regex`).

---

### 3. Database Column Alignment Check

An alignment check against strict database naming rules (`database-schema.md`) shows the codebase matches the rules perfectly:

- `op_sms_parsed` uses `device_id` (not `device_uuid`) and `match_status` (not `status`).
- `op_currencies` uses `decimal_places` (not `decimals`).
- `op_mobile_notifications` matches `plan.md` layout exactly.

---

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Mark checklist (`todo.md`) as backdated | It lists administrative UI files and templates as pending, but these are 100% completed in the source code. |
| Highlight the `op_sms_templates` schema drift | This drift forces a hybrid parser implementation in `SmsRegexParser.php` and represents a major gap between the architecture design and the code. |

---

## Resources

- [plan.md](file:///C:/laragon/www/ownpay/docs/v2/mobile_app/plan.md)
- [todo.md](file:///C:/laragon/www/ownpay/docs/v2/mobile_app/todo.md)
- [schema.sql](file:///C:/laragon/www/ownpay/database/schema.sql)
- [SmsRegexParser.php](file:///C:/laragon/www/ownpay/src/Service/Sms/SmsRegexParser.php)
- [SmsTemplateRepository.php](file:///C:/laragon/www/ownpay/src/Repository/SmsTemplateRepository.php)
- [SmsTemplateAdminController.php](file:///C:/laragon/www/ownpay/src/Controller/Admin/SmsTemplateAdminController.php)
