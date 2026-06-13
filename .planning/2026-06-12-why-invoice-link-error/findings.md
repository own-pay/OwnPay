# Findings & Decisions: Why Invoice Link Returns Error

## Requirements
- Identify the root cause of the 500 Internal Server Error (Premature end of script headers) on invoice and checkout links.
- Report findings without modifying the codebase.

## Research Findings
1. **SAPI-Specific Failure**: The 500 error only occurs under the CGI/FCGI SAPI (Apache mod_fcgid) and not under the CLI SAPI.
2. **Apache Error Logs**: The error logs show `Premature end of script headers: index.php` at the exact time of the crashes, with no corresponding fatal PHP errors or exception stack traces in `php_errors.log`. This signifies a hard worker process termination by Apache/mod_fcgid during response header parsing.
3. **Csp Header Size**: `SecurityHeadersMiddleware::collectGatewayCspSources()` scans all 123 subdirectories under `modules/gateways/` to collect CSP origins from all gateway manifests.
4. **Header Size Calculations**: The generated CSP header length is **16,275 bytes**.
5. **mod_fcgid Header Limit**: Apache's `mod_fcgid` has a strict default response header size limit (`FcgidMaxHeaderLen` / `LimitRequestFieldSize`) of **8,192 bytes (8 KB)**. Any individual response header line exceeding this size triggers immediate worker termination, resulting in the 500 error.
6. **Bypassing in Redirects**: The redirect routes (e.g. `/invoice/{token}`) do not output HTML, yet they undergo the full `SecurityHeadersMiddleware` pipeline, generating the massive header.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Scope active gateways by BrandContext | Scanning all 123 manifests is highly inefficient and exceeds Apache's FCGID header limits. Filtering by `BrandContext::getActiveBrandId()` active configs resolves it. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| `test_route.php` did not run middlewares | Created `run_kernel.php` to simulate the full middleware pipeline. |
