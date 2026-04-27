# OwnPay Anti-Patterns (Never Do)

## Forbidden Legacy and Structure Patterns
- Reintroducing any `pp-` / `pp_` legacy naming.
- Placing gateway/addon/theme logic directly in unrelated core files.
- Cross-module “shortcut” imports to private internals.

## Forbidden Security Patterns
- `include/require/unlink/rmdir` from unvalidated paths.
- Outputting untrusted values without escaping.
- Building shell commands with untrusted strings.
- Trusting client-provided authorization state.

## Forbidden Data Patterns
- Hardcoded DB prefixes/table names that bypass dynamic prefix configuration.
- Concatenated SQL with raw user input.
- Logging secrets or sensitive payment details.

## Forbidden Process Patterns
- Large refactors mixed with functional/security changes in one PR.
- Adding dependencies outside Composer workflow.
- Claiming validation/testing without evidence.

## Preferred Replacement Behaviors
- Validate → authorize → execute → encode output.
- Explicit interfaces and dependency injection.
- Small, auditable commits with clear scope.
- Security-first defaults and least privilege.