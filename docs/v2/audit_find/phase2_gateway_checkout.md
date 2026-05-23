# Phase 2 Audit Report: Gateway, Webhook & Checkout Integrity

This report documents the forensic architectural and codebase audit of **OwnPay's** checkout controllers, payment gateway integrations, inbound webhook processors, and cross-tenant transaction/ledger isolation systems.

---

## Executive Summary

Phase 2 audit reveals multiple critical defects in the payment callback and webhook processing loops. These defects will cause immediate transaction completion failures, 500 Server Errors during customer checkout redirections, database constraint violations in the double-entry ledger system, and complete failures of asynchronous Stripe webhook events.

---

## Detailed Findings

### 1. SSLCommerz Webhook Callback Transaction Resolution Failure
- **Severity**: Critical (Breaks payment completions)
- **Files**: 
  - [`SslCommerzGateway.php`](modules/gateways/sslcommerz/SslCommerzGateway.php#L97-L123)
  - [`GatewayApiService.php`](src/Service/Payment/GatewayApiService.php#L122-L138)
- **Description**:
  In `GatewayApiService::handleCallback()`, the system resolves the transaction by checking:
  ```php
  $trxId = $callbackData['trx_id'] ?? $verification['gateway_trx_id'] ?? '';
  ```
  However:
  1. SSLCommerz passes the merchant transaction ID via the `tran_id` parameter in callbacks, not `trx_id`. Therefore, `$callbackData['trx_id']` resolves to `null`.
  2. `SslCommerzGateway::verify()` maps `gateway_trx_id` to SSLCommerz's bank transaction ID (`bank_tran_id`):
     ```php
     'gateway_trx_id' => $data['bank_tran_id'] ?? '',
     ```
  3. Consequently, `$trxId` is set to `bank_tran_id` (the bank's reference), and `handleCallback` attempts to look up the transaction using:
     ```php
     $transaction = $this->transactions->findByTrxId($merchantId, $trxId);
     ```
     This lookup will fail to find any matching transaction in the database (since our database expects the internal `trx_id` starting with `OP-`).
- **Impact**: All SSLCommerz transactions will remain in `pending` status indefinitely, and the customer callback return flow will fail.

---

### 2. Stripe Webhook Verification Payload Structure Incompatibility
- **Severity**: Critical (Breaks Stripe webhooks)
- **Files**:
  - [`StripeGateway.php`](modules/gateways/stripe/StripeGateway.php#L95-L119)
  - [`UnifiedWebhookController.php`](src/Controller/Webhook/UnifiedWebhookController.php#L79-L90)
- **Description**:
  `StripeGateway::verify()` expects the callback payload to have a top-level `session_id` parameter:
  ```php
  $sessionId = $callbackData['session_id'] ?? '';
  ```
  While this works for the frontend redirect return URL (which appends `?session_id={CHECKOUT_SESSION_ID}`), it fails completely for Stripe's asynchronous webhook payloads sent to `/webhook/stripe`.
  Stripe's webhook payload is a structured JSON event where the session ID is nested under `data.object.id`.
  When a webhook hits `UnifiedWebhookController` and is routed to `StripeGateway::verify()`, the code searches for a top-level `session_id`, finds `null`, and attempts to make a curl request to:
  `https://api.stripe.com/v1/checkout/sessions/` (empty session ID).
- **Impact**: Asynchronous Stripe webhooks will fail with 400/404 API errors from Stripe, preventing out-of-band payment validation and completion.

---

### 3. Double-Entry Ledger System Database Constraint Crash
- **Severity**: Critical (Halts checkout completion & throws 500 errors)
- **Files**:
  - [`LedgerService.php`](src/Service/Payment/LedgerService.php#L29-L70)
  - [`LedgerRepository.php`](src/Repository/LedgerRepository.php#L94-L118)
  - [`schema.sql`](database/schema.sql#L490-L502)
- **Description**:
  `op_ledger_transactions` has a database column constraint: `` `merchant_id` BIGINT UNSIGNED NOT NULL ``.
  `LedgerRepository` uses the `TenantScope` trait. In `createTransaction()`, it extracts the merchant ID from its internal scope:
  ```php
  $mid = $this->tenantId;
  ```
  However, `LedgerService` resolves a singleton instance of `LedgerRepository` from the container but never calls `forTenant($merchantId)` on it before starting transactions.
  Because `$this->ledger` is unscoped, `$this->tenantId` remains `null`.
  When inserting a row into `op_ledger_transactions`, the query attempts to insert `NULL` for `merchant_id`. This causes an immediate:
  `PDOException: Integrity constraint violation: 1048 Column 'merchant_id' cannot be null`
- **Impact**: Every successful payment processing callback that invokes `LedgerService::recordPaymentReceived()` will crash the execution thread, roll back ledger entries, and send a 500 Server Error response to the gateway/user.

---

### 4. WebhookInboundProcessor Header Case-Sensitivity Mismatch
- **Severity**: High (Prevents validation of webhooks)
- **Files**:
  - [`WebhookInboundProcessor.php`](src/Gateway/WebhookInboundProcessor.php#L85-L93)
  - [`WebhookInboundProcessor.php`](src/Gateway/WebhookInboundProcessor.php#L239-L248)
- **Description**:
  `WebhookInboundProcessor::extractHeaders()` normalizes headers from `$_SERVER` by converting underscore to dash:
  ```php
  $headers[str_replace('_', '-', substr($key, 5))] = $value;
  ```
  This returns fully capitalized headers (e.g., `X-OP-SIGNATURE`).
  However, `extractWebhookHeaders()` only checks for lowercase or mixed-camelcase variants:
  ```php
  'signature' => $headers['x-op-signature'] ?? $headers['X-OP-Signature'] ?? '',
  ```
  Because `X-OP-SIGNATURE` is not in the list of checked keys, all values (`signature`, `timestamp`, `eventId`, `eventType`) resolve to empty strings.
- **Impact**: `validateRequest()` fails with "Missing required webhook headers" for all requests initialized via `extractHeaders()`.

---

### 5. UnifiedWebhookController Request rawBody Null Type Error
- **Severity**: High (Potential PHP crash)
- **Files**:
  - [`UnifiedWebhookController.php`](src/Controller/Webhook/UnifiedWebhookController.php#L132-L141)
- **Description**:
  In `resolveMerchantFromPayload()`, the controller retrieves the request body:
  ```php
  $body = $req->rawBody();
  ```
  Since `rawBody()` can return `null` (e.g., if there is no request body, such as in simple verification probes or GET requests), passing `$body` directly to `parse_str` will crash:
  ```php
  parse_str($body, $data); // TypeError: parse_str() must be of type string, null given
  ```
- **Impact**: Any empty webhook or probe request targeting `/webhook/*` will cause a 500 error.

---

### 6. Double API Latency Call during bKash Gateway Initiations
- **Severity**: Medium (Latency/Performance bottleneck)
- **Files**:
  - [`BkashApiGateway.php`](modules/gateways/bkash-api/BkashApiGateway.php#L58-L96)
- **Description**:
  Every time a bKash checkout is initiated or verified, the gateway makes an HTTP request to `/tokenized/checkout/token/grant` to fetch a new token, followed by the actual transaction request. The bKash token is not cached or persisted.
- **Impact**: Doubles payment latency for the user (adding ~1-2 seconds per step).

---

### 7. Hardcoded Base Currency and Silent Exchange Rate Fallback
- **Severity**: Medium (Accounting discrepancies)
- **Files**:
  - [`CurrencyService.php`](src/Service/Payment/CurrencyService.php#L15)
  - [`CurrencyService.php`](src/Service/Payment/CurrencyService.php#L100-L124)
- **Description**:
  1. The base currency is hardcoded: `private string $baseCurrency = 'USD';`, making it impossible to configure an alternative base currency (such as BDT) through the system settings.
  2. If a conversion rate is missing in `op_exchange_rates`, the service defaults the rate to `1.00000000` silently, rather than throwing a validation exception or warning.
- **Impact**: If a brand operates in multiple currencies but lacks explicit exchange rates, conversion rates default to 1:1, leading to serious financial reconciliation errors.
