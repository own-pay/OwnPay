# Forensic Audit Report: Checkout Flow, Invoices, & API Payments

This report documents the forensic audit findings for the OwnPay checkout pipeline, including Invoices, Payment Links, API Payments, and general edge cases/vulnerabilities.

---

## Findings & Target Vectors

### CHK-001: Invoice Status Loophole
- **Target Component:** Invoice
- **Exact File & Line Numbers:** [InvoiceCheckoutController.php](src/Controller/Checkout/InvoiceCheckoutController.php#L35-L37)
- **Flaw Description:** The `show()` method in `InvoiceCheckoutController` only checks and sets `$invoice = null` if the status is strictly `'paid'`. Other invalid lifecycle statuses (such as `'draft'`, `'cancelled'`, `'expired'`) are completely ignored and allowed to proceed to checkout.
- **Exploit Scenario / Failure Mode:** A merchant cancels a sent invoice or keeps an invoice in the `'draft'` state. A customer who previously captured the unique invoice checkout URL token can still access the checkout, select a gateway, and successfully complete the payment. This allows bypass of intended merchant business flows and forces payment completions on invalid invoices.
- **Architectural Fix Plan:**
  1. In `InvoiceCheckoutController::show()`, restrict payable invoices strictly to an allowed status whitelist:
     ```php
     $allowedStatuses = ['sent', 'overdue'];
     if ($invoice && !in_array($invoice['status'], $allowedStatuses, true)) {
         $invoice = null; // Treat as invalid/expired checkout session
     }
     ```
  2. Ensure that any request matching a non-allowed status is automatically redirected to the expired page via `renderExpired()`.

---

### CHK-002: Missing Invoice Expiry/Due Date Validation
- **Target Component:** Invoice
- **Exact File & Line Numbers:** [InvoiceCheckoutController.php](src/Controller/Checkout/InvoiceCheckoutController.php#L29-L37)
- **Flaw Description:** The `InvoiceCheckoutController` completely fails to validate if the invoice's due date has passed when rendering the checkout page. There is no check comparing `$invoice['due_date']` against the current system date.
- **Exploit Scenario / Failure Mode:** A customer visits a checkout URL for an invoice whose due date was weeks ago. Because the status is still `'sent'` (never manually marked overdue/expired), the customer can pay the invoice. The merchant is forced to process an order under outdated terms/pricing.
- **Architectural Fix Plan:**
  1. Modify `InvoiceCheckoutController::show()` to check if the invoice `due_date` has passed:
     ```php
     if ($invoice && !empty($invoice['due_date']) && \OwnPay\Support\DateHelper::isPast($invoice['due_date'])) {
         // Auto-update database status to overdue
         $this->invoiceRepo->updateScoped((int)$invoice['id'], ['status' => 'overdue'], (int)$invoice['merchant_id']);
         $invoice = null; // Prevent checkout
     }
     ```
  2. Gracefully show the `renderExpired()` layout when the due date is exceeded.

---

### CHK-003: Missing Automatic Invoice Paid Transition
- **Target Component:** Invoice
- **Exact File & Line Numbers:**
  - [GatewayApiService.php](src/Service/Payment/GatewayApiService.php#L150)
  - [TransactionService.php](src/Service/Payment/TransactionService.php#L67)
  - [hooks.php](config/hooks.php#L76)
- **Flaw Description:** When a payment transaction is completed, the system fires the `payment.transaction.completed` hook. However, the system completely lacks any listener or service handler that responds to this event, parses the `invoice_id` out of the transaction's metadata JSON, and transitions the invoice status in `op_invoices` to `'paid'`.
- **Exploit Scenario / Failure Mode:** A customer successfully pays an invoice. The transaction transitions to `'completed'`, double-entry ledger journals are recorded, but the underlying invoice remains in the `'sent'` or `'overdue'` state forever. Automatic merchant shipping, billing reports, or ERP integrations are never triggered because the invoice status is never updated.
- **Architectural Fix Plan:**
  1. Create a hook listener under `src/Service/Payment/InvoicePaymentListener.php`:
     ```php
     namespace OwnPay\Service\Payment;

     use OwnPay\Event\EventManager;
     use OwnPay\Repository\InvoiceRepository;
     use OwnPay\Support\DateHelper;

     final class InvoicePaymentListener
     {
         private InvoiceRepository $invoiceRepo;

         public function __construct(InvoiceRepository $invoiceRepo)
         {
             $this->invoiceRepo = $invoiceRepo;
         }

         public function onTransactionCompleted(array $transaction): void
         {
             $meta = json_decode($transaction['metadata'] ?? '{}', true);
             $invoiceId = $meta['invoice_id'] ?? null;
             if ($invoiceId) {
                 $this->invoiceRepo->forTenant((int)$transaction['merchant_id'])->updateScoped((int)$invoiceId, [
                     'status'  => 'paid',
                     'paid_at' => DateHelper::now(),
                 ]);
             }
         }
     }
     ```
  2. Register the listener class in `config/services.php` and wire its `onTransactionCompleted` method to the `payment.transaction.completed` action hook in the boot loader.

---

### CHK-004: Payment Link Parameter Tampering
- **Target Component:** Payment Link
- **Exact File & Line Numbers:** [PaymentLinkCheckoutController.php](src/Controller/Checkout/PaymentLinkCheckoutController.php#L56-L65)
- **Flaw Description:** In `PaymentLinkCheckoutController::show()`, when the payment link has a dynamic amount (`$link['amount']` is empty/null/0), the controller extracts the amount from the query parameters: `$amount = $link['amount'] ?? $req->query('amount', '0');`. If `$amount` is greater than 0, it skips showing the amount entry form and immediately creates a pending transaction and redirects to the gateway, bypassing `min_amount` and `max_amount` bounds validations.
- **Exploit Scenario / Failure Mode:** A merchant creates a dynamic payment link with a configured minimum amount of `500.00` BDT. A malicious user bypasses the frontend input validation by visiting the URL directly: `/pay/{slug}?amount=0.01`. The backend immediately creates a transaction for `0.01` and redirects to the payment gateway, allowing the malicious user to pay a microscopic amount.
- **Architectural Fix Plan:**
  1. Inject bounds check logic in the `show()` GET endpoint:
     ```php
     $amount = $link['amount'] ?? $req->query('amount', '0');
     $amt = (float)$amount;
     if ($amt > 0) {
         $minAmount = (float)($link['min_amount'] ?? 0);
         $maxAmount = (float)($link['max_amount'] ?? 0);

         if (($minAmount > 0 && $amt < $minAmount) || ($maxAmount > 0 && $amt > $maxAmount)) {
             // Treat parameter as invalid, clear amount to force rendering the form with an error
             $amount = '0';
             $error = "Amount is out of valid bounds.";
         }
     }
     ```
  2. Ensure the form renders with the error message if the query parameter validation fails.

---

### CHK-005: Broken Stopped-Link Enforcement
- **Target Component:** Payment Link
- **Exact File & Line Numbers:** [CheckoutController.php](src/Controller/Checkout/CheckoutController.php#L52-L64)
- **Flaw Description:** When rendering the checkout interface (`CheckoutController::show`) or processing a payment gateway selection (`CheckoutController::pay`), the system fetches the transaction via `$this->txnRepo->findActiveForCheckout($ref)`. However, it never checks the status of the associated payment link (linked via `payment_link_id` in the transaction's metadata).
- **Exploit Scenario / Failure Mode:** A customer visits a payment link and receives a pending checkout transaction. Before they complete the checkout, the merchant deactivates the payment link or deletes it. Because the checkout page is already open, the customer selects their gateway and pays. The backend processes the payment successfully, bypassing the deactivation.
- **Architectural Fix Plan:**
  1. In `CheckoutController::show()` and `CheckoutController::pay()`, parse the transaction metadata:
     ```php
     $meta = json_decode($txn['metadata'] ?? '{}', true);
     $linkId = $meta['payment_link_id'] ?? null;
     if ($linkId) {
         $linkRepo = $this->c->get(\OwnPay\Repository\PaymentLinkRepository::class);
         $link = $linkRepo->forTenant((int)$txn['merchant_id'])->findScoped((int)$linkId);
         if (!$link || $link['status'] !== 'active' || (!empty($link['expires_at']) && \OwnPay\Support\DateHelper::isPast($link['expires_at']))) {
             // Cancel the transaction and reject the session
             $this->txnRepo->cancelByTrxId($txn['trx_id']);
             return $this->renderStatus($txn['trx_id'], 'expired');
         }
     }
     ```

---

### CHK-006: Payment Link Usage Tracking Fail
- **Target Component:** Payment Link
- **Exact File & Line Numbers:**
  - [PaymentLinkRepository.php](src/Repository/PaymentLinkRepository.php#L33-L39)
  - [TransactionService.php](src/Service/Payment/TransactionService.php#L67)
- **Flaw Description:** `PaymentLinkRepository` exposes `incrementUseCount(int $id)`, but this method is never invoked anywhere in the codebase. When a payment link transaction completes, the link's `use_count` is never incremented.
- **Exploit Scenario / Failure Mode:** A merchant creates a single-use payment link (`max_uses = 1`). Multiple customers can pay using the same link infinitely, since `use_count` is stuck at `0` forever.
- **Architectural Fix Plan:**
  1. Register a listener for the `payment.transaction.completed` hook.
  2. Parse the metadata to extract `payment_link_id`.
  3. Call `$linkRepo->incrementUseCount($linkId)`.
  4. Fetch the payment link record. If `max_uses > 0` and `use_count >= max_uses`, update its status to `'inactive'`.

---

### CHK-007: Idempotency Keys Completely Ignored
- **Target Component:** API Payment
- **Exact File & Line Numbers:**
  - [PaymentController.php](src/Controller/Api/PaymentController.php#L39)
  - [middleware.php](config/middleware.php#L36-L40)
- **Flaw Description:** The Merchant API payment endpoints completely ignore idempotency validation. There is no `IdempotencyMiddleware` registered in the `api` stack, and the controller does not inspect or track any `Idempotency-Key` headers.
- **Exploit Scenario / Failure Mode:** Due to a network glitch, a merchant's server retries an API call to `/api/v1/payments/initiate` twice. The server processes both requests independently, creating two separate transactions and double-charging the merchant's customer.
- **Architectural Fix Plan:**
  1. Create `src/Middleware/IdempotencyMiddleware.php` which intercepts the `Idempotency-Key` header.
  2. Use a Redis or database lock/table to track active keys scoped by merchant ID.
  3. Return `409 Conflict` if a request is already in progress, or return the cached response JSON if the request was already resolved.
  4. Add this middleware into the `api` stack in `config/middleware.php`.

---

### CHK-008: Missing API Payload PII and Callback URL Validation
- **Target Component:** API Payment
- **Exact File & Line Numbers:** [PaymentController.php](src/Controller/Api/PaymentController.php#L70-L74)
- **Flaw Description:** The `PaymentController` accepts `customer_email`, `customer_name`, `customer_phone`, and `callback_url` parameters directly from the merchant API request payload and passes them through without validating formats, lengths, or executing robust sanitation.
- **Exploit Scenario / Failure Mode:**
  - An attacker passes a Stored XSS payload in `customer_name` (e.g. `<script>steal(document.cookie)</script>`). When a staff member views the customer list or transaction audit logs in the Admin Dashboard, the script executes.
  - A client provides a malicious URL scheme in `callback_url` (e.g. `javascript:alert(1)`), which executes arbitrary JavaScript in the context of the user when the callback link is clicked on the admin dashboard.
- **Architectural Fix Plan:**
  1. Sanitize the customer inputs strictly using `InputSanitizer::email()`, `InputSanitizer::string()` (removing all HTML tags), and phone pattern matchers.
  2. Validate the `callback_url` format via `filter_var($url, FILTER_VALIDATE_URL)`.
  3. Enforce that the `callback_url` scheme must strictly be `http` or `https`.

---

### CHK-009: SQL Database Crash on Bad/Unsupported Currency
- **Target Component:** API Payment
- **Exact File & Line Numbers:** [PaymentController.php](src/Controller/Api/PaymentController.php#L49-L51)
- **Flaw Description:** The controller validates the currency parameter strictly using `empty($body['currency'])`. It does not verify if the currency exists in the list of active/supported currencies. If a client sends an extremely long string or invalid currency code, the application tries to insert it directly, causing a database column constraint crash (`CHAR(3)` overflow) and throwing a raw SQL strict mode exception.
- **Exploit Scenario / Failure Mode:** A merchant calls the API with `"currency": "US_DOLLAR"`. The database layer crashes due to a data-truncation strict mode exception on the `currency` column. The application displays a raw database error or generic 500 page, exposing instability.
- **Architectural Fix Plan:**
  1. Inject the `CurrencyService` into the `PaymentController`.
  2. Prior to processing, check if the currency is valid and supported via `$currencyService->isSupported($currencyCode)`.
  3. If unsupported, return a clean `422 Unprocessable Entity` HTTP response with a JSON error payload, avoiding unhandled system crashes.

---

### CHK-010: API Customer Details Completely Discarded
- **Target Component:** API Payment
- **Exact File & Line Numbers:**
  - [GatewayApiService.php](src/Service/Payment/GatewayApiService.php#L64-L75)
  - [PaymentController.php](src/Controller/Api/PaymentController.php#L65-L77)
- **Flaw Description:** `PaymentController::initiate()` extracts customer fields from the API request and packs them under `$data` keys `customer_email`, `customer_name`, and `customer_phone`. However, in `GatewayApiService::initiatePayment()`, the transaction is created using `$params['customer_id'] ?? null`. Since the controller passed the raw fields instead of a resolved `customer_id`, this evaluates to `null` and the customer details are completely lost.
- **Exploit Scenario / Failure Mode:** A merchant initiates a payment and passes correct customer details. The transaction is successfully created and paid, but the transaction record lacks any customer association (`customer_id` is `NULL`) and no customer record is created or updated in the brand's customer database (`op_customers`).
- **Architectural Fix Plan:**
  1. Inject the `CustomerPiiService` into the `PaymentController` or `GatewayApiService`.
  2. Prior to creating the transaction in `GatewayApiService::initiatePayment()`, check if a customer with the given email already exists for the merchant:
     ```php
     if (!empty($params['customer_email'])) {
         $customer = $customerService->findByEmail($merchantId, $params['customer_email']);
         if (!$customer) {
             $customer = $customerService->create($merchantId, [
                 'name'  => $params['customer_name'] ?? 'API Customer',
                 'email' => $params['customer_email'],
                 'phone' => $params['customer_phone'] ?? null,
             ]);
         }
         $params['customer_id'] = $customer['id'];
     }
     ```
  3. Pass the resolved `customer_id` during transaction creation.

---

### CHK-011: API Payment Duplication & Race Condition on Checkout Submission
- **Target Component:** API Payment
- **Exact File & Line Numbers:** [CheckoutController.php](src/Controller/Checkout/CheckoutController.php#L299-L313)
- **Flaw Description:** In `CheckoutController::pay()`, when a client submits an API gateway checkout, it calls `GatewayApiService::initiatePayment()`. Inside `initiatePayment()`, a second, duplicate transaction is created in the database for the actual gateway processing, while the parent transaction (from the checkout session) is left pending. Furthermore, there is no state validation or lock mechanism on the parent transaction or checkout session.
- **Exploit Scenario / Failure Mode:** If a customer double-clicks the "Pay Now" button or selects a gateway multiple times, `CheckoutController::pay()` executes multiple times concurrently. It triggers multiple parallel `initiatePayment()` calls, creating multiple duplicate transactions in the database and triggering multiple payment intents with the gateway. This causes double-charging, gateway errors, and invalid duplicate ledger journal entries.
- **Architectural Fix Plan:**
  1. Add a unique transaction locking mechanism (e.g. database-level row lock `SELECT ... FOR UPDATE` or Redis lock) when handling `pay()`.
  2. Implement state-based transition checks. If a transaction is already transitioning or processing, reject subsequent concurrent requests.
  3. Instead of blindly creating new transaction entries on every gateway select, reuse/update the existing transaction record or strictly link them via a parent-child relationship with a lock.

---

### CHK-012: Sensitive Merchant Gateway Credentials Leak in Twig Render Context
- **Target Component:** Invoice | Payment Link | API Payment
- **Exact File & Line Numbers:**
  - [CheckoutController.php](src/Controller/Checkout/CheckoutController.php#L78)
  - [GatewayConfigRepository.php](src/Repository/GatewayConfigRepository.php#L26-L29)
- **Flaw Description:** The `CheckoutController::show()` loads active API gateways via `$this->apiGw->forTenant($mid)->listActive()`. The `listActive()` method executes `SELECT gc.*`, which retrieves all fields from `op_gateway_configs`—including `credentials_enc` (encrypted gateway secrets) and the `settings` JSON block. The entire array is then directly passed into the Twig render context as `'gateways' => $gateways`.
- **Exploit Scenario / Failure Mode:** Although Twig files might not explicitly render `credentials_enc`, having sensitive merchant database credentials, keys, or private gateway configurations packed in the controller's public-facing view render context is a severe risk. If any debug extension is active, or if a custom theme implements `{{ dump(gateways) }}`, all encrypted merchant secrets and sensitive configurations will be leaked directly in the HTML response source to the customer.
- **Architectural Fix Plan:**
  1. Modify `GatewayConfigRepository::listActive()` or the controller mapping to strictly filter out any secret/sensitive fields:
     ```php
     // In listActive() select only non-sensitive visual metadata fields:
     "SELECT gc.id, gc.merchant_id, gc.mode, gc.status, g.slug, g.name, g.type, g.logo_path ..."
     ```
  2. Ensure that raw database fields like `credentials_enc` are NEVER loaded into public view controllers, but are kept isolated for backend adapter execution only.
