# OwnPay Copilot Instructions (Entrypoint)

This repository uses **modular Copilot instruction files** in `.github/instructions/`.

## Scope
- Apply these instructions to all code generation, edits, refactors, bug fixes, tests, docs, and PR reviews.
- If instructions conflict, use this precedence:
  1. `security.instructions.md`
  2. `architecture.instructions.md`
  3. `php-standards.instructions.md`
  4. `module-conventions.instructions.md`
  5. `pr-review.instructions.md`
  6. `anti-patterns.instructions.md`

## Mandatory Reading Order
1. `.github/instructions/security.instructions.md`
2. `.github/instructions/architecture.instructions.md`
3. `.github/instructions/php-standards.instructions.md`
4. `.github/instructions/module-conventions.instructions.md`
5. `.github/instructions/pr-review.instructions.md`
6. `.github/instructions/anti-patterns.instructions.md`

## Baseline Behavior
- Prioritize **security and correctness** over speed.
- Keep changes **minimal, scoped, and reversible**.
- Maintain strict compatibility with repo architecture and modular boundaries.
- Do not introduce legacy naming conventions or paths.