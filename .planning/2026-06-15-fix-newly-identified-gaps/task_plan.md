# Implementation Plan: Resolve Newly Identified Admin GUI Gaps [COMPLETED]

This plan outlines the steps taken to resolve the 8 active GUI gaps and mismatches identified during the fresh audit of the OwnPay Admin portal.

## Proposed Changes

### 1. Payment Intents Management GUI (Gap 1) [COMPLETED]
- **NEW** `src/Controller/Admin/PaymentIntentController.php`: Admin controller that paginates and filters payment intents (`op_payment_intents`) scoped by the active merchant context.
- **NEW** `templates/admin/payment-intents/index.twig`: List view for payment intents with search, filter, and pagination.
- **NEW** `templates/admin/payment-intents/show.twig`: Detailed inspection view for a single payment intent, displaying token, cancellation/redirect URLs, expiration, and JSON metadata.
- **MODIFY** `config/routes/web.php`: Add web routes for payment intent listing and detail view.
- **MODIFY** `templates/admin/layout/sidebar.twig`: Add "Payment Intents" submenu under "Payments" sidebar section.
- **MODIFY** `storage/languages/en.json`: Add translation key `"menu.payment_intents": "Payment Intents"`.

### 2. Mobile Push Notifications Log GUI (Gap 2) [COMPLETED]
- **NEW** `src/Controller/Admin/MobileNotificationLogController.php`: Admin controller to display push logs from `op_mobile_notifications`.
- **NEW** `templates/admin/devices/notifications.twig`: Log view page listing push notifications, their delivery state, recipient device, payload, and read timestamps.
- **MODIFY** `config/routes/web.php`: Add route `/admin/devices/notifications` for the notifications log.
- **MODIFY** `templates/admin/layout/sidebar.twig`: Add a sub-menu link "Push Logs" under the "Mobile & SMS" sidebar section.

### 3. Queue Jobs GUI (Gap 3) [COMPLETED]
- **NEW** `templates/admin/settings/queue.twig` / tab-queue: A settings tab/view to show pending/reserved queue counts, queue engine status, and recent job metadata.
- **MODIFY** `src/Controller/Admin/SettingsController.php`: Load File/Redis queue size stats and DB-based queue counts.
- **MODIFY** `templates/admin/settings/index.twig`: Add a "Queue Monitor" tab inside the settings panel.
- **MODIFY** `public/assets/js/pages/settings.js`: Hide the Save Settings button when the queue tab is active.

### 4. Parsed SMS Body Column (Gap 4) [COMPLETED]
- **MODIFY** `templates/admin/sms-data.twig`: Add a "Message / Body" column to the parsed SMS data table to show the raw SMS content.

### 5. Staff 2FA Reset Button (Gap 5) [COMPLETED]
- **MODIFY** `templates/admin/staff/edit.twig` & `templates/admin/staff/index.twig`: Add a button to trigger the `/admin/staff/{id}/reset-2fa` POST form action with confirmation, visible only when 2FA is enabled.

### 6. Brand Slug Editing (Gap 6) [COMPLETED]
- **MODIFY** `templates/admin/brands/edit.twig`: Add an uneditable or editable-on-create `Brand Slug` field under Business Info settings, allowing users to view the slug value.

### 7. Detailed Ledger Debits/Credits View (Gap 7) [COMPLETED]
- **MODIFY** `templates/admin/ledger/index.twig`: Add a toggle or accordion layout to display individual debit/credit rows (`op_ledger_entries` detail) for each ledger transaction row.

### 8. Outbound Telegram & Webhook Log tabs in Comm Log (Gap 8) [COMPLETED]
- **MODIFY** `src/Repository/CommLogRepository.php`: Add `listTelegramQueue` and `listWebhookQueue` queries.
- **MODIFY** `src/Controller/Admin/SmsTemplateAdminController.php`: Load Telegram and Webhook queues.
- **MODIFY** `templates/admin/sms-center/index.twig`: Add tabs/tables for Telegram alerts and Webhook dispatches loaded from `op_comm_log`.

---

## Verification Plan

### Automated Tests [PASSED]
- Run `vendor/bin/phpunit` to ensure all tests pass.
- Run static analysis using PHPStan.
- Run Twig/Asset Linters.

### Manual Verification
- Access each newly implemented GUI page and verify visual completeness.
