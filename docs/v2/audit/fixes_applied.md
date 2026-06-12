# OwnPay — Fixes Applied (Claude Fable 5)

Baselines before any fix: PHPStan level 9 = **0 errors**; PHPUnit = **475 tests / 1523 assertions / 1 skipped / 0 failures** (PHP 8.3.28).
Each fix below: backed up to `docs/v2/audit/backups/<file>.bak`, edited minimally, `php -l` verified.

---

### FIX-001 — [FIND-002] — Enable TLS certificate verification on 7 gateway adapters
Files (live HTTPS calls had cert verification disabled):
- `modules/gateways/cashmaal/CashmaalGateway.php:96`
- `modules/gateways/nagad-merchant-api/NagadMerchantApiGateway.php:187,268,339`
- `modules/gateways/now-payments/NowPaymentsGateway.php:108–109`
- `modules/gateways/oxapay/OxapayGateway.php:89–90`
- `modules/gateways/paystation/PaystationGateway.php:121–122,184–185`
- `modules/gateways/paypal-checkout/PaypalCheckoutGateway.php:115–116,199–200,225–226,292–293`
- `modules/gateways/shurjopay/ShurjopayGateway.php:138–139,227–228,292–293`
Status: APPLIED

Before:
```php
CURLOPT_SSL_VERIFYPEER => false,
CURLOPT_SSL_VERIFYHOST => 0,
```

After:
```php
CURLOPT_SSL_VERIFYPEER => true,
CURLOPT_SSL_VERIFYHOST => 2,
```
(Cashmaal had only `VERIFYPEER => false`; replaced with `VERIFYPEER => true` + added `VERIFYHOST => 2`.)

Reason: Disabled TLS verification exposed every live payment-provider call to man-in-the-middle interception (credential/payload theft, forged responses). Enabling peer+host verification is the correct, safe default and does not affect the plain-HTTP sandbox endpoints (TLS options apply only to HTTPS).

---

### FIX-002 — [FIND-008] — Use CSPRNG for Nagad cryptographic challenge
File: `modules/gateways/nagad-merchant-api/NagadMerchantApiGateway.php:486`
Status: APPLIED

Before:
```php
$randomString .= $characters[rand(0, $charactersLength - 1)];
```

After:
```php
$randomString .= $characters[random_int(0, $charactersLength - 1)];
```

Reason: The Nagad payment-initialization "challenge" is a security token mixed into RSA-encrypted, signed sensitive data. `rand()` is not cryptographically secure and is predictable; `random_int()` is a CSPRNG suitable for security tokens.

---

### FIX-003 — [FIND-003] — Capture tenant-scoped clone in PaymentCompletionListener
File: `src/Service/Payment/PaymentCompletionListener.php:74–92`
Status: APPLIED

Before:
```php
if ($linkId !== null) {
    $this->linkRepo->forTenant($merchantId);
    $this->linkRepo->incrementUseCount($linkId);

    // Check max_uses
    $link = $this->linkRepo->findScoped($linkId);
    if ($link) {
        ...
        $this->linkRepo->updateScoped($linkId, ['status' => 'inactive']);
    }
}
```

After:
```php
if ($linkId !== null) {
    // TenantScope::forTenant() returns a scoped CLONE — capture it.
    $scopedLinks = $this->linkRepo->forTenant($merchantId);
    $scopedLinks->incrementUseCount($linkId);

    // Check max_uses
    $link = $scopedLinks->findScoped($linkId);
    if ($link) {
        ...
        $scopedLinks->updateScoped($linkId, ['status' => 'inactive']);
    }
}
```

Reason: `forTenant()` returns a scoped clone; the original singleton stays unscoped. Discarding the clone meant `findScoped()` hit `requireTenant()` and threw a `LogicException` (swallowed by `EventManager::doAction`), so the `max_uses` auto-deactivation never ran — payment links remained usable beyond their configured use limit. Capturing the clone restores enforcement.

---

### FIX-004 — [FIND-012] — Re-validate intent amount after plugin amount filter
File: `src/Service/Payment/PaymentService.php:66–73`
Status: APPLIED

Before:
```php
$data['amount'] = $amount;

if ($data['metadata'] ?? null) {
```

After:
```php
$data['amount'] = $amount;

// Defense-in-depth: the amount must remain strictly positive after the
// payment.amount.calculate filter so a misbehaving plugin cannot zero or
// negate the stored intent amount that later drives the gateway charge.
$amountStr = is_scalar($data['amount']) ? (string) $data['amount'] : '';
if ($amountStr === '' || !is_numeric($amountStr) || bccomp($amountStr, '0', 2) <= 0) {
    throw new \InvalidArgumentException('Payment intent amount must be a positive number.');
}

if ($data['metadata'] ?? null) {
```

