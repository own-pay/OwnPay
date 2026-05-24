# Task Plan: Integrate Agent Operating Rules

## Goal
Integrate the behavioral and quality-assurance rules from `docs/agent-introduction.md` into `.agents/rules/agent-operating-rules.md` as an always-active rule, registering it in both the rules index and the core manifest (`AGENTS.md`) without conflicts.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Analyze `docs/agent-introduction.md` for overlaps/conflicts with existing rules
- [x] Map required file updates (`architecture-rule.md`, `AGENTS.md`, and the new rule file)
- [x] Document discovery in `findings.md`
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define the exact contents of `.agents/rules/agent-operating-rules.md`
- [x] List specific integration steps
- **Status:** complete

### Phase 3: Implementation
- [x] Create `.agents/rules/agent-operating-rules.md`
- [x] Modify `.agents/rules/architecture-rule.md` to index the new rule file
- [x] Modify `AGENTS.md` to register the new rule file
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify formatting and markdown structure
- [x] Attest planning files and run static checks if applicable
- **Status:** complete

### Phase 5: Delivery
- [x] Present completed work and structural analysis to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Create `agent-operating-rules.md` | Provides a dedicated file for behavioral rules under `.agents/rules/`. |
| Merge and resolve minor terminology overlaps | Keeps the senior architect identity primary while preserving standard engineer principles. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| None | N/A |


|-------|------------|
