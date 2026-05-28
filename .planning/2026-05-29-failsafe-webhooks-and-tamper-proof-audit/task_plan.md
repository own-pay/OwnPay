# Task Plan: Failsafe Webhooks & Tamper-Proof Audit Trail

## Goal
Implement end-to-end reliability for Outbound Webhooks (Features 3) and Cryptographic Signatures for Audit Trails (Feature 6) with Level 9 PHPStan compliance and 100% green tests.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent (only implement 3 and 6)
- [x] Identify constraints (strict typing, double-entry ledgers, brand context)
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define approach (unified deliver() in WebhookService, HMAC signature chaining)
- [x] Create project structure (Controllers, Views, Cron Task, Repositories, Tests)
- **Status:** complete

### Phase 3: Implementation
- [x] Execute the plan
- [x] Create WebhookEventController
- [x] Create WebhookRetryCron background worker
- [x] Add signature verification and backporting to AuditLogRepository
- [x] Register routes, navigation menu items in sidebar, and dependency injection container
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify requirements met
- [x] Implement AuditIntegrityTest
- [x] Implement WebhookRetryTest
- [x] Verify 100% green unit tests (437/437 passing)
- [x] Document test results in progress.md
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs
- [x] Deliver to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Real Repos + Mock DB | Circumented final class mocking restrictions (`ClassIsFinalException`) while maintaining completely isolated, database-agnostic in-memory unit tests. |
| Port 9999 testing | Permitted simulating network timeout/connection-refused scenarios without triggering local IP SSRF blocks. |
| Fillable Signature | Added 'signature' to `AuditLogRepository`'s `$fillable` property to allow automatic batch creation values through `$this->create()`. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| ClassIsFinalException | Instantiated repositories directly and mocked the underlying `Database` calls. |
| IncompatibleReturnValueException | Set mocked `Database::update()` to return `1` and `Database::execute()` to return `\PDOStatement` mock. |
| SSRF localhost block | Used a public but unresolvable or high-port unconnectable URL format for testing. |
