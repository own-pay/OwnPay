# Findings & Decisions

## Requirements
- Achieve 100% type safety and warning-free PHPStan Level 9 analysis of all payment gateways under `modules/gateways/`.
- Ensure zero regressions in the PHPUnit test suite.

## Research Findings
- Accessing mixed keys in untyped arrays like `$credentials['key']` triggers PHPStan Level 9 mixed offset errors.
- Coalescing guaranteed fields in custom array typehints like `$params['trx_id'] ?? null` triggers "Offset always exists and is not nullable" PHPStan errors.
- `curl_init()` returns `CurlHandle|false`, requiring strict boolean guards before usage in `curl_setopt_array()`.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Use `GatewayDefaults` Safe Getters | Leverage `getString`, `getInt`, `getFloat`, and `getArray` to perform safe, PHPStan-friendly casts of credentials and webhook fields. |
| Directly Access Guaranteed `$params` | Access `amount`, `currency`, `trx_id`, `redirect_url`, and `cancel_url` directly from `$params` since their presence is guaranteed by `GatewayAdapterInterface::initiate()`. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Strict Null Coalescing Errors | Access guaranteed non-nullable parameters directly to satisfy PHPStan array-shape requirements. |
