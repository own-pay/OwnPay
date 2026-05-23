# Findings: PHPStan Level 8 Errors

We ran PHPStan at Level 8 on the entire repository (including `src`, `cli`, `config`, `modules`) and found exactly 5 errors:

1. **`cli\build-update.php` line 47**: `preg_replace` expects `string|array`, but gets `string|null`.
2. **`cli\build-update.php` line 48**: `trim` expects `string`, but gets `string|null`.
3. **`cli\create-module.php` line 55**: `preg_replace` expects `string|array`, but gets `string|null`.
4. **`cli\create-module.php` line 56**: `trim` expects `string`, but gets `string|null`.
5. **`cli\create-module.php` line 172**: `strtolower` expects `string`, but gets `string|null`.

### Root Cause
`preg_replace()` returns `string|array|null`. In these utility scripts, `preg_replace` is chained or its output is passed immediately to another string function (`preg_replace`, `trim`, `strtolower`). PHPStan flags this because if `preg_replace` fails and returns `null`, passing it to these functions will cause type errors.

### Solution
Explicitly cast the return value of `preg_replace()` to `(string)` in these specific locations.
