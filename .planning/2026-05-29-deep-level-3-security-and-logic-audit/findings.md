# Findings & Decisions

## Requirements

- Conduct an advanced "Level 3" deep security and logic audit of the OwnPay codebase.
- Identify hidden security vulnerabilities and logic flaws that automated tools or browser scans easily miss.
- Remediate all discovered vulnerabilities completely, with zero stubs or placeholders.
- Verify everything with lints, static checkers, and PHPUnit integrations.

## Research Findings

### FIND-006: [HIGH] Outbound SSRF Verification Gap in WebhookDispatcher

- **OWASP 2025**: A01:2025 - Broken Access Control (SSRF)
- **Location**: `src/Service/Notification/WebhookDispatcher.php` (inside `doSend` / `sendWithRetry`)
- **Description**: While `WebhookService.php` validates outgoing URLs via `UrlValidator::isValidWebhookUrl()`, `WebhookDispatcher.php` executes raw cURL requests directly. An administrative user or compromised API token could register a local/loopback URL and trigger webhook tests or deliveries, causing outbound SSRF requests.
- **Impact**: Attackers can access private internal system ports, trigger actions, or extract metadata from local loopback resources.
- **Priority**: P1 (Fix immediately)

### FIND-007: [MEDIUM] Exceptional Condition Exception Gap in Webhook Resolution

- **OWASP 2025**: A10:2025 - Mishandling of Exceptional Conditions
- **Location**: `src/Controller/Webhook/UnifiedWebhookController.php` (inside `resolveMerchantFromPayload()`)
- **Description**: In `resolveMerchantFromPayload()`, payload variables such as `$data[$field]` are bound directly to PDO queries. If an attacker passes a non-scalar parameter (e.g. an array), the database query will crash due to parameter binding mismatch.
- **Impact**: Disruption of webhook handling, excessive logging, or potentially bypasses if exception blocks fail open in other parts of the chain.
- **Priority**: P2 (Fix this session)

### FIND-008: [HIGH] Default-Deny Access Control Bypass on Unmapped Admin Routes

- **OWASP 2025**: A01:2025 - Broken Access Control
- **Location**: `src/Middleware/PermissionMiddleware.php` (inside `resolvePermission()`)
- **Description**: The prefix route matching loop matched `/admin` prefixes for all admin URLs. Consequently, any unmapped administrative route (e.g. `/admin/super-secret/action`) resolved to the `dashboard.view` permission rather than falling through to the default-deny `system.unmapped` block.
- **Impact**: Any non-privileged staff member with baseline dashboard access could bypass access controls on newly added or unmapped administrative sub-routes.
- **Priority**: P1 (Fix immediately)

## Technical Decisions

| Decision | Rationale |
|----------|-----------|
| Validate `WebhookDispatcher` URLs | Enforces strict SSRF protection on outgoing notifications dispatched via `WebhookDispatcher`. |
| Enforce `is_scalar` validation in `UnifiedWebhookController` | Protects the query parameter binding from array injection attacks causing SQL or type exceptions. |
| Restrict `PermissionMiddleware` prefix matching | Excludes base '/admin' prefix and enforces strict path boundaries to preserve default-deny authorization checks. |

## Issues Encountered

| Issue | Resolution |
|-------|------------|

## Resources

- [UrlValidator.php](file:///c:/laragon/www/ownpay/src/Security/UrlValidator.php)
- [WebhookDispatcher.php](file:///c:/laragon/www/ownpay/src/Service/Notification/WebhookDispatcher.php)
- [UnifiedWebhookController.php](file:///c:/laragon/www/ownpay/src/Controller/Webhook/UnifiedWebhookController.php)
- [PermissionMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/PermissionMiddleware.php)
- [SecurityRemediationTest.php](file:///c:/laragon/www/ownpay/tests/Security/SecurityRemediationTest.php)
