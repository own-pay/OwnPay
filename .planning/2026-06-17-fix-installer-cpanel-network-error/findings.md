# Findings & Decisions

## Requirements
- Enforce Argon2id support check in the installer step 1 so users on cPanel/shared hosting know immediately if their PHP configuration is missing Argon2 support.
- Improve error reporting in installer steps 2, 3, and 4 when `fetch()` or JSON parsing fails, showing the actual response text (e.g., PHP warnings, ModSecurity blocks, or server 500 errors) instead of masking everything with a generic "Network Error".

## Research Findings
- The "Network Error: Could not reach the server..." message is hardcoded in the `catch (err)` block of `templates/install/step3.php` (and also `step2.php`, `step4.php`).
- At step 3, `InstallerController::createAdmin()` performs `password_hash($password, PASSWORD_ARGON2ID)`.
- On cPanel/shared hosting, PHP is frequently compiled without Argon2 support. If so, accessing or passing `PASSWORD_ARGON2ID` throws a `ValueError` or `Error` in PHP 8.
- Because `display_errors` is often active on such setups, PHP prints the warning/error message or ModSecurity returns a 403 HTML page, making the response invalid JSON. The client-side `r.json()` then throws a SyntaxError, triggering the `catch` block and showing "Network Error".

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Add Argon2id check to `checkRequirements` | Pre-emptively warns the user at Step 1 rather than failing silently on Step 3. Argon2id is strictly required by the PCI-DSS/ISO-27001 rules of the project. |
| Extract fetch text response on parse failure | Prevents masking server-side PHP/ModSecurity errors as generic network errors, facilitating troubleshooting. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [InstallerController.php](file:///c:/laragon/www/ownpay/src/Controller/Install/InstallerController.php)
- [step1.php](file:///c:/laragon/www/ownpay/templates/install/step1.php)
- [step2.php](file:///c:/laragon/www/ownpay/templates/install/step2.php)
- [step3.php](file:///c:/laragon/www/ownpay/templates/install/step3.php)
- [step4.php](file:///c:/laragon/www/ownpay/templates/install/step4.php)
