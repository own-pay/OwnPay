# Findings & Decisions - PHPStan Level 7 Compliance

## Research Findings
We ran a full static analysis check at Level 7 and identified 181 errors across five categories:
1. **BC Math Mismatches (approx. 35 errors)**: operand parameters expect `numeric-string` but are general `string` type-hints.
2. **List vs Array Return Types (approx. 70 errors)**: repositories returning `array` but annotated as returning `list`.
3. **`string|false` and Resource Warnings (approx. 50 errors)**: operations like `strtolower`, file uploads, dynamic encryptions, and curl executions passing potential `false` or non-resource types.
4. **Key-Existence Warnings (approx. 20 errors)**: array offset accesses without guaranteed existence checks.
5. **Dynamic Call Warnings (approx. 6 errors)**: Dynamic method invocations or reflection on dynamic class-strings without checks.

## Technical Decisions
- Update all list return annotations to `array<int, ...>` which aligns exactly with `fetchAll()` returns.
- Add local `/** @var numeric-string $var */` or type assertion checks for financial BC Math logic to keep math calculations correct and robust.
- Add explicit type/fallback guards for built-in PHP functions that return `false`.
