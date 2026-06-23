# Task Plan: Fix checkout showing "pending payment" status page before any payment event

## Bug (user report)
On checkout, selecting a gateway and going back WITHOUT completing payment shows a "pending payment"
status page. Expected: it should show the CHECKOUT page (gateway selection). The status page should
only appear once a real payment EVENT has occurred.

## Root cause (systematic-debugging Phase 1 — confirmed in code)
The status-page endpoints render the status view for the transaction/intent's CURRENT status — INCLUDING
the pre-payment states that mean "no payment event yet":
- `CheckoutController::status()` (src/Controller/Checkout/CheckoutController.php:733) → `findAnyByTrxId` →
  `renderStatus($token, $status)` for ANY status. Pre-payment txn states = `'pending'` and `'created'`
  (the two `findActiveForCheckout` treats as still-on-checkout; `pay()` requires `'pending'`).
- `PaymentIntentCheckoutController::status()` (line 816) → renders for the payment-intent status. Intent
  enum = `pending,processing,completed,failed,cancelled,expired`; pre-payment = `'pending'`.
Customers reach `/status` with a pre-payment status via the API-init fallback redirect
(CheckoutController:674 / PaymentIntentCheckoutController:805) or browser history/back. Result: a
misleading "pending payment" page instead of the checkout.
NOTE: callback capture blocks require status `'processing'`, so pre-payment statuses never enter them —
redirecting pre-payment statuses to checkout cannot break gateway callbacks.

## Fix (minimal, root-cause)
In each `status()`, when the resolved status reflects NO payment event, redirect to the checkout page
instead of rendering the status page:
- CheckoutController::status(): if txn exists and status ∈ {pending, created} → `redirect("/checkout/{$token}")`.
- PaymentIntentCheckoutController::status(): if intent exists and status === 'pending' →
  `redirect("/checkout/intent/{$token}")`.
Place the guard after resolving status, before the callback block (callback requires 'processing').

## Test (TDD — failing first)
tests/Integration/CheckoutStatusRedirectTest.php (real DB + container):
- pending txn → CheckoutController::status() returns 302 to /checkout/{trx_id}.
- completed txn → 200 (status page still renders).
- pending intent → PaymentIntentCheckoutController::status() returns 302 to /checkout/intent/{token}.
- completed intent → 200.
Use Response::getStatusCode() + getHeaders()['Location'].

## Verification
PHPStan L9 clean · full PHPUnit green · (status page render for terminal states unaffected).

## Concurrency note
A separate session owns .active_plan (2026-06-22-fix-missing-brand-variable-exception-in-, checkout
BRAND-variable template guard). This fix is controller-side (status routing) — different file; no overlap.
This plan dir was created WITHOUT init-session to avoid stealing .active_plan; attested via PLAN_ID.

## Status: COMPLETE & VERIFIED
- Fix applied to CheckoutController::status() (txn 'pending'/'created' → redirect /checkout/{token}) and
  PaymentIntentCheckoutController::status() (intent 'pending' → redirect /checkout/intent/{token}).
- TDD: tests/Integration/CheckoutStatusRedirectTest.php (4 tests) RED before fix (pending→200), GREEN after.
- Guardrails: PHPStan L9 clean; full PHPUnit 585 pass (581 + 4), 0 failures.
- Scope note: only PRE-payment statuses redirect. 'processing' (external gateway initiated = a payment
  event) intentionally still shows status — re-opening checkout for it could risk a double-charge if the
  gateway later completes; out of the reported scope.
