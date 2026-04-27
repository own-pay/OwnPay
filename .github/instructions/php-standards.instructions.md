# OwnPay PHP Standards (8.2+)

## 1) Language Baseline
- Target **PHP 8.2+**.
- Use:
  - `declare(strict_types=1);`
  - typed properties
  - parameter and return types
  - constructor property promotion where suitable
  - enums, `match`, readonly features when they improve clarity/safety

## 2) Code Style and Maintainability
- Prefer small, cohesive classes and functions.
- Keep methods focused and side effects explicit.
- Use clear names; avoid ambiguous abbreviations.
- Replace magic values with constants/enums/value objects where appropriate.

## 3) Errors and Exceptions
- Fail securely and predictably.
- Throw domain-appropriate exceptions; avoid silent failure.
- Avoid catching broad `\Throwable` unless rethrowing or translating with context.
- Include actionable error messages without leaking sensitive internals.

## 4) Data and DB Practices
- Never hardcode DB table names with fixed prefixes.
- Use dynamic prefix strategy (e.g., env/config prefix, default `op_`).
- Use parameterized queries / safe query builders (no SQL injection vectors).
- Keep migrations idempotent and backward-aware where needed.

## 5) JavaScript/CSS Rule
- No inline CSS/JS by default.
- Keep assets in appropriate static files unless a narrowly scoped secure exception is required.

## 6) Testing Mindset
- Add or update tests for behavior changes when test infrastructure exists.
- Prioritize unit/integration coverage around security-sensitive flows.
- Do not claim tests passed unless they were actually run.