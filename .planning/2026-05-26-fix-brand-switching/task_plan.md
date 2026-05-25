# Task Plan: Fix Brand Switching (CSP & Session Restoration)

## Goal
Fix brand context switching from the admin navbar selector by ensuring compatibility with strict Content Security Policy (CSP) and preventing session loss during redirect.

## Current Phase
Phase 3: Implementation

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent (brand switching fails)
- [x] Identify CSP and session ID regeneration root causes
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define approach (event listener in admin.js + remove session regeneration)
- [x] Update planning files
- **Status:** complete

### Phase 3: Implementation
- [x] Remove `session_regenerate_id(true)` and `session_write_close()` from `BrandController::switchBrand`
- [x] Remove inline `onchange` event handler from `templates/admin/layout/navbar.twig` and add ID `brand-switcher-select`
- [x] Add event listener in `public/assets/js/admin.js` for `#brand-switcher-select`
- [x] Add cache-buster version parameter to `admin.js` inclusion in `templates/admin/layout/base.twig`
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify brand switching in browser (with and without debug mode)
- [x] Run PHPUnit test suite to ensure zero regressions
- [x] Run PHPStan analysis to ensure strict type compliance
- **Status:** complete

### Phase 5: Delivery
- [ ] Deliver details to user
- **Status:** in_progress

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Bind change listener in `admin.js` | CSP-compliant way to submit form without inline scripting blocks. |
| Avoid session ID regeneration | Prevents session deletion and race conditions during 302 redirect. |
| Add cache-buster to `admin.js` | Forces browser to reload updated JavaScript file and apply new listener. |
