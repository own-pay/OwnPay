# Findings & Decisions

## Requirements
- No ignored errors or annotation bypasses where possible; use structural type narrowing.
- Enforce strict typing on all parameters and returns.
- Maintain compatibility with PHPUnit tests.

## Research Findings
- **Cron Jobs**: Errors are primarily due to casting `mixed` values from database results to string/int, and passing `mixed` variables to functions expecting strings (e.g. `strtotime`, `is_dir`, `trim`, `preg_match`).
- **Gateways**: `GatewayBridge` and `WebhookInboundProcessor` fetch database or config values which are `mixed` and need strict type-narrowing/validation before passing to arrays and interface methods.
- **Http/Router**: `Request::all()` and `server()` return type declarations are strict, but values are dynamic arrays/mixed. We need to cast server headers, query variables, and uploaded file lists.
- **Middleware**: Config files read array structures which PHPStan sees as `mixed`. We must assert/narrow these configurations.
- **View/Twig**: Twig factories and extensions have functions expecting/returning strings but receiving/returning `mixed` configurations.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Use `is_string()` & `is_int()` | Instead of direct casts when the variable might be `mixed` but is expected to be a scalar type. |
| Add `is_array()` checks | Before iterating or key-accessing nested structures retrieved from configs or JSON. |
| Use `instanceof` | For container/service lookups when type safety of custom classes is required. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| PHPUnit duplicate helper function error `ensureType()` | Moved the helper functions to the top of `config/services.php` (preceding the early `return static function` closure) and wrapped them inside `if (!function_exists(...))` checks so they are only registered once at run-time. |

