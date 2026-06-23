# Findings & Decisions

## Requirements
- Fix the Twig error: `Variable "brand" does not exist in "checkout/layout.twig" at line 22.`
- Constraint: Update ONLY the frontend (Twig layout template), do NOT modify the backend (PHP controllers).

## Research Findings
- The exception is raised because `checkout/layout.twig` checks `{% if brand is not null and brand.show_powered_by is defined %}` without checking if `brand` is defined first. Because Twig's `strict_variables` is set to `true` in `TwigFactory.php` line 97, this causes a fatal Twig runtime exception if `brand` is not defined in the template context.
- By guarding `brand` with `brand is defined` and falling back safely when it's not defined, we can solve the error entirely on the frontend without changing any PHP controllers.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Update `checkout/layout.twig` to guard with `brand is defined` | Twig strict variables mode will crash if a variable is used before validating its existence with `is defined`. This handles cases where `brand` is missing entirely from the context. |
| Do not modify backend controllers | Per user constraint to only update frontend. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [layout.twig](file:///c:/laragon/www/ownpay/templates/checkout/layout.twig)
