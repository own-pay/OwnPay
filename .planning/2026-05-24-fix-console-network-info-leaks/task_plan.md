# Task Plan: Fix Console and Network Information Leaks

## Goal
Resolve all console security errors and network information leaks across all platform pages by adding script nonces, cleaning up duplicate Apache/PHP response headers, and unsetting engine header signatures.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Analyze console script blocks and CSP configuration
- [x] Trace duplicate and technology-disclosing headers in network tab
- [x] Document discoveries in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Outline target templates and PHP modules needing nonces
- [x] Plan `.htaccess` modifications to clean up duplicates and unset `X-Powered-By`
- **Status:** complete

### Phase 3: Implementation
- [x] Add `nonce="{{ csp_nonce }}"` to all external script tags in Twig templates
- [x] Update `modules/themes/own-pay/Theme.php` to output nonces on footer script tags
- [x] Update `modules/gateways/cashmaal/CashmaalGateway.php` to add nonce to CashMaal form submit script
- [x] Update `public/.htaccess` to restrict security headers to static files and unset `X-Powered-By`
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run Composer, JS, CSS, JSON, and Twig linters
- [x] Run PHPUnit tests and verify zero regressions
- [x] Run PHPStan analysis and confirm Level 9 compliance
- **Status:** complete

### Phase 5: Delivery
- [x] Verify clean console and network headers
- [x] Report resolution details to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Add nonces to all scripts | Crucial to satisfy strict CSP without throwing console errors in standard browsers. |
| Limit .htaccess to static assets | Prevents headers from being sent twice (Apache + PHP). |

## Errors Encountered
| Error | Resolution |
|-------|------------|
