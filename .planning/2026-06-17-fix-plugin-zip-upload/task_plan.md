# Task Plan: Fix Plugin Zip Upload Bug

## Goal
Fix the plugin ZIP upload issue by correcting the manifest search filename from `plugin.json` to `manifest.json`, and write integration tests to prevent regressions.

## Current Phase
Phase 2: Planning & Structure

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent
- [x] Identify constraints
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define approach (change `plugin.json` references to `manifest.json` in `PluginInstaller.php`)
- [x] Plan integration testing strategy for zip extraction/upload
- **Status:** complete

### Phase 3: Implementation
- [x] Implement the fixes in [PluginInstaller.php](file:///c:/laragon/www/ownpay/src/Plugin/PluginInstaller.php)
- [x] Create mock zip files (both root manifest and nested folder layouts) for testing
- [x] Implement new integration test case for zip installation in [PluginInstallerTest.php](file:///c:/laragon/www/ownpay/tests/Plugin/PluginInstallerTest.php)
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Run PHPUnit tests and ensure 100% success
- [x] Run static analysis via `phpstan`
- [x] Run asset quality/twig lints
- **Status:** complete

### Phase 5: Delivery
- [x] Create walkthrough.md with verification details
- [x] Deliver the changes to the user
- **Status:** complete

### Phase 6: Requirements & Discovery (Delete & Update Confirmations)
- [x] Define requirement: custom confirmation dialogs for trash/uninstall
- [x] Define requirement: version checking on re-upload and display alert if already exists
- [x] Define requirement: prevent DB rollback/uninstall on plugin update
- **Status:** complete

### Phase 7: Planning & Structure
- [ ] Map route changes in `config/routes/web.php`
- [ ] Plan controller changes in `PluginController.php`
- [ ] Plan view changes in `index.twig` (modal confirmation) and `confirm_update.twig`
- **Status:** pending

### Phase 8: Implementation
- [ ] Implement deletion confirmation modal inside `index.twig` / `modals.twig`
- [ ] Implement version checking and update logic in `PluginManager.php` and `PluginInstaller.php`
- [ ] Implement persistent temp file storage and confirmation rendering in `PluginController.php`
- [ ] Create `confirm_update.twig` template
- **Status:** pending

### Phase 9: Testing & Verification
- [ ] Run PHPUnit tests and add new test cases for the update flow
- [ ] Verify delete confirmation modal works visually via dev tools or tests
- [ ] Run PHPStan checks
- **Status:** pending

### Phase 10: Final Delivery
- [ ] Update walkthrough.md and progress.md
- [ ] Deliver finished features to the user
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Standardize on `manifest.json` | The entire codebase, including existing gateways and manifests, uses `manifest.json` rather than `plugin.json`. |
| Normalize backslashes during security scan | Allows zips created by Windows archivers to be processed safely instead of failing path traversal validation. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
