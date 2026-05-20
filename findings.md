# Findings: Checkout Flow, Invoices, & API Payments Audit

This file stores permanent findings during the forensic audit of the checkout pipeline.

## 1. Invoice Checkout Flow Findings
- **CHK-001 (Invoice Status Loophole)**: `InvoiceCheckoutController::show` only blocks `'paid'` status. Draft, cancelled, or expired invoices can be loaded and paid.
- **CHK-002 (Missing Invoice Expiry)**: `InvoiceCheckoutController::show` does not validate if due date is in the past. Expired invoices can be checked out.
- **CHK-003 (Missing Automatic Invoice Paid Transition)**: No hook/listener updates the invoice status in `op_invoices` to `'paid'` when a transaction completes.

## 2. Payment Links Findings
- **CHK-004 (Payment Link Parameter Tampering)**: Dynamic links with no fixed amount allow GET query parameter `?amount=` to bypass `min_amount`/`max_amount` bounds validation.
- **CHK-005 (Broken Stopped-Link Enforcement)**: `CheckoutController` doesn't verify if underlying payment link is still active/unexpired on render or payment submit.
- **CHK-006 (Payment Link Usage Tracking Fail)**: `use_count` is never incremented in the database when a transaction completes, making `max_uses` useless.

## 3. API Payment Integration Findings
- **CHK-007 (Idempotency Keys Ignored)**: API payments do not support `Idempotency-Key` or equivalent, leading to potential duplicate charges on retry.
- **CHK-008 (Missing API Payload PII and URL Validation)**: Direct acceptance of customer fields and callback URLs without proper sanitation/validation leads to potential Stored XSS and redirection vulnerabilities.
- **CHK-009 (SQL Database Crash on Bad Currency)**: Direct insert of currency string without check against supported currencies triggers `CHAR(3)` overflow/truncation SQL exception, crashing the system.
- **CHK-010 (API Customer Details Completely Discarded)**: `GatewayApiService::initiatePayment` hardcodes `customer_id` as null because it expects `customer_id` parameter instead of raw fields.

## 4. Edge Cases & Vulnerabilities Findings
- **CHK-011 (API Payment Duplication & Race Condition)**: Multiple clicks trigger multiple parallel transaction creation and double charges due to no concurrency/locking controls on checkout submission.
- **CHK-012 (Sensitive Merchant Credentials Leak)**: `CheckoutController::show` queries `SELECT gc.*` which retrieves sensitive `credentials_enc` and exposes it directly in Twig render context.
- **Double Checkout sessions**: Multi-click dynamic links create multiple transactions. Resolved via session-bind of transactions.
- **Data Leaks & Security**: High-severity XSS threats through unsafe `callback_url` parameters and client names.

