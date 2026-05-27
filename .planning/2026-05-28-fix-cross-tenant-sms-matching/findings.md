# findings: Secure Cross-Tenant Transaction Matching

## Discovery
1. **Verification Job (`SmsVerificationJob`):**
   - Falls back to `findPendingMatchGlobal($amount, $gatewaySlug)` if no exact `trx_id` is parsed from the companion device's SMS body.
2. **Global Query (`findPendingMatchGlobal`):**
   - Historically matched ANY transaction in status `'pending'` matching only the exact `amount` and `gateway_slug` across the entire system.
   - **Severe Leakage Risk:** Easily matches an unrelated merchant's customer checkout if multiple payments of the same amount occur around the same time.

## Resolution Strategy
1. **Tight Temporal Boundary:** Enforced that the transaction's `created_at` timestamp must be within a **30-minute interval** relative to the SMS's `received_at` timestamp.
2. **Ambiguity Shield:** Queried the total matching pending transactions in that interval first. If multiple records overlap (i.e. `$count !== 1`), matching is safely aborted to prevent mismatching cross-tenant funds.
3. **Verified pass:** Successfully verified both PHPStan strict Level 9 checks and all 405 PHPUnit tests.
