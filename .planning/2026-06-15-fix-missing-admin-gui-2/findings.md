# Findings & Decisions - GUI Gaps Resolution

## Requirements & Gap Breakdown

This session aims to resolve all 19 gaps between the Admin user interface and backend operations identified during the system audit:

1. **Login Attempts Log UI**: Display `op_login_attempts` in a new view.
2. **Mobile Config API Settings Group**: Align mobile keywords/configs under `'sms'` group in `SettingsController`.
3. **Global Checkout Messages**: Align settings group to `'general'` or load correctly in `CheckoutController`.
4. **Audit Log Details View**: Allow viewing `old_values` and `new_values` JSON payloads in Activity Logs.
5. **Developer Rate Limits View**: Add a view/detail for active rate limits from `op_rate_limits`.
6. **Brand Default Language**: Add default language selector to Brand form.
7. **Brand Checkout Messages**: Add brand checkout overrides to Brand form.
8. **Domain Type & Redirect URL**: Add type and redirect url fields to Domain Add Modal.
9. **Primary Domain & SSL Display**: Add primary toggle and SSL verification status columns.
10. **Manual Gateway SMS Parsing Setup**: Add sender patterns and regex inputs to Manual Gateways form.
11. **Staff Profile & Status Controls**: Add status, phone, username, avatar inputs, and 2FA reset button to Staff form.
12. **Multi-Webhook Endpoint CRUD**: Allow CRUD operations for webhook endpoints (`op_webhooks`).
13. **Webhook Deliveries Log View**: Display webhook delivery attempts.
14. **Outbound Email Log View**: Display SMTP email communications in Comm log.
15. **SMS Queue Retry Action**: Add retry buttons to SMS queue logs.
16. **Refunds Administration Dashboard**: CRUD for `op_refunds`.
17. **Manual Match/Reprocess on Parsed SMS**: Add button/action to manually match parsed SMS.
18. **Dispute Structured Evidence**: Support receipt/tracking structured data.
19. **Ledger Table Field Mismatch**: Align `op_ledger_entries` fields and select in Ledger repository query.

## Initial Code Discoveries

- `web.php` maps all administrative AJAX routes under the `admin` middleware group.
- `ActivitiesController.php` handles administrative activity logs.
- **Issue 2 (Mobile Config API Settings Group)**: `ConfigController.php` expects keys `positive_keywords`, `negative_keywords`, and `filter_rules_check_interval_hours` in settings group `'sms'`. `SettingsController::saveGeneral` currently maps them to `'general'` group as `sms_positive_keywords` etc. We will modify `SettingsController` to save/load from `'sms'` group but expose them correctly to the UI.
- **Issue 3 (Global Checkout Messages Mismatch)**: `CheckoutController.php` loads status messages from `'general'` settings group. `SettingsController::saveCheckout` saves to `'checkout'` group. We will update `CheckoutController.php` to load from `'checkout'` group first, falling back to `'general'`, identical to `PaymentIntentCheckoutController.php`.

