# Task Plan: PHPStan Level 9 Hardening

## Goal
Resolve all PHPStan Level 9 static analysis errors across `cli`, `config`, `modules`, and `src` directories to ensure complete type safety, security, and stability.

## Current Phase
Phase 3: Implementation for Src (Core Source)

## Phases

### Phase 1: Requirements & Discovery
- [x] Run PHPStan on `cli` and `config` (verified: 0 errors)
- [x] Run PHPStan on `modules` (verified: 83 errors originally)
- [x] Identify key files in `modules` and errors in `src`
- **Status:** complete

### Phase 2: Implementation for Modules (Addons & Gateways)
- [x] Resolve type mismatches/mixed usages in `addons/` (0 errors)
- [x] Resolve type mismatches/mixed usages in `gateways/` (0 errors)
- **Status:** complete

### Phase 3: Implementation for Src (Core Source)
- [ ] Run PHPStan on `src`
- [ ] Resolve all type mismatches/mixed usages in `src/Repository/` (53 errors)
- [ ] Resolve all type mismatches/mixed usages in `src/Service/` (210 errors)
- [ ] Resolve remaining errors in other `src/` folders
- **Status:** in_progress

### Phase 4: Testing & Verification
- [ ] Run full PHPStan analysis globally
- [ ] Run PHPUnit tests to ensure zero functional regressions
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Scope incrementally | Focus on `modules/` first, then tackle `src/` to make issues manageable. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
