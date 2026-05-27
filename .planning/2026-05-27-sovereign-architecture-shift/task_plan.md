# Task Plan: Sovereign Architecture Shift

Optimize OwnPay for a Sovereign Single-Owner Model (not SaaS) by implementing inheritance-based configuration fallback, a global shared Android device pool, and safe schema adaptations.

## Goal
Implement global default gateway configuration inheritance and centralized dynamic Android SMS routing fallback, ensuring complete backwards compatibility and 100% passing tests.

## Current Phase
Completed

## Phases

### Phase 1: Safe Database Schema Migration
- [x] Audit database columns and constraint locations in `database/schema.sql`.
- [x] Modify `merchant_id` to be nullable in `op_gateway_configs` (line 145).
- [x] Modify `merchant_id` to be nullable in `op_device_pairing_tokens` (line 602).
- [x] Modify `merchant_id` to be nullable in `op_paired_devices` (line 617).
- [x] Modify `merchant_id` to be nullable in `op_sms_parsed` (line 670).
- **Status:** complete

### Phase 2: Gateway Configuration Inheritance
- [x] Update `findCredentialsBySlug` in `src/Repository/GatewayConfigRepository.php` to fallback to `merchant_id IS NULL` when empty.
- [x] Update `listActive`, `findForGateway`, and `listActiveForCheckout` in `src/Repository/GatewayConfigRepository.php` to inherit global defaults.
- [x] Update `decryptCredentials` in `src/Gateway/GatewayBridge.php` to leverage inheritance fallback.
- **Status:** complete

### Phase 3: Centralized Shared Android Device Pool
- [x] Add `findPendingMatchGlobal` to `src/Repository/TransactionRepository.php`.
- [x] Allow `findByTrxId` in `src/Repository/TransactionRepository.php` to run globally if no tenant is set.
- [x] Refactor `SmsVerificationJob::run()` in `src/Cron/SmsVerificationJob.php` to perform dynamic cross-tenant routing (updating `merchant_id` on the parsed SMS dynamically when a global transaction matches).
- **Status:** complete

### Phase 4: Static Verification & Testing
- [x] Run `vendor/bin/phpunit` to ensure 100% of integration and ledger tests are green.
- [x] Run `vendor/bin/phpstan analyse` to verify strict typing compliance at level 9.
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Nullable `merchant_id` in configs/devices | Enables global defaults / shared pools while retaining the columns for brand overrides and backwards compatibility. |
| Dynamic context updates in SMS verification | Automatically matches a globally received SMS to whichever brand context owns the matching transaction, eliminating the need for brand-by-brand device pairing. |
| Safe nested database transactions | Modified `Database::transaction` in `src/Core/Database.php` to check `inTransaction()` and avoid duplicate transaction openings, eliminating PDO crashes on nested queries. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| Column 'merchant_id' cannot be null | Altered development and testing databases (`ownpay`, `ownpay_test`) to make the column nullable. |
| Redundant delete on `op_ledger_entries` | Removed the delete statement since `ON DELETE CASCADE` handles child entry deletions automatically. |
| Nested database transactions | Implemented safe transaction nesting in `src/Core/Database.php` using `inTransaction()`. |
