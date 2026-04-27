# OwnPay Pull Request Review Instructions

When reviewing PRs, enforce the following checks first.

## 1) Immediate Blockers
- Any use of legacy `pp-` directories, names, or `pp_` table prefixes.
- Dynamic file includes/deletes without `realpath()` + boundary checks.
- Raw unescaped output of untrusted data in HTML contexts.
- Command execution patterns with potential injection risk.
- New third-party dependency not managed via Composer.

## 2) Security Checklist
- Inputs validated at trust boundaries.
- Auth checks present on privileged operations.
- Sensitive data not logged or exposed.
- Dangerous defaults avoided; deny-by-default where possible.

## 3) Architecture Checklist
- Code placed in proper module/core location.
- No module-to-core leakage of feature-specific logic.
- Contracts/interfaces are explicit and stable.
- No unnecessary cross-module coupling.

## 4) Quality Checklist
- PHP 8.2+ typing and strictness maintained.
- Error handling is explicit and safe.
- Diff is focused and avoids unrelated churn.
- Tests or verification notes cover changed behavior.

## 5) Reviewer Output Format (Recommended)
- **Severity:** blocker / high / medium / low
- **Location:** file + function/section
- **Issue:** concise description
- **Why it matters:** risk/impact
- **Fix suggestion:** concrete remediation