# Findings: PHPStan Level 5 Static Analysis (Skipping `src/`)

We have analyzed all files under the `cli/`, `config/`, and `modules/` directories at static analysis Level 5.
PHPStan reported exactly **113 errors** across **20 files**.

## Categories of Static Analysis Errors

### 1. Route Nullsafe Method Calls (`nullsafe.neverNull`)
* **Files:** `config/routes/web.php` (Line 36)
* **Problem:** `container()?->` used on a non-nullable `Container`.
* **Fix:** Change to `container()->`.

### 2. Constructor Parameter Counts (`arguments.count`)
* **Files:** `config/services.php` (Line 209)
* **Problem:** `PluginInstaller` constructor receives 2 parameters, but expects/requires 1.
* **Fix:** Remove the unused second parameter.

### 3. Addon strict comparisons and missing methods (`identical.alwaysFalse` & `method.notFound`)
* **Files:** 
  - `modules/addons/mail-gateway/Plugin.php` (Line 157)
  - `modules/addons/telegram-bot/Plugin.php` (Lines 98, 104, 129)
* **Problems:** 
  - Strict check `=== '0'` on mixed variables that evaluate to false.
  - Call to undefined method `Request::jsonBody()`.
* **Fixes:**
  - Cast values to strings before comparing.
  - Replace `jsonBody()` with the correct array helper or request body getter.

### 4. Redundant Null Coalescing (`nullCoalesce.offset` / `nullCoalesce.expr`)
* **Files:** Multiple addons and gateways (`mail-gateway`, `sms-gateway`, `aamarpay`, `binance-merchant-api`, `cashmaal`, `eps`, `stripe`, etc.).
* **Problem:** Using `??` on keys that are already guaranteed to exist and be non-nullable according to their types.
* **Fix:** Remove redundant null coalescing checks.

### 5. Curl Option Type Safety (`argument.type`)
* **Files:** `nagad-merchant-api`, `now-payments`, `oxapay`, `paypal-checkout`, `paystation`, `shurjopay` gateways.
* **Problem:** Passing `CURLOPT_SSL_VERIFYHOST => false` (expecting `0` or `2` of type `int`).
* **Fix:** Change `false` to `0`.

### 6. Gateway Adapter Return Shapes (`return.type`)
* **Files:** `apple-pay`, `bkash-api`, `google-pay`, `nagad-merchant-api`, `now-payments`, `oxapay`, `paypal-checkout`, `paystation`, `shurjopay` gateways.
* **Problem:** The `verify()` methods returned array structures mismatching `GatewayAdapterInterface::verify()` return contract. Specifically, return shapes specify types like `gateway_trx_id: string`, but the method returns `gateway_trx_id: null` on failure/error, which triggers compile-time errors.
* **Fix:** Fallback/cast these nullable keys to non-empty strings (e.g. `""`) or add missing keys (like `'amount' => null`) to satisfy the shape contract.

### 7. Gateway Field Options Compatibility (`method.childReturnType` / `return.type`)
* **Files:** `sslcommerz`, `stripe`, `paystation` gateways.
* **Problem:** `fields()` method returns option arrays as `['test', 'live']` (type `array<int, string>`), but `PluginInterface::fields()` expects an associative map of options `['test' => 'test', 'live' => 'live']` (type `array<string, string>`).
* **Fix:** Convert options arrays to string key-value dictionaries.

---

## Direct Action Plan & Validation

We will modify each of these files, run static analysis to confirm zero errors, and verify the integration test suite via PHPUnit. All modifications will strictly avoid touching `src/`.
