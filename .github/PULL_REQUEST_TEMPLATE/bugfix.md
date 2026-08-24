# Bug Fix Pull Request

> [!IMPORTANT]
> **Branch Rule**: Pull Requests MUST target the **`dev`** branch.

---

## 🐛 Bug Fix Summary

<!-- What was broken, and how does this pull request resolve it? -->

**Related Issue**: Fixes #<!-- issue number -->

### 🔍 Root Cause Analysis
<!-- Why did this bug occur in the first place? -->

### 🩹 Fix Explanation
<!-- How does your solution fix the issue without introducing side effects or regressions? -->

---

## 🧪 Verification & Regression Prevention

<!-- Detail the automated or manual tests used to verify this fix. -->

- [ ] Added a regression test in `tests/` reproducing the bug before the fix and passing after.
- [ ] Ran `composer test` (PHPUnit suite passes completely).
- [ ] Ran `composer analyse` (PHPStan Level 9 passes with 0 errors).
- [ ] Ran `composer lint` (All templates and scripts valid).

### Manual Reproduction Steps Tested:
1. 
2. 
3. 

---

## ⚖️ DCO & License
- [ ] My commits include a **DCO sign-off** (`git commit -s`).
- [ ] I agree to license this work under the **GNU AGPL-3.0 License**.
