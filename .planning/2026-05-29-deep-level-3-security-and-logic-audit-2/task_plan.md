# Task Plan - Level 3 Sandbox Hardening

## Goal
Implement highly advanced, unbreakable security controls in `PluginLoader.php` to prevent sandbox escapes via language construct tokens (`T_EVAL`, `T_INCLUDE`, `T_REQUIRE`) and namespace aliased imports (`use function exec as alias`).

## Current Phase
Phase 5: Attestation & Final Walkthrough - IN_PROGRESS

## Phases

### Phase 1: Requirements & Discovery
- [x] Uncover hidden, extremely deep vulnerabilities (FIND-009 & FIND-010)
- [x] Document vulnerabilities in `findings.md`
- **Status**: complete

### Phase 2: Implementation Planning
- [x] Define exact token scanner enhancements in `PluginLoader.php`
- [x] Write detailed plan to `task_plan.md`
- **Status**: complete

### Phase 3: Sandbox Hardening Implementation
- [x] Harden `PluginLoader.php` to block `T_EVAL`, `T_INCLUDE`, `T_INCLUDE_ONCE`, `T_REQUIRE`, `T_REQUIRE_ONCE`
- [x] Harden `PluginLoader.php` to scan forward inside `T_USE` statement blocks and reject dangerous functions/restricted references
- **Status**: complete

### Phase 4: Integration Testing & Verification
- [x] Write dedicated PHPUnit integration tests in a new test file or inside `tests/Unit/PluginLoaderTest.php` to verify all sandbox bypass patterns are successfully blocked
- [x] Run `vendor/bin/phpunit` to ensure all 446+ tests pass successfully
- [x] Run `vendor/bin/phpstan analyse` to verify strict PHP type-safety
- [x] Run frontend and syntax lints: `npm run lint` and `composer lint:twig`
- **Status**: complete

### Phase 5: Attestation & Final Walkthrough
- [x] Attest the task plan via the PowerShell script
- [x] Document resolved vulnerabilities in `walkthrough.md`
- **Status**: complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| **Reject `T_EVAL`, `T_INCLUDE`, `T_REQUIRE`** | Prevents arbitrary RCE and local file inclusion escapes natively, as plugins do not require these constructs. |
| **Verify `T_USE` imports** | Statically prevents imported function/class aliasing bypasses. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
