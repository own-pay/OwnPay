# OwnPay Architecture Instructions

You are working on **OwnPay**, an enterprise-grade payment gateway with a modular backend-first architecture.

## 1) Core Architecture Rules
- Treat OwnPay as a **modern standalone codebase**.
- Preserve strict separation between:
  - `src/` → core framework/services/shared infrastructure
  - `app/modules/gateways` → payment gateway integrations
  - `app/modules/addons` → optional extensions/features
  - `app/modules/themes` → presentation/theme modules
- Do not leak module-specific business logic into core services unless explicitly justified and reviewed.

## 2) Naming and Legacy Prohibition
- **Never introduce legacy `pp-` or `pp_` nomenclature**.
- Forbidden examples:
  - `pp-gateways`, `pp-addons`, `pp-themes`, `pp-modules`
  - `pp_` database table prefix
- Preferred examples:
  - `app/modules/gateways`, `app/modules/addons`, `app/modules/themes`
  - dynamic DB prefix with default `op_`

## 3) Dependency and Integration Boundaries
- Keep module APIs explicit; avoid hidden cross-module coupling.
- Prefer dependency injection and interfaces over static global coupling.
- New dependencies must be added via **Composer** only.
- Do not vendor random source files or add git submodules for PHP libs.

## 4) Change Design Guidelines
- For new features:
  - place code in the correct module first
  - expose only necessary interfaces to core
  - keep extension points predictable and documented
- For refactors:
  - no large “drive-by” rewrites
  - preserve behavior unless explicitly changing requirements
- For performance:
  - optimize only after correctness and security are ensured.

## 5) Out-of-Scope
- Mobile app work (Flutter/Dart) is out-of-scope unless explicitly requested.