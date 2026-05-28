# Task Plan: CSP Hardening & Admin Page Fixes

## Goal
Harden all remaining templates in the admin panel by replacing inline style and script event attributes with CSP-compliant solutions (nonced style blocks, CSS classes, and unobtrusive/delegated JS event listeners) and verifying correctness.

## Current Phase
Phase 5: Delivery

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
- [x] Refactor form confirmations to use delegated `data-confirm` in `admin.js`
- [x] Refactor global delete/detail modals to use unobtrusive dynamic event delegation
- [x] Refactor Settings page (move inline settings template scripts from templates/admin/settings/index.twig to public/assets/js/pages/settings.js and bind using event delegation/listeners)
- [x] Refactor Developer Hub page (remove style attributes, add page-specific JS listeners)
- [x] Refactor Paired Devices page (remove style/onclick attributes, add page-specific JS listeners)
- [x] Refactor Domains page (remove style/onclick attributes, add page-specific JS listeners)
- [x] Refactor Roles & Permissions page (remove style/onclick attributes, add page-specific JS listeners)
- [x] Refactor Setup Wizard component (remove style/onclick attributes, add page-specific JS listeners)
- [x] Clean up other minor inline styles/handlers (My Account, Customers, Activities, etc.)
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify pages render and interact cleanly with zero CSP or JS console errors using the browser tool
- [x] Run PHPUnit test suite to ensure no regressions
- [x] Run PHPStan analysis to maintain Level 9 compliance
- **Status:** complete

### Phase 5: Delivery
- [x] Review outputs
- [x] Update walkthrough artifact
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Use `data-confirm` + delegation | Replaces unsafe `onsubmit="return confirm(...)"` handler cleanly across all forms. |
| Nonced scripts in templates / settings.js | Colocates page-specific handlers using CSP-compliant nonced script blocks. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| None | |
