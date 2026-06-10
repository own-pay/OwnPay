# Task Plan: OwnPay Audit Report Code Fixes

## Goal
Implement fixes for all 11 confirmed security, validation, and performance bugs in the OwnPay codebase as detailed in the fixing plan.

## Current Phase
Verification Complete

## Phases

### Phase 1: Implement Critical & High severity fixes
- [x] Fix FIND-002 (Outbound gateway refund cURL call executed inside DB transaction holding FOR UPDATE)
- [x] Fix FIND-006 (PHPUnit requirement: Upgrade to PHPUnit 12.5.29 and minimum PHP 8.3 compatibility)
- [x] Fix FIND-007 (Rate limiter fails open on DB error: return 503 fail-closed for auth endpoints)
- [x] Fix FIND-009 (EventManager `db.query.before` SQL validation bypasses check if plugin sandbox is null)
- **Status:** complete

### Phase 2: Implement Medium & Low severity fixes
- [x] Fix FIND-016 (GatewayApiService: Callback amount not verified against order amount)
- [x] Fix FIND-017 (SMS TrxID namespace mismatch: Add `provider_trx_id` column to `op_transactions` and update lookup)
- [x] Fix FIND-008 (UrlValidator: DNS-rebinding TOCTOU mitigation & IPv6 resolution support)
- [x] Fix FIND-010 (DomainMiddleware: Restrict localhost bypass only to loopback IP clients)
- [x] Fix FIND-011 (InvoiceService: Clamp invoice totals to non-negative)
- [x] Fix FIND-014 (GatewayApiService: Strip all inline scripts in `sanitizeFormHtml` except safe auto-submit forms)
- [x] Fix FIND-015 (MobileNotificationService: Secure fallback temp-file or remove shared file fallback)
- [x] Fix FIND-012 (Authenticator: Tighten default TOTP discrepancy limit from 2 to 1)
- **Status:** complete

### Phase 3: Testing & Verification
- [x] Run PHPUnit tests and ensure all tests pass
- [x] Run Twig / JS / CSS lints and ensure clean code
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Split FIND-002 into 2 transactions | Allows committing the pending refund record to release DB row locks BEFORE making external gateway network calls, then reconciling in a second short transaction. |
| Selective rate limiter fail-closed | Only fail-closed for authentication/pairing endpoints, and fail-open for public pages/invoices to avoid unnecessary DOS of invoice pages during DB hiccup. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
