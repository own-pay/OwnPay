# Task Plan: Fix All PHPStan L9 Audit Bugs

**Plan ID:** 2026-05-26-fix-all-phpstan-l9-audit-bugs  
**Goal:** Verify and confirm that every bug, security issue, and runtime violation identified in the phpstan_l9_audit_report.md is fully remediated. Ensure zero PHPStan L9 errors, all tests pass, all linters pass.

---

## Phases

### Phase 1: Baseline Verification [complete]

- [x] Run fresh PHPStan L9 analysis on `src/` → **0 errors**
- [x] Run PHPStan L9 on `modules/` → **0 errors**
- [x] Run PHPUnit → **402 tests, 1133 assertions - ALL PASS**
- [x] Run JS lint (ESLint) → **0 errors**
- [x] Run CSS lint (Stylelint) → **0 errors**
- [x] Run JSON lint → **0 errors** (after deleting temp planning files)
- [x] Run Twig lint → **0 errors, 73 templates**

### Phase 2: Deep Audit Verification - Root Causes [complete]

- [x] ROOT-CAUSE-A (Container `mixed`): `config/services.php` - `ensureType()` helpers in place, all service bindings typed
- [x] ROOT-CAUSE-B (`json_decode` returns `mixed`): All gateway modules use `is_array()` guards
- [x] ROOT-CAUSE-C (BrandContext pattern): `resolveMerchant()` private method in all 11 admin controllers
- [x] ROOT-CAUSE-D (Gateway return type violations): AamarpayGateway & BinanceMerchantApiGateway both have explicit casts
- [x] ROOT-CAUSE-E (Request mixed inputs): TransactionController, SettingsController - all `is_string()`/`is_scalar()` guarded
- [x] ROOT-CAUSE-F (Telegram Bot): All string interpolation variables cast, DB calls guarded with `instanceof`
- [x] ROOT-CAUSE-G (SMS Gateway): `sendTwilio()`/`sendVonage()` JSON responses guarded with `is_array()`
- [x] ROOT-CAUSE-H (HMAC key mixed): BinanceMerchantApiGateway - `$apiSecret` cast via `is_scalar()` guard at lines 78 and 153
- [x] ROOT-CAUSE-I (DB port cast): `config/services.php` - `ensureInt($cfg['port'])` in PDO DSN construction
- [x] ROOT-CAUSE-J (Router `get()` on mixed): `config/services.php` - Router registered with explicit singleton closure returning `Router`
- [x] ROOT-CAUSE-K (CronJobRunner mixed): `config/services.php` - cron jobs registered via typed closures

### Phase 3: Cascade Chain Verification [complete]

- [x] Cascade Chain A (Ledger Financial Integrity): `updateStatus()` has `instanceof` guards on both `TransactionService` and `LedgerService` - ledger posting is safe
- [x] Cascade Chain B (HMAC → Fraudulent Webhook): `apiSecret` properly narrowed before `hash_hmac()` call
- [x] Cascade Chain C (BrandContext null → forTenant(0)): `resolveMerchant()` throws if `getActiveBrandId()` returns null
- [x] Cascade Chain D (SmsRegexParser → payment not confirmed): `SmsRegexParser::parse()` properly guards `$matches` access

### Phase 4: Final Full-Stack Verification [complete]

- [x] PHPStan L9 `src/config/cli` → **0 errors**
- [x] PHPStan L9 `modules/` → **0 errors**
- [x] PHPUnit 402 tests → **ALL PASS**
- [x] npm run lint (JS + CSS) → **0 errors**
- [x] npm run lint:json → **0 errors**
- [x] composer lint:twig → **0 errors, 73 templates**

---

## Decisions & Notes

- All previously identified 2,131 L9 errors were remediated in commit `d88f89a`
- The `ensureType/ensureArray/ensureString/ensureInt` helper functions are properly guarded with `function_exists()` checks
- HMAC security fix confirmed: `$apiSecret` is `is_scalar()` narrowed to `string` before `hash_hmac()` - no empty-key vulnerability possible
- Financial integrity confirmed: double-entry journal posting is only skipped if `updatedTxn` is null (transaction not found), not due to service type error
- All 14 gateway modules and 3 addon plugins are PHPStan L9 clean
