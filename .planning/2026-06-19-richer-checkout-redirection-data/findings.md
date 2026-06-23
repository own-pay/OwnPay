# Findings: Strict Payment ID Checkout Redirection & Lookup

## Target Files & Code modifications

### 1. Routes Mapping
* **File:** [api.php](file:///c:/laragon/www/ownpay/config/routes/api.php)
* **Change:**
  ```diff
  - $router->get('/api/v1/payments/{trx_id}', 'Api\\PaymentController@show', 'api');
  + $router->get('/api/v1/payments/{payment_id}', 'Api\\PaymentController@show', 'api');
  ```

### 2. Payment Service Layer
* **File:** [PaymentService.php](file:///c:/laragon/www/ownpay/src/Service/Payment/PaymentService.php)
* **Change:** Add `findByUuid(string $uuid): ?array` to bridge intent UUID lookups to the `PaymentIntentRepository`.

### 3. API Payments Controller
* **File:** [PaymentController.php](file:///c:/laragon/www/ownpay/src/Controller/Api/PaymentController.php)
* **Change:**
  * Read parameter `payment_id` instead of `trx_id`.
  * Validate that `payment_id` matches UUID format.
  * Retrieve Payment Intent by UUID.
  * Resolve transaction dynamically by the Payment Intent's database ID (`findByIntentId`).
  * Return intent details if no transaction has been started yet.

### 4. Checkout Template & Redirection Controller
* **File:** [PaymentIntentCheckoutController.php](file:///c:/laragon/www/ownpay/src/Controller/Checkout/PaymentIntentCheckoutController.php)
  * `cancel()`: Redirect using `payment_id` parameter instead of `token`.
  * `renderStatus()`: Add `'intent_payment_id' => $intent['uuid']` to the Twig rendering parameters.
* **File:** [checkout-status.twig](file:///c:/laragon/www/ownpay/templates/checkout/checkout-status.twig)
  * Map `data-payment-id="{{ intent_payment_id }}"` instead of `data-token="{{ intent_token }}"`.
* **File:** [checkout-status.js](file:///c:/laragon/www/ownpay/public/assets/js/checkout-status.js)
  * Read `data-payment-id` from wrapper element and append `payment_id` to final redirection URL instead of `token`.

### 5. Integration Tests
* **File:** [TrxIdLookupApiTest.php](file:///c:/laragon/www/ownpay/tests/Integration/TrxIdLookupApiTest.php)
  * Seed `op_payment_intents` records in `setUp()`.
  * Rewrite `testPaymentLookupByOwnPayAndGatewayTrxId` to `testPaymentLookupByPaymentId` to test strict UUID query.
