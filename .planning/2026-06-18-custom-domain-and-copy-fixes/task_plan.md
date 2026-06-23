# Task Plan - Custom Domain and Copy Fixes

## Phase 1: Planning and Research (Complete)
- [x] Analyze DNS verification record name and value discrepancy.
- [x] Analyze admin dashboard copy-to-clipboard button double-triggering and literal copying.
- [x] Analyze DomainMiddleware pending vs inactive status routing.
- [x] Create planning files on disk and implementation_plan.md artifact.

## Phase 2: Implementation (Complete)
- [x] Apply fixes to `DnsVerifier.php` to support both record name/value variations.
- [x] Apply fixes to `DomainMiddleware.php` to correctly return 503 for pending domains.
- [x] Apply fixes to `admin.js`, `developer.js`, and `domains.js` for copying behaviour.
- [x] Add cache-busting version parameters to Twig scripts (`base.twig`, `developer/index.twig`, `domains/index.twig`) to force loading updated JS assets.
- [x] Implement DNS-over-HTTPS (DoH) fallback via Cloudflare in `DnsVerifier.php` to bypass slow local/cPanel DNS resolver caches during propagation.

## Phase 3: Verification (Complete)
- [x] Run PHPUnit tests to check for regressions.
- [x] Run assets linters.
