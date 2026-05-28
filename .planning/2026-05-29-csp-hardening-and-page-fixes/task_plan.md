# Task Plan: CSP Hardening & Admin Page Fixes

## Goal
Harden all remaining templates in the admin panel by replacing inline style and script event attributes with CSP-compliant solutions (nonced style blocks, CSS classes, and unobtrusive/delegated JS event listeners) and verifying correctness.

## Current Phase
Phase 1: Requirements & Discovery

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent
- [x] Identify constraints
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define approach
- [x] Create implementation plan artifact
- **Status:** complete

### Phase 3: Implementation
- [ ] Refactor form confirmations to use delegated `data-confirm` in `admin.js`
- [ ] Refactor global delete/detail modals to use unobtrusive dynamic event delegation
- [ ] Refactor Developer Hub page (remove style attributes, add page-specific JS listeners)
- [ ] Refactor Paired Devices page (remove style/onclick attributes, add page-specific JS listeners)
- [ ] Refactor Domains page (remove style/onclick attributes, add page-specific JS listeners)
- [ ] Refactor Roles & Permissions page (remove style/onclick attributes, add page-specific JS listeners)
- [ ] Refactor Setup Wizard component (remove style/onclick attributes, add page-specific JS listeners)
- [ ] Clean up other minor inline styles/handlers (My Account, Customers, Activities, etc.)
- **Status:** pending

### Phase 4: Testing & Verification
- [ ] Verify pages render and interact cleanly with zero CSP or JS console errors using the browser tool
- [ ] Run PHPUnit test suite to ensure no regressions
- [ ] Run PHPStan analysis to maintain Level 9 compliance
- **Status:** pending

### Phase 5: Delivery
- [ ] Review outputs
- [ ] Update walkthrough artifact
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use `data-confirm` + delegation | Replaces unsafe `onsubmit="return confirm(...)"` handler cleanly across all forms. |
| Nonced scripts in templates | Colocates page-specific handlers using CSP-compliant nonced script blocks. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| None | |
