# Task Plan: Create Strict Agent Rules
---
Plan-SHA256: 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
---

## Goal
Create multiple dedicated rule files inside `.agents/rules/` following the `trigger: always_on` format, based on `AGENTS.md`, `ARCHITECTURE.md`, and the codebase context, to ensure future AI agents adhere to OwnPay's core architecture.

## Current Phase
Phase 5: Complete

## Phases

### Phase 1: Requirements & Discovery
- [x] Read `AGENTS.md` and `ARCHITECTURE.md` to identify structural and logical constraints
- [x] Inspect existing rules folder `.agents/rules/` and file formats
- [x] Document key discoveries in `findings.md`
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Outline the specific categories of rules to isolate into dedicated files
- [x] Define file names and structures matching the frontmatter format
- [x] Document planned approach in `findings.md`
- **Status:** complete

### Phase 3: Implementation
- [x] Write `business-model-scoping.md` to `.agents/rules/`
- [x] Write `database-schema.md` to `.agents/rules/`
- [x] Write `white-label-domains.md` to `.agents/rules/`
- [x] Write `double-entry-ledger.md` to `.agents/rules/`
- [x] Write `security-cryptography.md` to `.agents/rules/`
- [x] Write `code-standards-architecture.md` to `.agents/rules/`
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify that all files have been created and have correct YAML frontmatter
- [x] Run PHPUnit tests to verify no regressions in the system
- **Status:** complete

### Phase 5: Delivery
- [x] Review all output rules files
- [x] Deliver a summary of accomplishments to the user
- **Status:** complete

## Key Questions
1. How should each rules file be structured? (It must start with the YAML frontmatter containing `trigger: always_on` and have a clear markdown description).
2. What are the key categories of rules we must write? (Business model & scoping, Database schema & constraints, White-label domain pipeline, Double-entry ledger engine, Security & crypto guardrails, and Code standards & DI container architecture).

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Create 6 distinct rules files | Dedicated rules should be separated by functional boundaries to make them modular and clear. |
| Retain and update `architecture-rule.md` or replace it | Make sure it contains general architectural requirements or replace it if redundant. |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
|       | 1       |            |

## Notes
- Update phase status as you progress: pending → in_progress → complete
- Re-read this plan before major decisions
- Log ALL errors - they help avoid repetition
