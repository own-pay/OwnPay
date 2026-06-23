# Findings - Custom Domain and Copy Fixes

## 1. DNS Verification Discrepancy & Resolver Caching
- The verification instruction in `templates/admin/domains/index.twig` shows record name `_ownpay-verify` and value `ownpay-verify={token}`.
- However, `DnsVerifier::verifyTxt` originally queried `_ownpay-verification.{$domain}` and expected the value to exactly equal the raw token (`op-verify-...`).
- This double mismatch was corrected by updating `DnsVerifier::verifyTxt` to look up both record names and support both expected value formats.
- **DNS Caching / Slow Propagation:** In cPanel/shared hosting and local environments, OS/local DNS resolvers aggressively cache negative lookups. If a verification check is run before the record is set or propagates, future checks fail even if the user subsequently configures the record correctly.
- **Solution:** Added a DNS-over-HTTPS (DoH) fallback via Cloudflare API (`https://cloudflare-dns.com/dns-query`) directly to `DnsVerifier::verifyTxt()`. This queries the Cloudflare global resolver, bypassing any local caching and ensuring instant verification as soon as the user sets up the record.

## 2. Clipboard Copy Button & Browser Caching
- The global click handler in `admin.js` intercepted all clicks on elements matching `[data-copy]`, does `e.preventDefault()`, and copied the literal attribute value of `data-copy` to clipboard.
- The global copy delegate was fixed to check if `data-copy` refers to an existing DOM element and copy its text, while the specific click event handlers in `developer.js` and `domains.js` were updated to call `e.stopPropagation()` to stop bubbling.
- **Browser Caching:** Browsers aggressively cache JS assets. If the user previously accessed the page, their browser continues to use the cached versions of `admin.js`, `developer.js`, and `domains.js` which did not have these fixes, resulting in continued copying of literal ID strings.
- **Solution:** Added query version parameters (`?v=045`, `?v=002`) to the `<script>` source declarations in `base.twig`, `developer/index.twig`, and `domains/index.twig` to force the browser to invalidate cache and load the fresh, fixed JS assets.

## 3. Custom Domain Routing (DomainMiddleware)
- `DomainMiddleware` intercepts requests on custom domains. If `status !== 'active'`, it returned a 404 response.
- Newly mapped custom domains start in a `'pending'` status (with `dns_verified = 0`).
- Because of `status !== 'active'`, these pending domains resulted in a 404 response instead of the intended `503 Domain Not Verified / DNS verification pending` response.
- Fix: Adjusted checks in `DomainMiddleware::handle` to correctly distinguish between unrecognized/inactive domains (404) and pending/unverified domains (503).