Reason: API input is validated `> 0`, but the `payment.amount.calculate` plugin filter could transform the amount to zero/negative/non-numeric before persistence. Since the stored intent amount later drives the gateway charge, this guard ensures it stays strictly positive (defense-in-depth).

---

## Regression verification (post-fix)
- `php -l` on all 9 modified files: 0 syntax errors.
- PHPStan level 9: see report (must remain 0).
- PHPUnit: see report (must remain 0 failures).

---

### FIX-005 — [FIND-004] — Mandatory provider-verified amount match on completion
File: `src/Service/Payment/GatewayApiService.php:170,197–258`; `src/Gateway/WebhookInboundProcessor.php:313–349`; `src/Controller/Webhook/UnifiedWebhookController.php:140`
Status: APPLIED

Summary: A transaction may now complete only when `verify()` returns a numeric amount that matches the stored transaction amount (or `metadata.converted_amount` when auto-conversion occurred). Missing/non-numeric/mismatched amounts fail closed in both completion paths (core `GatewayApiService::handleCallback` and the plugin-hook `WebhookInboundProcessor`). `handleCallback()` gained a `$webhookVerified` flag: the `_op_webhook_verified` marker is stripped from inbound data and re-set only by `UnifiedWebhookController` after the adapter's `verifyWebhook()` signature check passes, so adapters can distinguish a signature-proven webhook from an unauthenticated redirect return. `WebhookInboundProcessor` also now validates the prior status is non-terminal before completing, so a signed event cannot resurrect a failed/cancelled/refunded transaction.

Reason: Previously a callback with no amount (or only `success:true`) could complete a payment, and the plugin-hook path performed no central amount check at all — a forged/replayed confirmation could mark an order paid. Regression test `FinancialLeakageAuditTest::testCallbackWithMismatchedAmountIsRejected` now proves a mismatched-amount callback neither completes the transaction nor posts to the ledger.

---

### FIX-006 — [FIND-001] — Fail-closed webhook verification + unauthenticated-callback gate across payload-trusting adapters
Files: 20 gateway adapters under `modules/gateways/*` (verify() gate); 14 of them also fail-closed `verifyWebhook()`
Status: APPLIED

verify() `_op_webhook_verified` gate inserted (refuses success on unauthenticated redirect/callback params): airtel-money, amazon-pay, gocardless, grabpay, kakaopay, klarna, mercadolibre-wallet, mercadopago, mpesa, now-payments, opay, oxapay, pagseguro, payme, pix, sezzle, square, trustly, wechat-pay, wise.

verifyWebhook() unconditional `return true` → fail-closed `return false`: airtel-money, grabpay, kakaopay, klarna, mercadolibre-wallet, mercadopago, mpesa, pagseguro, payme, pix, square, trustly, wechat-pay, wise.

Left intentionally unchanged: ccavenue (verify() decrypts an AES-128-CBC response with the merchant working key — genuine cryptographic authentication); the 6 adapters with real HMAC `verifyWebhook` (amazon-pay, gocardless, now-payments, opay, oxapay, sezzle) keep their signature check and only receive the verify() gate so their authenticated webhook still completes while unauthenticated redirect returns do not.

Reason: These adapters either accepted any webhook as authentic (`verifyWebhook` returned `true` unconditionally) and/or asserted payment success purely from attacker-controllable callback fields (`trustly`/`opay` even echoed a payload amount, defeating the FIND-004 backstop). The gate mirrors the existing Adyen pattern: only a payload the core proved via `verifyWebhook()` (sets `_op_webhook_verified`) can complete a payment. Gateways without a real signature scheme now fail closed (transaction stays pending) until their provider signature check is implemented — see the Gateway Fleet Risk Assessment checklist.

---

### FIX-007 — [FIND-005] — Pin validated public IP for outbound webhooks (DNS-rebinding defense)
File: `src/Security/UrlValidator.php` (new `resolveSafeWebhookIp()`); `src/Service/Notification/WebhookDispatcher.php:doSend()`
Status: APPLIED

Summary: `doSend()` now resolves the webhook host to a public IP and pins it via `CURLOPT_RESOLVE` (plus explicit `CURLOPT_SSL_VERIFYHOST => 2`), so cURL connects to the exact address that passed SSRF validation instead of re-resolving the hostname at request time. `resolveSafeWebhookIp()` re-verifies every resolved A/AAAA record is public before returning the pinned IP.

Reason: `isValidWebhookUrl()` validated the host at check time, but the subsequent cURL call re-resolved DNS — a malicious/changed resolver could return a public IP for the check and an internal one (e.g. 169.254.169.254 cloud metadata) for the request. Pinning closes that time-of-check/time-of-use window while keeping TLS certificate validation bound to the hostname.
