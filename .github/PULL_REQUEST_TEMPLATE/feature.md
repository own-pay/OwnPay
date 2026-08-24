# Feature Pull Request

> [!IMPORTANT]
> **Branch Rule**: Pull Requests MUST target the **`dev`** branch.

---

## ✨ Feature Overview

<!-- Describe the new capability, why it is needed, and how it solves user problems. -->

**Related Issue**: Closes #<!-- issue number -->

### 🎯 Target User Persona & Scope
- [ ] Super Administrator (Platform Owner)
- [ ] Brand / Store Staff
- [ ] End Customer / Payer
- [ ] External API Consumer
- [ ] Plugin / Gateway Developer

**Brand Scope**: Platform-wide / Single Brand / Custom Domain / Core Infrastructure

---

## 🛠️ Implementation Details

<!-- Outline key architecture, services, repositories, database tables, or routes introduced. -->

- **New Services / Repositories**:
- **Database Schema Changes** (`database/schema.sql` / migrations):
- **Routes & Middleware**:

---

## 🏛️ Quality & Architectural Checklist

- [ ] **Strict Typing**: `declare(strict_types=1);` present at the top of all PHP files.
- [ ] **Dependency Injection**: Autowired via PSR-11 container in constructors.
- [ ] **Multi-Brand Scoping**: Repository calls properly scoped via `forTenant()` / `getWriteMerchantId()`.
- [ ] **Monetary Precision**: All financial math uses `bcmath` string operations.
- [ ] **Security**: Output escaped in Twig, CSRF protection enabled on all POST forms.
- [ ] **Testing**: Unit/integration tests added covering happy and unhappy paths.
- [ ] **Static Analysis**: `composer analyse` (PHPStan Level 9) passes with 0 errors.
- [ ] **Linters**: `composer lint` passes.

---

## 📸 Screenshots / Demos (If Applicable)

| Before | After |
| :--- | :--- |
| *(image or N/A)* | *(image or N/A)* |

---

## ⚖️ DCO & License
- [ ] My commits include a **DCO sign-off** (`git commit -s`).
- [ ] I agree to license this work under the **GNU AGPL-3.0 License**.
