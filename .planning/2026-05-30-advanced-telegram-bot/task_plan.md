# Task Plan: Advanced Telegram Bot Control Center

## Goal
Transform the Telegram Bot addon into an advanced, flexible, and interactive admin command center featuring premium interactive inline buttons, dynamic query execution, and robust integrations.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Read the existing Telegram Bot plugin code.
- [x] Identify standard commands and database structures.
- [x] Review available core repositories and services.
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Outline the detailed welcome interface, commands, and callback payloads.
- [x] Write implementation_plan.md artifact.
- [x] Lock the planning files hash via attestation script.
- **Status:** complete

### Phase 3: Implementation
- [x] Add new dynamic welcome keyboard grid in `Plugin.php`.
- [x] Add `/customers` command logic fetching customer count and list.
- [x] Add `/disputes` command logic listing open payment disputes.
- [x] Add `/refunds` command logic showing recent refund requests.
- [x] Add `/gateways` command listing manual and automatic gateway states.
- [x] Update `/today` to include currency breakdown and interactive inline buttons.
- [x] Update `/recent` to list recent transactions with interactive details buttons.
- [x] Update `/status` to offer full status checks with dynamic details, customer info, and refund options.
- [x] Implement advanced callback query router capturing `cmd_*`, `txn_details:*`, `txn_cust:*`, and `txn_refund_prompt:*`.
- [x] Hardened PHPStan Level 9 compliance (type checking, nullable bounds, string conversion).
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Create integration test suite `tests/Integration/TelegramBotAddonTest.php`.
- [x] Run test suite using PHPUnit.
- [x] Run static analysis using PHPStan.
- **Status:** complete

### Phase 5: Delivery
- [x] Document final walkthrough.md.
- [x] Deliver complete code.
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Inline Button Actions | Enables quick administration without typing complex commands on mobile devices. |
| Customer PII Decryption | Integrates with CustomerPiiService to safely decrypt customer details, keeping ledger/sensitive data secure. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| saveGroup undefined | Replaced with valid SettingRepository method bulkSet. |
| expires_at column missing | Inserted correct fields for transaction records inside integration test. |
