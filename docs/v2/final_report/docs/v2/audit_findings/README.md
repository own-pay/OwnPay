# Audit Findings & Fixing Plans

This directory contains the master security and architecture audit report for the OwnPay gateway, along with the detailed technical fixing plans for the verified bugs.

## Contents

1. **[Master Audit Report](ownpay_master_audit_report.md)**: Mapped codebase footprint, severity matrix, single-owner business-model analysis, scalability assessment, and detailed reports for 19 findings.
2. **[Technical Fixing Plan](ownpay_fixing_plan.md)**: Detailed step-by-step code-level instructions for resolving the 11 verified security, concurrency, and validation bugs identified in the report.

---

## Mapped Security & Logic Findings (Cross-Checked & Confirmed)

All major findings from the audit report have been verified against the live codebase:

| Finding ID | Severity | Component | Finding Description | Fix Status |
|---|---|---|---|---|
| **FIND-003** | **CRITICAL** | Core/DI | `Database::getInstance()` throws `RuntimeException` in production because the static singleton is never populated. | Planned |
| **FIND-004** | **CRITICAL** | Gateways | Un-gated `mock_` token checkout-bypass in Affirm, Afterpay, and Bitpay adapters. | Planned |
| **FIND-001** | **HIGH** | MFS / SMS | Swapped argument order in `MfsService::processIncomingSms()` when calling `SmsParserService::parse()`. | Planned |
| **FIND-005** | **HIGH** | Gateways | Unverified/stubbed webhook validation and simulated refunds in 2Checkout. | Planned |
| **FIND-019** | **HIGH** | Mobile API | JWT authentication middleware guards device pairing, blocking companion app onboarding. | Planned |
| **FIND-002** | **MEDIUM** | Concurrency | Synchronous cURL gateway refund request occurs inside a DB transaction holding `FOR UPDATE` locks. | Planned |
| **FIND-006** | **MEDIUM** | Tooling | PHPUnit version 12 is incompatible with the declared minimum PHP version (8.2) in `composer.json`. | Planned |
| **FIND-007** | **MEDIUM** | Auth / Security | Rate limiter fails open on database error or connection loss. | Planned |
| **FIND-009** | **MEDIUM** | Plugins | Plugin with no sandbox context bypasses database SQL validation checks. | Planned |
| **FIND-016** | **MEDIUM** | Payments | Callback verified amount is not checked against the stored order amount. | Planned |
| **FIND-017** | **MEDIUM** | MFS / SMS | exact SMS TrxID matching fails because it searches against internal `OP-` references instead of provider references. | Planned |
| **FIND-008** | **LOW** | Webhook / SSRF | Outbound webhook URL validator ignores IPv6 (AAAA) records and is vulnerable to DNS-rebinding. | Planned |
| **FIND-010** | **LOW** | Middleware | `DomainMiddleware` allows arbitrary Host header bypass if set to `'localhost'`. | Planned |
| **FIND-011** | **LOW** | Payments | Invoice subtotal and total calculations can go negative due to unclamped prices/discounts. | Planned |
| **FIND-014** | **LOW** | Payments | `form_html` sanitizer filters out external scripts but keeps inline `<script>` tags. | Planned |
| **FIND-015** | **LOW** | Notifications | Unsecured shared JSON file fallback in system temp directory leaks transaction metadata. | Planned |
| **FIND-012** | **INFORMATIONAL** | Security | TOTP drift window allows discrepancy offset of 2 steps (±60 seconds), widening replay window. | Planned |

---

## Verification & Guidelines

When implementing the fixes detailed in `ownpay_fixing_plan.md`, developers must strictly adhere to the following developer guidelines to avoid regressions:

1. **Strict Types & Parameterization**: Ensure every PHP file starts with `declare(strict_types=1);` and utilize parameterized statements for all database updates.
2. **Double-Entry Balance Guardrails**: Do not modify ledger entry sequences or balances outside atomic database transaction blocks.
3. **White-Label Custom Domain Constraints**: Ensure public paths (like payment links) construct using `DomainUrlService` to respect domain mapping configurations.
4. **CI Testing verification**: Run composer validation, PHPStan (at level 9), and linters on all modified code:
   ```bash
   composer validate
   npm run lint && composer lint:twig
   vendor/bin/phpstan analyse
   ```
