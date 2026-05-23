# Findings & Decisions

## Requirements
- Conduct a deep-driven complete codebase forensic audit and find/resolve all bugs.

## Research Findings
During the audit, static analysis via PHPStan highlighted 5 key issues in the codebase:
1. **ReconciliationService::reconcile() PHPDoc Mismatch**: The `@return` type declaration did not list `refund_total`, `settlement_total`, `expected_balance` resulting in a PHPStan error in `BalanceVerificationController` when referencing `expected_balance`.
2. **WebhookRetryJob::run() Array End Reference Warning**: The code was calling `end(self::BACKOFF_SECONDS)` on a class constant array. Since `end()` passes arrays by reference, this triggers a PHP compile warning and fails PHPStan.
3. **DevicePairingTokenRepository::createToken() PHPDoc Reference**: The PHPDoc referenced a non-existent parameter `$brandId`.
4. **FeeService::__construct() PHPDoc Reference**: The PHPDoc referenced a non-existent parameter `$db`.
5. **LedgerService::postJournal() Unused Method**: The private helper method `postJournal` was unused in `LedgerService.php`.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Update `ReconciliationService::reconcile` return array definition | Resolves PHPStan type mismatch in the calling controller. |
| Use array indexing instead of `end()` for constants | Avoids passing a constant value by reference. |
| Remove/Correct PHPDoc parameters | Aligns comment blocks with physical method signatures. |
| Delete unused private method `postJournal` | Cleaned up unused legacy code and resolved static analysis warnings. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| PHPStan: Offset 'expected_balance' does not exist on array | Corrected PHPDoc return block to include `expected_balance` in `ReconciliationService.php`. |
| PHPStan: Parameter #1 $array of function end is passed by reference | Replaced `end(self::BACKOFF_SECONDS)` with `self::BACKOFF_SECONDS[count(self::BACKOFF_SECONDS) - 1]`. |
| PHPStan: PHPDoc tag @param references unknown parameter | Corrected comments in `DevicePairingTokenRepository.php` and `FeeService.php`. |
| PHPStan: Method postJournal() is unused | Removed the unused private method from `LedgerService.php`. |
