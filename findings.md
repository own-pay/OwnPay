# Findings & Decisions: Codebase Comment Refactoring & PHPDoc Standardization

## Requirements
- Rewrite and optimize all comments line-by-line across the entire "Own Pay" codebase.
- Maintain strict PSR-5 compliance for all class, interface, method, and structural closure blocks.
- Remove redundant, conversational comments (e.g., repeating simple conditional logic).
- Document complex financial operations (BCMath, debit/credit ledger, currency exchange rates).
- Document developer-friendly custom domain white-labeling rules (`DomainMiddleware` hooking into route matching).
- No functional logic, variable initializations, or execution sequences should be altered.

## Research Findings
- `src/Container.php`: Uses generic autowiring via reflection. Contains custom handling for recursive alias chains and parameter lookups. Needs standard return and parameter annotations.
- `src/Gateway/GatewayBridge.php`: Resolves gateway adapters dynamically via loaded plugins and decrypts credentials. Integrates with `EventManager` hooks.
- `src/Plugin/PluginLoader.php`: Performs deep security sandbox scans of PHP plugin files via `token_get_all`. Auto-wires gateway adapters.
- `src/Http/Router.php`: Converts route patterns to regular expressions, matches them against current requests, and dispatches to controllers (including plugin controllers FQCNs).
- `src/Middleware/DomainMiddleware.php`: Handles multi-brand custom domain white-labeling. Parses IPv4, IPv6, resolves master domain fallback, and restricts access to administrative routes for white-labeled brand domains.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Standardize all PHPDoc signatures | Fixes lack of `@param`, `@return`, and `@throws` descriptions in key core files. |
| Retain and refine deep explanation comments | Ensure security/fix notes like `AUD-G4`, `AUD-21`, `BUG-1` are not lost but standard and clear. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
