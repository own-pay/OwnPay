# Task Plan: Why Invoice Link Returns Error

## Goal
Identify the root cause of the 500 Internal Server Error (Premature end of script headers) when accessing the invoice checkout link under Apache/CGI SAPI, and report it to the user.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Research & Discovery
- [x] Read `src/Controller/Checkout/InvoiceCheckoutController.php`
- [x] Read `src/Middleware/SecurityHeadersMiddleware.php`
- [x] Inspect Apache error log details
- [x] Identify where the FCGI/CGI SAPI deviates from CLI SAPI (e.g. `apache_request_headers`, headers already sent, globbing plugins, output buffering)
- **Status:** complete

### Phase 2: Hypothesis & Verification
- [x] Formulate hypothesis
- [x] Test hypothesis via customized script or local Apache server request observation
- **Status:** complete

### Phase 3: Reporting
- [x] Document findings in `findings.md`
- [x] Deliver a detailed report to the user on the root cause and how to resolve it
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Scope active gateways by BrandContext | Scanning all 123 manifests is highly inefficient and exceeds Apache's FCGID header limits. Filtering by `BrandContext::getActiveBrandId()` active configs resolves it. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| `mysql: Access denied` | Used password `-proot` as configured in `.env`. |
