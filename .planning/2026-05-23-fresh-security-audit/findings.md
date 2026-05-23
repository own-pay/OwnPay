# Findings & Decisions

## Requirements
Remediate the 4 security issues identified in the fresh security audit:
1. **SQL Sandbox Bypass**: Prevent plugins from bypassing sandbox validation via `db.query.before` filter hooks.
2. **Redirect SSRF Bypass**: Prevent attackers from bypassing SSRF checks by redirecting outbound HTTP requests to private IPs.
3. **Open Redirect in Brand Controller**: Restrict redirects using the `referer` header to safe admin panel subpaths.
4. **Developer Webhook Tester SSRF**: Enforce standard SSRF checks on the user-supplied webhook testing URL.

## Research Findings
- **SQL Sandbox Hook Bypass**: The database validation check currently executes *before* the `'db.query.before'` filter hook. A plugin listener can modify the query after validation. Moving validation to `Database::execute()` after the filter runs, and validating during hook execution inside `EventManager::applyFilter()`, resolves this.
- **cURL Redirect SSRF**: `HttpClient` follows redirects natively. Setting `CURLOPT_FOLLOWLOCATION => false` and implementing a manual redirection loop using `CURLINFO_REDIRECT_URL` and `UrlValidator::isValidWebhookUrl()` prevents redirect SSRF.
- **Brand Switched Referer**: `BrandController::switchBrand` redirects directly to `referer` header. Extracting the path and ensuring it is local prevents open redirection.
- **Developer Webhook URL**: `DeveloperController::testWebhook` only uses `filter_var()` for URL validation. Replacing it with `UrlValidator::isValidWebhookUrl()` prevents SSRF.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Validate hook SQL in EventManager | Mitigates supply-chain SQL injection bypasses before queries reach the database handler. |
| Implement manual redirect loops in HttpClient | Resolves redirect-based SSRF exploits without relying on insecure native cURL redirection. |
| Extract path from referer header | Prevents open redirect attacks by ensuring redirects are relative and restricted to the admin panel. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [Database.php](file:///C:/laragon/www/ownpay/src/Core/Database.php)
- [EventManager.php](file:///C:/laragon/www/ownpay/src/Event/EventManager.php)
- [HttpClient.php](file:///C:/laragon/www/ownpay/src/Service/System/HttpClient.php)
- [BrandController.php](file:///C:/laragon/www/ownpay/src/Controller/Admin/BrandController.php)
- [DeveloperController.php](file:///C:/laragon/www/ownpay/src/Controller/Admin/DeveloperController.php)

