# Findings & Decisions

## Requirements
- `api/v1/customers` endpoint must return customer list with `email` and `phone` unmasked (instead of or alongside masked values).
- `/api/v1/customers/{identifier}` must work dynamically with both email addresses (containing `@`) and phone numbers (containing `+` or URL-encoded equivalents).

## Research Findings
- **Controller File:** [CustomerController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/CustomerController.php) controls all customer endpoints.
  - `index()` maps response fields using `email_masked` and `phone_masked` derived from `CustomerPiiService::list()`.
  - `show()` uses `CustomerPiiService::findByContact()` to find a customer by email or phone.
- **Router Pattern Match Constraint:** [Router.php](file:///c:/laragon/www/ownpay/src/Http/Router.php) (line 149) compiles route placeholders using `([a-zA-Z0-9_\-\.]+)`. This prevents matching `@`, `+`, or `%` characters.
- **Rule Constraint:** [code-standards-architecture.md](file:///c:/laragon/www/ownpay/.agents/rules/code-standards-architecture.md) (Section 6) explicitly mandates that route parameters must not allow `@` or `+`.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Expose `email` and `phone` in `CustomerController::index()` | Matches the user request to show unmasked fields. We will keep `email_masked` and `phone_masked` for backward compatibility. |
| Modify `Router.php` to allow `+`, `@`, and `%` specifically for the `{identifier}` parameter | Allows email and phone parameters to match in the URL while keeping all other parameters strictly validated to `[a-zA-Z0-9_\-\.]`. |
| Use `rawurldecode()` on the `identifier` parameter in `CustomerController::show()` | Safely decodes URL-encoded parameters (like `%40` for `@` and `%2B` for `+`) without converting `+` to space (which `urldecode` does). |
| Synchronize rules in `code-standards-architecture.md`, `ARCHITECTURE.md`, and `AGENTS.md` | Ensures standard compliance and keeps all documentation updated. |
| Require `write` and `admin` scopes for caller API key on API key endpoints | Restricts programmatic key management to only highly privileged keys to prevent privilege escalation. |
| Verify `X-Super-Admin-Email` header against active super admins in DB | Adds an additional layer of security requiring verification from a platform super-administrator. |
| Accept `scopes` array in `POST /api/v1/api-keys` body | Allows configuring specific privileges (`read`, `write`, `admin`) for the generated key. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [CustomerController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/CustomerController.php)
- [Router.php](file:///c:/laragon/www/ownpay/src/Http/Router.php)
- [code-standards-architecture.md](file:///c:/laragon/www/ownpay/.agents/rules/code-standards-architecture.md)
- [ApiKeyController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/ApiKeyController.php)
- [ApiKeyService.php](file:///c:/laragon/www/ownpay/src/Service/Customer/ApiKeyService.php)
