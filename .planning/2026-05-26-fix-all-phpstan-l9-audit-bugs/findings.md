# Findings & Decisions

## Requirements
- Enforce PHPStan Level 9 strict typing on `src/`, `config/`, `modules/`, and `cli/`.
- Ensure zero errors on PHPStan.
- Ensure all automated unit and integration tests (PHPUnit) continue to pass.
- Ensure Twig, JS, CSS, and JSON linters pass.

## Research Findings
- The PHPStan level 9 upgrade successfully flagged mixed type usage across container dependencies, JSON decodes, and request input methods.
- The codebase uses `ensureType()`, `ensureArray()`, `ensureString()`, and `ensureInt()` to guarantee that returned container values are not treated as mixed.
- All payment gateway response payloads are properly typecast, preventing potential validation bypasses and type mismatches.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Narrowing types on `$c->get()` calls | The PSR-11 container returns `mixed`, which triggers PHPStan Level 9 errors when methods are called. Adding assertion/helper calls ensures type-safety. |
| Casting gateway array keys to string | Gateways return associative arrays that must conform to interface signatures; casting prevents dynamic type mismatch. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| None | All fixes are fully verified and stable. |

## Resources
- [phpstan_l9_audit_report.md](file:///C:/Users/iamna/.gemini/antigravity-cli/brain/f3039796-d5ee-4c5d-abeb-717b152a3899/phpstan_l9_audit_report.md)
- [walkthrough.md](file:///C:/Users/iamna/.gemini/antigravity-cli/brain/f3039796-d5ee-4c5d-abeb-717b152a3899/walkthrough.md)
