# Findings & Decisions - Fresh Admin GUI Audit

## Requirements
- Perform a fresh, thorough audit comparing the current Admin UI (routes, controllers, Twig templates) against the specifications in `docs/frontend_contribution/ADMIN-PANEL-MAP.md` and the database schema in `database/schema.sql`.
- Identify all remaining missing Admin GUI pages, input fields, or settings tabs where backend logic is present but no GUI is exposed.
- Document all findings clearly with technical details (affected database tables, columns, and source files) to maintain a complete mapping of GUI gaps.

---

## 1. Resolved Gaps (Confirmed as Fully Implemented)
A comparison with the previous list of gaps reveals that several features have been successfully implemented:
1. **Login Attempts Log GUI**: Fully implemented via `LoginAttemptController`, `/admin/login-attempts` routes, and `settings/login-attempts.twig`.
2. **Mobile Config API Group Mismatch**: Resolved by aligning the companion app's config query to the `sms` settings group.
3. **Global Checkout Messages Mismatch**: Resolved with fallback checks fetching from both `checkout` and `general` groups.
4. **Audit Log Changes Detail View**: Fully implemented; the `activities.twig` page launches a modal pulling `activity-details.twig` with `old_values` and `new_values` JSON diffs.
5. **Developer Rate Limits Detail View**: Fully implemented under the "Rate Limits" tab in `developer/index.twig` with active bucket listings and reset forms.
6. **Default Brand Language & Checkout Message Overrides**: Fully implemented in `brands/edit.twig` with language selection and status overrides.
7. **Domain Type, Redirect URLs, Primary Toggle & SSL Status**: Fully implemented in `domains/index.twig` inside the domains table and modal.
8. **Manual Gateway SMS Parsing Fields**: Fully implemented inside `gateways/edit-manual.twig` with patterns and regex inputs.
9. **Staff Profile & Status Controls**: Fully implemented in `staff/edit.twig` with username, phone, status, avatar, and inline permissions controls.
10. **Parsed SMS Match Action & Re-queue Retries**: Fully implemented inside `sms-center/index.twig` with retry buttons and manual match form actions.
11. **Communication Log (Outbound Emails)**: Fully implemented inside the SMS Center under the "Outbound Emails" tab.

---

## 2. Active Gaps (Backend Exists, GUI Missing)
The following are the newly audited, active gaps in the OwnPay Admin UI:

### Gap 1: Missing Payment Intents GUI
- **Backend Implementation**: The database table `op_payment_intents` tracks active checkout sessions. The `PaymentIntent` entity and services manage checkout states.
- **UI Gap**: There is no controller, route, or template to list, search, or inspect active checkout sessions (`op_payment_intents`).
- **Map Alignment**: Mapped in `ADMIN-PANEL-MAP.md` under `Payments -> Payment Intents`.
- **Files Affected**:
  - `database/schema.sql` (table definition)
  - `config/routes/web.php` (no routes)
  - `templates/admin/layout/sidebar.twig` (no sidebar link)

### Gap 2: Missing Mobile Push Notifications Log GUI
- **Backend Implementation**: The database table `op_mobile_notifications` stores push logs sent to paired devices, backed by `MobileNotificationRepository`.
- **UI Gap**: The Admin UI completely lacks a page, route, or controller to view the push notifications log.
- **Map Alignment**: Mapped in `ADMIN-PANEL-MAP.md` as `Notifications / Devices ← Mobile push notification log`.
- **Files Affected**:
  - `src/Repository/MobileNotificationRepository.php`
  - `config/routes/web.php` (no routes)

### Gap 3: Missing Queue Jobs GUI
- **Backend Implementation**: The database table `op_queue_jobs` defines the job payload, but the backend uses `RedisQueue` and `FileQueue` for async background operations.
- **UI Gap**: No interface exists for administrators to monitor queue status, active workers, or review failed/stuck background jobs.
- **Map Alignment**: Mentioned in `ADMIN-PANEL-MAP.md` under `System Health`.
- **Files Affected**:
  - `database/schema.sql`
  - `config/routes/web.php`

### Gap 4: Parsed SMS Body Column Omission
- **Backend Implementation**: The `op_sms_parsed` table has a `body` text column storing the raw SMS content.
- **UI Gap**: The Parsed SMS list page (`templates/admin/sms-data.twig`) only displays metadata (Sender, Amount, TRX ID, Gateway, Status, Received) and completely omits the raw SMS `body`. (Note: The SMS Center has the body, but the main parsed SMS page does not).
- **Files Affected**:
  - `templates/admin/sms-data.twig` (missing column in table)

### Gap 5: Staff 2FA Reset Button Omission
- **Backend Implementation**: `StaffController::reset2fa` is fully implemented, and the `/admin/staff/{id}/reset-2fa` route is registered in `web.php`.
- **UI Gap**: There is no button or form trigger in `staff/edit.twig` or `staff/index.twig` to allow administrators to reset a staff member's 2FA setup.
- **Files Affected**:
  - `templates/admin/staff/edit.twig`
  - `templates/admin/staff/index.twig`

### Gap 6: Brand Slug Editing Omission
- **Backend Implementation**: The `slug` column in the `op_merchants` table stores the unique brand identifier.
- **UI Gap**: The brand configuration form `templates/admin/brands/edit.twig` has no input field to view or edit the Brand Slug. The slug is generated automatically on brand creation but remains uneditable thereafter.
- **Map Alignment**: Mapped in `ADMIN-PANEL-MAP.md` as "Brand Slug" under Brand Profile.
- **Files Affected**:
  - `templates/admin/brands/edit.twig`

### Gap 7: Detailed Ledger Journal Entries View Omission
- **Backend Implementation**: Financial entries are recorded in `op_ledger_entries` (debits/credits) linked to `op_ledger_transactions`.
- **UI Gap**: The ledger index page (`templates/admin/ledger/index.twig`) lists transaction-level summaries but lacks any expandable details or sub-table displaying the individual debits/credits for each ledger transaction.
- **Map Alignment**: Mapped in `ADMIN-PANEL-MAP.md` as "Ledger".
- **Files Affected**:
  - `templates/admin/ledger/index.twig`

### Gap 8: Outbound Telegram & Webhook Omission in Communication Logs
- **Backend Implementation**: `op_comm_log` stores communication channels `sms`, `email`, `telegram`, and `webhook`.
- **UI Gap**: The SMS Center outbound queue only exposes SMS and Email tabs. There is no log view or tab for Telegram alerts or webhook dispatches within the communication logging interface.
- **Files Affected**:
  - `templates/admin/sms-center/index.twig`

---

## 3. Structural & Divergence Gaps
- **System Health & Cron Jobs**: Documented as top-level navigation routes in `ADMIN-PANEL-MAP.md`, but in the actual GUI, they are rendered as settings tabs inside `/admin/settings` (`tab-optimization` and `tab-cron`).
- **Currencies**: Documented under System Settings, but in the actual GUI, the sidebar currency link redirects to the `/admin/settings/payment` tab.
