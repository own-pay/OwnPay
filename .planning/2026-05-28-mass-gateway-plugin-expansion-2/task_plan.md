# Task Plan: Mass Payment Gateway Plugin Expansion & Integration

## Goal
Develop and integrate 5 new production-ready, highly secure payment gateway plugins for the BangladeshLocalized MFS ecosystem: NexusPay (DBBL), CellFin (IBBL), Tap, OK Wallet, and PortWallet.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Research PortWallet, NexusPay, CellFin, Tap, and OK Wallet APIs and specifications
- [x] Understand math (BCMath), signature, and security constraints
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Create directory structures under `modules/gateways/` for the 5 plugins
- [x] Write `manifest.json` for each gateway containing appropriate metadata, namespace, and CSPs
- [x] Create simple, beautiful custom `icon.svg` files for each gateway
- **Status:** complete

### Phase 3: Implementation
- [x] Implement `PortWalletGateway.php` with bearer authentication, invoice creation, and callback validation
- [x] Implement `NexusPayGateway.php` with DBBL specifications and secure verification
- [x] Implement `CellFinGateway.php` with Islami Bank integration details and webhook verification
- [x] Implement `TapGateway.php` with Trust Axiata Pay checkout features
- [x] Implement `OkWalletGateway.php` with ONE Bank MFS structure
- [x] Verify each file begins with `declare(strict_types=1);` and utilizes constructor injection
- [x] Verify high-precision BCMath math is applied to amount subunit conversions
- [x] Harden sandbox simulation validations to prevent live mode bypass (rejection of `SIM_` prefixes in live mode)
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run PHPStan analysis to ensure 100% Level 9 compliance with zero errors
- [x] Run the PHPUnit test suite to ensure no regressions and verify loadability of new plugins
- **Status:** complete

### Phase 5: Delivery
- [x] Document final integration instructions and details in a walkthrough report
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Clean implementation of all 5 plugins | Ensures 100% production-ready capabilities. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| PHPStan amount string does not accept type null | Wrote conditions to prevent null in returned session_id / amount fields |
| PHPStan bcadd expects numeric-string, string given | Refined amount type utilizing `is_numeric` check before calling bcadd |

