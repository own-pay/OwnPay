# Findings — Comprehensive Deep Audit Cross-Check

All 9 reported bugs and 3 gaps verified against live codebase.
All fixes confirmed in-place by a PRIOR session.
This session performed independent verification + applied missing DB migration.

| Ref | Bug Name | Severity | Code Fix | DB Fix | Status |
|---|---|---|---|---|---|
| **BUG 01** | Duplicate Ledger Account Creation | CRITICAL | ✅ `findOrCreateAccount` queries by name+currency only (no type filter) | N/A | ✅ VERIFIED |
| **BUG 02** | Ledger Balance Accumulation | HIGH | ✅ `adjustBalance` takes `$entryType` param, applies double-entry rules | N/A | ✅ VERIFIED |
| **BUG 03** | Transaction Metadata Overwrite | HIGH | ✅ `updateMetadata` merges via `array_merge($existing, $metadata)` | N/A | ✅ VERIFIED |
| **BUG 04** | Multi-Refund Overdraw | HIGH | ✅ `getTotalRefundedAmount()` + BCMath `bccomp` + aggregate check | N/A | ✅ VERIFIED |
| **BUG 05** | Inactive Plugin Sandbox | HIGH | ✅ `Database::execute()` calls `getActiveOwner()` → `getSandbox()` → `validateSql()` | N/A | ✅ VERIFIED |
| **BUG 06** | JWT Issuer Mismatch | HIGH | ✅ `JwtService` constructor uses `getenv('APP_NAME') ?: 'OwnPay'` matching middleware | N/A | ✅ VERIFIED |
| **BUG 07** | Privilege Escalation (RBAC) | MEDIUM | ✅ Unmapped `/admin` routes default to `'system.unmapped'` (not `'admin.access'`) | N/A | ✅ VERIFIED |
| **BUG 08** | Scanner OOP False Positive | MEDIUM | ✅ Skips tokens preceded by `T_OBJECT_OPERATOR` / `T_DOUBLE_COLON` | N/A | ✅ VERIFIED |
| **BUG 09** | Installer Twig Templates | LOW | ✅ Renamed to `.php`, method renamed to `renderPhpTemplate` | N/A | ✅ VERIFIED |
| **GAP I** | JSON Extract Full Scan | HIGH | ✅ schema.sql has STORED generated columns | ✅ Applied via ALTER TABLE | ✅ VERIFIED |
| **GAP II** | Missing Auth Hooks | MEDIUM | ✅ `auth.login.success` and `auth.login.failed` hooks fired in Authenticator | N/A | ✅ VERIFIED |
| **GAP III** | Overdue Invoices Locked | LOW | ✅ No `$invoice = null` after due date check; overdue stays payable | N/A | ✅ VERIFIED |

## DI Wiring Verification
- GatewayApiService: 5 params in constructor = 5 params in services.php ✅
- RefundService: 4 params (incl. LedgerService) = 4 params in services.php ✅
- Authenticator: autowired with nullable EventManager, injected via container reflection ✅
- JwtService: DI passes `$secret` and `$iss` from env ✅

## DB Migration Applied
- `ALTER TABLE op_transactions ADD COLUMN invoice_id` (generated STORED)
- `ALTER TABLE op_transactions ADD COLUMN payment_link_id` (generated STORED)
- `ADD KEY idx_invoice_id`, `ADD KEY idx_payment_link_id`
- All verified: ✅ invoice_id EXISTS, ✅ payment_link_id EXISTS, Indexes: 2
