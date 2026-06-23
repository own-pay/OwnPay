> **STATUS UPDATE (2026-06-11): IMPLEMENTED.** This specification was implemented in remediation pass 2. See `docs/v2/audit/fixes_applied.md` (FIX-005/007/008) and the updated finding status in `report_claude_fable_5.md`. Retained for historical traceability.

# FIND-004 — Central callback amount verification is skipped when an adapter returns no amount

Severity: MEDIUM (enables the CRITICAL FIND-001 forge to bypass the amount check)
Status: SPEC_WRITTEN (ripple across the whole adapter fleet — not a safe single-file patch)

## Problem (technical)
`GatewayApiService::handleCallback()` (`src/Service/Payment/GatewayApiService.php:213–227`) only compares the gateway-reported amount to the stored transaction amount **when the adapter's `verify()` returned a non-null `amount`**:

```php
$verifiedAmountVal = $verification['amount'] ?? null;
if ($verifiedAmountVal !== null && is_scalar($verifiedAmountVal) && is_scalar($txAmtVal)) {
    if (bccomp((string)$verifiedAmountVal, (string)$txAmtVal, 2) !== 0) {
        $amountMismatch = true; $transaction = null; return;
    }
}
```

If an adapter's `verify()` returns `success => true` but omits `amount` (e.g. `mpesa` — `success => $checkoutRequestId !== ''`), the amount check is skipped entirely. Combined with a stub `verifyWebhook()` (FIND-001), a forged callback that references a pending transaction can complete it with **no amount validation at all**.

## Recommended solution
Make the core treat "successful verification without a verifiable, matching amount" as a failure. Replace the conditional check with a mandatory one:

```php
// Inside the db->transaction closure, where $transaction !== null:
$verifiedAmountVal = $verification['amount'] ?? null;
$txAmtVal = $transaction['amount'] ?? null;

// A completion MUST carry a server-verifiable amount that matches the stored order.
if (!is_scalar($verifiedAmountVal) || !is_scalar($txAmtVal)
    || bccomp((string)$verifiedAmountVal, (string)$txAmtVal, 2) !== 0) {
    $amountMismatch = true;
    $transaction = null;
    return;
}
```

## Files to change
- `src/Service/Payment/GatewayApiService.php` — `handleCallback()` amount-check block (~L213–227).

## Why a single-file patch is unsafe here
This changes the contract for **all ~123 adapters**: every adapter's `verify()` must now return a correct `amount`. Adapters whose `verify()` performs a genuine server-side confirmation but does not echo the amount (legitimately secure, but amount-less) would begin failing closed. Shipping this requires first auditing every adapter's `verify()` return shape and adding `amount` where missing — otherwise good gateways break. That fleet-wide pre-work makes it a coordinated change, not an isolated fix.

## Pre-implementation checklist (per adapter)
1. Confirm `verify()` returns `amount` as a decimal string equal to the gateway-confirmed paid amount.
2. For adapters that cannot return an amount, implement a server-side status+amount query (do not trust the callback body).
3. Add a regression test posting a callback with a mismatched amount → expect rejection.

## Verification after implementation
- Unit test: `handleCallback` with `verify()` returning no amount → result `success:false`, `error:'Transaction amount mismatch'`.
- Unit test: matching amount → completes; mismatching amount → rejected.
- Full `phpunit` + `phpstan analyse` remain green.
