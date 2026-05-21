# Task Plan: Codebase Comment Refactoring & PHPDoc Standardization

## Goal
Conduct a line-by-line rewrite and optimization of code comments across the entire OwnPay codebase to meet enterprise-grade, PSR-5 compliant PHPDoc standards, removing redundant comments and documenting complex logic, without altering functional application logic.

## Current Phase
Phase 2: Payment & Checkout Controllers

## Phases

### Phase 1: Direct Core Layer
- [x] Refactor [Container.php](file:///c:/laragon/www/ownpay/src/Container.php)
- [x] Refactor [GatewayBridge.php](file:///c:/laragon/www/ownpay/src/Gateway/GatewayBridge.php)
- [x] Refactor [PluginLoader.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginLoader.php)
- [x] Refactor [Router.php](file:///c:/laragon/www/ownpay/src/Http/Router.php)
- [x] Refactor [DomainMiddleware.php](file:///c:/laragon/www/ownpay/src/Middleware/DomainMiddleware.php)
- [x] Perform verification (PHP syntax check, run unit tests, phpstan checks)
- **Status:** complete

### Phase 2: Payment & Checkout Controllers
- [ ] Refactor checkout and API controllers comments
- [ ] Verify syntax and tests
- **Status:** pending

### Phase 3: Financial & Gateway Modules
- [ ] Refactor LedgerService, CurrencyService, and Gateway adapters comments
- [ ] Verify syntax and tests
- **Status:** pending

### Phase 4: Database Models & Schema Interfaces
- [ ] Refactor Repositories and TenantScope trait comments
- [ ] Verify syntax and tests
- **Status:** pending

## Key Questions
1. Do any of the files in Phase 1 contain tricky constructs that require careful handling (e.g. PHP tokens parsing)?
   - Yes, `PluginLoader.php` uses `token_get_all` to scan for dangerous function calls. We should document this process carefully.

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Comment-only refactor | Ensure zero logical changes to prevent regression or system crashes |
| PSR-5 PHPDoc standard | Ensure consistent IDE auto-completion and static analysis compatibility |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
