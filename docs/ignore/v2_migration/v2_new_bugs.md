# V2 Migration — New Bugs Report

This document records the exhaustive list of bugs, broken routes, and schema mismatches introduced or overlooked during the V2 structural migration.

## 1. Settings Module Issues
* **Bug 1.1:** Fatal error in `SettingsController::index()`. The `$db` variable is undefined. It was removed during the `SettingsRepository` migration, but `$db->fetchAll("SELECT ... FROM op_currencies")` was left behind, causing the entire settings page to fail (`admin/settings#tab-currency`). **[FIXED]**
* **Bug 1.2:** Missing API Key routes. The `web.php` routes file is completely missing the POST routes for `/admin/api-keys/generate` and `/admin/api-keys/{id}/revoke`. Form submissions result in a 404. **[PARTIALLY FIXED — revoke still broken, see Bug 10.1]**
* **Bug 1.3:** API Key UI variable mismatch. `templates/admin/settings/index.twig` expects `key.label` and `key.key_hash`, but `ApiKeyRepository::listActiveKeys()` does not `SELECT name` (which is the label), and returns `key_prefix`. As a result, the UI displays blank names and missing hashes. **[FIXED]**

## 2. Brand / Merchant Module Issues
* **Bug 2.1:** Brand Edit 404. `templates/admin/brands/index.twig` links to `/admin/brands/{id}/edit`, but the router (`web.php`) maps `GET /admin/brands/{id}` to `BrandController@show` without the `/edit` suffix. **[FIXED]**
* **Bug 2.2:** No delete brand option. Brand index page has no delete button/action. Admin cannot remove brands. **[FIXED — delete route + controller + confirm dialog + safety checks]**

## 3. Ledger & Balance Verification Issues
* **Bug 3.1:** Legacy `op_ledger` schema leak in `ReconciliationService`. `BalanceVerificationController` triggers `ReconciliationService::reconcile()`, which attempts to query the legacy `op_ledger` table (deleted in V2). It needs to be updated to use the new V2 triple-table schema (`op_ledger_accounts` / `op_ledger_entries`) via `LedgerService::calculateBalance()`.

## 4. FAQ Settings Issues
* **Bug 4.1:** JSON Decoding edge cases. If `settings['faqs']` contains malformed JSON or an empty string, `json_decode` may fail or return null, breaking the `{% for %}` loop in Twig. Defensive checks are needed in `SettingsController`. **[FIXED]**

## 5. Maintenance Mode Issues
* **Bug 5.1:** Maintenance lock file `retry` key mismatch — Kernel reads `retry_after` but SettingsController writes `retry`. **[FIXED]**
* **Bug 5.2:** Kernel maintenance blocks `/login` route — admin can't login during maintenance. **[FIXED]**
* **Bug 5.3:** Checkbox settings never uncheck — unchecked HTML checkboxes don't POST. **[FIXED]**

## 6. Template Safety Issues
* **Bug 6.1:** Unsafe Twig property access across 10+ checkout/admin templates (`txn.trx_id`, `gw.logo`, `brand.logo`) — crashes on undefined keys in strict mode. **[FIXED]**

## 7. Staff Module Issues
* **Bug 7.1:** Role dropdown hardcoded as string values (admin/manager/staff) instead of DB FK to `op_roles`. **[FIXED]**

## 8. Plugin System Issues
* **Bug 8.1:** Plugin install page (`/admin/plugins/install`) has unprofessional UI/UX and incorrect "Plugin Requirements" info.
* **Bug 8.2:** Plugin settings pages (`/admin/plugins/{slug}/settings`) don't show plugin-specific settings for any installed plugin.
* **Bug 8.3:** Addon activate/deactivate (`/admin/addons`) shows multiple flash notices/errors on toggle.

## 9. Theme System Issues
* **Bug 9.1:** Theme customize route (`/admin/themes/{slug}/customize`) returns 404.

## 10. API Key Issues
* **Bug 10.1:** API key revoke (`/admin/api-keys/{id}/revoke`) not working — route or controller logic broken.

## 11. Domain System Issues
* **Bug 11.1:** Domain verification checks TXT record but should check A record. Custom domains used for customer-facing pages (checkout, invoice, payment link) need DNS A pointing to server IP. **[FIXED — dual TXT+A record verification, A record failure = warning not blocker]**

## 12. Activity Log Issues
* **Bug 12.1:** Activity log page (`/admin/activities`) shows empty — audit logging not triggered from controllers/services.

## 13. Gateway System Issues
* **Bug 13.1:** Gateway page UI/UX not professional enough for fintech standard. **[FIXED]**
* **Bug 13.2:** After activating a gateway plugin, button still shows "Activate" instead of changing state. **[FIXED]**

## 14. Payment Link Issues
* **Bug 14.1:** No default/fixed payment link per brand. Each brand should have a configurable default payment link URL.

## 15. Invoice Issues
* **Bug 15.1:** Invoice status always shows "draft" regardless of actual payment state. **[FIXED]**
