# Pull Request

> [!IMPORTANT]
> **Branch Rule**: Pull Requests MUST target the **`dev`** branch. PRs targeting `main` directly are automatically rejected.

---

## 📝 Summary of Changes

<!-- Provide a clear, concise overview of what this pull request changes or introduces. -->

**Related Issue / Discussion**: Fixes #<!-- issue number --> (or Resolves #)

---

## 🏷️ Type of Change

<!-- Please select all options that apply: -->

- [ ] 🐛 **Bug fix** (non-breaking change fixing an issue)
- [ ] ✨ **New feature** (non-breaking change adding functionality)
- [ ] 🔌 **Payment Gateway / Adapter** (new or updated gateway plugin)
- [ ] 🔒 **Security fix or hardening** (patching a vulnerability or tightening controls)
- [ ] ⚡ **Performance improvement** (optimizing queries, caching, asset payloads)
- [ ] 🎨 **UI / UX improvement** (Twig styling, responsive design, accessibility)
- [ ] 📖 **Documentation / Translation** (updating guides, API specs, language packs)
- [ ] 🧹 **Refactoring / Maintenance** (code cleanup, typing, dependency upgrades)
- [ ] 💥 **Breaking change** (fix or feature requiring migration or breaking existing contracts)

---

## 🏛️ Architectural & Security Checklist

<!-- Please confirm all core architectural invariants are respected: -->

- [ ] **Target Branch**: My PR targets the `dev` branch.
- [ ] **Strict Typing**: All new or modified PHP files include `declare(strict_types=1);` as the first statement.
- [ ] **Dependency Injection**: Dependencies are resolved via PSR-11 constructor autowiring (`src/Container/`).
- [ ] **Multi-Brand Scoping**:
  - Scoped database queries use `$repo->forTenant($merchantId)`.
  - Scoped writes use `BrandContext::getWriteMerchantId()`.
- [ ] **Database Standards**:
  - All tables use the `op_` prefix.
  - Column conventions followed (`two_factor_enabled`, `decimal_places`, `base_currency`, etc.).
  - Hot query filters use generated stored columns where applicable.
- [ ] **Double-Entry Ledger Integrity**:
  - All financial events post via `LedgerService::postEntries()`.
  - Financial math uses BCMath (`bcadd`, `bcsub`, `bcmul`, `bcdiv`, `bccomp`) with string types, never floats.
- [ ] **Security & White-Label**:
  - Dynamic URLs use `DomainUrlService` (no hardcoded hostnames).
  - Admin routes are shielded under master `APP_DOMAIN`.
  - Forms include valid CSRF tokens via `SecurityHelpers::csrfToken()`.
  - All SQL queries use prepared statements with parameter binding (no raw string interpolation).
  - Twig templates rely on auto-escaping (no untrusted `|raw` filters).
  - Gateway plugins adhere to the AST `PluginSandbox` security constraints.

---

## 🧪 Testing & Verification

<!-- How did you verify these changes? -->

### Automated Quality Checks
- [ ] `composer test` (PHPUnit unit and integration tests pass)
- [ ] `composer analyse` (PHPStan passes at level 9 with 0 errors)
- [ ] `composer lint` (Twig, ESLint, and Stylelint pass with 0 errors)

### Manual Verification Details
- **Environment**: PHP 8.3 / MySQL 8.0 / Nginx (or Laragon / Docker)
- **Steps executed**:
  1. 
  2. 
  3. 

---

## 🎨 UI / Visual Changes (If Applicable)

<!-- If this PR changes any frontend templates or UI, attach Before & After screenshots or recordings. -->

| Before | After |
| :--- | :--- |
| *(image or N/A)* | *(image or N/A)* |

---

## 📜 Contributor License Agreement & DCO

- [ ] I agree that my contributions will be licensed under the **GNU AGPL-3.0 License**.
- [ ] I certify that my commits contain a **Developer Certificate of Origin (DCO)** sign-off (`git commit -s`).