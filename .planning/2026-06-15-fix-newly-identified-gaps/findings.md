# Findings - newly identified Admin UI gaps

During our fresh audit, we identified 8 active gaps where backend logic/data exists in the database schema but is completely missing from the administrator UI.

This session will implement all 8 missing interfaces/elements.

## Implementation Details & Design Choices

1. **Payment Intents GUI**:
   - `PaymentIntentController` will paginate and filter intents using the scoped `PaymentIntentRepository`.
   - Index layout: Standard search filter bar (filtering by status: `pending`, `processing`, `completed`, `failed`, `cancelled`, `expired`).
   - Details view: Display fields like customer, token, cancellation/redirect/webhook URLs, and metadata.

2. **Mobile Push Notifications Log**:
   - `MobileNotificationLogController` will load push logs from `op_mobile_notifications`.
   - Display a list table: Device ID, Type, Title, Body, Read status, Sent timestamp.
   - Filter by read status and search by title/body.

3. **Queue Jobs GUI**:
   - Instead of a standalone tab, we will integrate a "Queue Monitor" tab inside the Settings panel `/admin/settings`, which aligns with other system health modules (optimization, cron).
   - Display active engine type (e.g., File or Redis) and counts of queued notifications/jobs.

4. **Parsed SMS Body Column**:
   - Modify `templates/admin/sms-data.twig` to add a new column displaying the SMS message body.

5. **Staff 2FA Reset Button**:
   - Add a POST form submit button labeled "Reset 2FA" in both `staff/edit.twig` and `staff/index.twig` (visible only when 2FA is active).

6. **Brand Slug Field**:
   - Add a read-only field displaying the slug under the `Business Info` card in `brands/edit.twig`.

7. **Ledger Detail View**:
   - Build a collapsible detail container inside each ledger transaction row on `/admin/ledger`, showing individual debit/credit rows from `op_ledger_entries`.

8. **Telegram & Webhook Logs**:
   - Add Outbound Telegram and Webhook tabs inside the SMS Center `/admin/sms-center` log view.
