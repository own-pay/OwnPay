# Progress Log

## Session: 2026-05-23

### Current Status
- **Phase:** 4 - Testing & Verification
- **Started:** 2026-05-23

### Actions Taken
- Implemented merchant status checks in `PaymentIntentCheckoutController::show`, `pay`, and `expressPay`.
- Removed redundant `(float)` casts for item `unit_price` and `total` inside `InvoiceService::create` and `InvoiceService::update` to preserve absolute string-based decimal precision for database binding.
- Initialized running tests to verify changes.

### Test Results
| Test | Expected | Actual | Status |
|------|----------|--------|--------|

### Errors
| Error | Resolution |
|-------|------------|
