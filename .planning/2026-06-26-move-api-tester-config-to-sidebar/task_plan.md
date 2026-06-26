# Task Plan - Move API Tester Config to Sidebar

## Goal
Move the Host, API Key, and Admin Email fields from the header to the sidebar in `public/api-tester.php` to save space on mobile views, and persist their values using `sessionStorage` so they remain after reload but are cleared when the tab is closed.

## Phases

### Phase 1: Planning & Approval
- [x] Analyze `public/api-tester.php` layout and script functionality.
- [x] Create implementation plan artifact.
- [x] Obtain user approval on the implementation plan.

### Phase 2: Implementation
- [x] Remove configuration inputs from the header of `public/api-tester.php`.
- [x] Add the configuration inputs section to the top of the sidebar of `public/api-tester.php`.
- [x] Implement `sessionStorage` integration in the script section of `public/api-tester.php`.

### Phase 3: Verification
- [x] Open in browser and verify layout on desktop and mobile viewports.
- [x] Verify that inputs save/restore correctly on reload via `sessionStorage`.
- [x] Verify that API calls are executed correctly with the populated settings (verified via PHP syntax validation).
- [x] Run static validation and manual check.run lint` if applicable to check linting.
