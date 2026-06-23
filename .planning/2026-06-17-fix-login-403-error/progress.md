# Progress Log

## Diagnostic Timeline
- **Step 1**: Traced the route configuration (`config/routes/web.php`) and middleware pipeline (`config/middleware.php`) for `web-auth`. Identified `CsrfMiddleware` as the only component returning a 403 Forbidden.
- **Step 2**: Modified `CsrfMiddleware` to log specific CSRF failures (in `src/Middleware/CsrfMiddleware.php`).
- **Step 3**: Created `test_login_flow.php` simulating GET and POST login requests to `/login` programmatically. Validated that the native session-CSRF mechanism itself operates correctly (both GET/POST complete with 302 Found).
- **Step 4**: Verified the full application test suite passes successfully (545 tests) to ensure no regressions were introduced.
- **Step 5**: Created `test_client_login.php` simulating HTTP client requests via PHP cURL against the active Apache local web server. Verified that GET/POST login flow completes with a 302 redirect for both HTTP and HTTPS requests.
- **Step 6**: Formulated the Secure Cookie Mismatch hypothesis. If a user previously accessed the site on HTTPS, the browser saved a `Secure` cookie. If they try to log in via HTTP afterwards, the browser will not send the cookie, and will reject the server's attempts to set a non-secure cookie of the same name, leading to an empty POST session and a 403 Forbidden.
- **Step 7**: Added diagnostic output to the `CsrfMiddleware::forbidden` HTML response so the user can see the exact reason (e.g., `CSRF token missing` or `CSRF token mismatch`) in their browser.
- **Step 8**: Cleaned up all temporary diagnostic scripts.

## Run Logs / Verification Output
```
=== Client-Side HTTP GET/POST (cURL) ===
GET /login Response Status: 200
Session ID: hdipcansm5ms7bp48r8v3mebui
Captured CSRF Token: 647515ea6dae24806fa19028288185d6356c8014e6c963de63ab4ca6a0ddc43c

=== Client-Side HTTPS GET/POST (cURL) ===
Response Status: 302 Found (Redirect to /admin)
Set-Cookie: op_session=c1842ckvimn20759n0c4hnbdh0; expires=Wed, 17 Jun 2026 10:33:17 GMT; Max-Age=7200; path=/; secure; HttpOnly; SameSite=Lax
```
All verifications succeeded.
