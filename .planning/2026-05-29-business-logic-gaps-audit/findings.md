# Findings & Decisions: Comprehensive Business Logic and Vulnerability Audit

## Executive Summary
This audit focuses on identifying and documenting business logic gaps, scoping issues, concurrency vulnerabilities, and security flaws across the core operational zones of OwnPay:
1. **Payment processing & callback flows**
2. **Ledger & financial operations**
3. **Device & mobile app integration APIs**
4. **Plugin & theme sandbox constraints**
5. **REST API security & scopes**

---

## Detailed Findings

### 1. Webhook Signature Verification Bypass
* **Vulnerability:** Unauthenticated/Spoofed Webhooks Bypass Signature Checks
* **Location:** `OwnPay\Gateway\GatewayBridge::verifyWebhookSignature()` (lines 157-159)
* **Description:** If a webhook request is received for a gateway whose adapter is not currently registered or loaded in the bridge, the method returns `true` as a fallback default. This allows any external attacker to spoof webhook requests using arbitrary unregistered gateway slugs, bypassing signature verification checks entirely. This allows malicious payloads to trigger system-wide webhook actions and listener tasks (`webhook.incoming.{$gateway}`).
* **Remediation:** If the adapter is not found, signature verification should fail by returning `false` (or throw an exception), rather than defaulting to `true`.

### 2. REST API Key Scope Verification Deficit
* **Vulnerability:** Unenforced Scopes on REST API Keys
* **Location:** `OwnPay\Middleware\BearerAuthMiddleware` and REST Controllers (`PaymentController`, `RefundController`, `ApiKeyController`)
* **Description:** API keys in `op_api_keys` have a `scopes` JSON column. However, no controller or middleware validates the scopes associated with the resolved API key request context. This allows an API key with a read-only scope (or empty scopes) to invoke mutate endpoints (e.g., initiating payments, processing refunds, generating/revoking API keys).
* **Remediation:** Enforce key scope checks (e.g., `read` vs `write`) in `BearerAuthMiddleware` or at the controller action layer to reject requests attempting operations beyond their configured scope permissions.

### 3. Race Condition in Checkout Callback Capture
* **Vulnerability:** Double-Capture callback processing race condition
* **Location:** `OwnPay\Controller\Checkout\CheckoutController::status()`
* **Description:** Unlike `PaymentIntentCheckoutController::status()`, which utilizes a database-level status lock (`callback_processing`) to serialize concurrent capture events, the standard `CheckoutController::status()` directly calls the gateway's callback execution code when the transaction is in `processing` status. This exposes the checkout pipeline to double-capture callback race conditions when duplicate redirects/callbacks arrive simultaneously.
* **Remediation:** Align `CheckoutController::status()` with `PaymentIntentCheckoutController::status()` by acquiring a database-level lock (updating status to `callback_processing` where current status is `processing`) before triggering gateway callbacks.

### 4. Cross-Brand Checkout Leakage (White-Label Bypass)
* **Vulnerability:** Missing custom-domain host verification on checkout tokens
* **Location:** `CheckoutController::show()` and `PaymentIntentCheckoutController::show()`
* **Description:** OwnPay resolves the active brand/merchant context from custom domains (`DomainMiddleware` injects `merchant_id`). However, if an end-user accesses the checkout flow via Brand A's custom domain (e.g., `branda.test`) but specifies a transaction token/UUID belonging to Brand B, the controllers load and display Brand B's payment details without validating if the transaction/intent merchant ID matches the domain-resolved merchant ID. This leaks transactions across brands and breaks white-label domain scoping rules.
* **Remediation:** Enforce domain validation checks inside checkout show controllers to confirm that the transaction's/intent's `merchant_id` matches the domain-resolved `merchant_id` request attribute if present.

### 5. Idempotency Concurrent Exception Crash
* **Vulnerability:** Uncaught PDOException on Concurrent Duplicate Requests
* **Location:** `OwnPay\Middleware\IdempotencyMiddleware::handle()`
* **Description:** When duplicate requests are sent simultaneously, both threads bypass the initial duplicate check (`IdempotencyService::check()`) and attempt to insert the new idempotency key. One thread fails at the SQL database insertion stage due to the unique key constraint on `(merchant_id, idempotency_key)`, throwing a `PDOException`. The middleware does not catch this exception, leading to a raw 500 database error crash instead of returning a clean HTTP `409 Conflict`.
* **Remediation:** Wrap the idempotency key insertion block in a try-catch block for database integrity violations (SQLSTATE 23000) and return a clean HTTP `409 Conflict` response indicating that a request with this key is already in progress.

### 6. Unchecked Ledger Balances on Refund
* **Vulnerability:** Infinite Over-Refunding / Deficit Liability Balancing
* **Location:** `OwnPay\Service\Payment\RefundService::create()`
* **Description:** The system checks that the total refund amount does not exceed the parent transaction amount, but never verifies whether the merchant has a sufficient payable ledger balance in `MERCHANT_PAYABLE` before initiating the refund. This allows merchants to issue refunds that push their ledger balances into unmonitored negative figures, exposing the platform owner to financial loss.
* **Remediation:** Query `LedgerService::calculateBalance($merchantId, $currency)` before triggering the gateway refund and ensure the merchant's `MERCHANT_PAYABLE` liability balance is sufficient to cover the refund.

---

## Technical Decisions & Action Plan

1. **Webhook Security:** Webhook signature verification defaults to false/rejection if no adapter is matched.
2. **API Scope Checks:** Update `BearerAuthMiddleware` to check scopes against a defined routing registry or define simple read/write access groups.
3. **Callback Locking:** Standard checkout status updates must match intent checkout status locking design.
4. **Scoping Isolation:** Add domain request validation to checkout routes.
5. **Idempotency Concurrency:** Wrap insertion in `IdempotencyService` or `IdempotencyMiddleware` with try-catch targeting unique-constraint conflicts.
6. **Financial Security:** Add a configurable "allow_negative_balance" check or strictly validate payable balances prior to refunds.
