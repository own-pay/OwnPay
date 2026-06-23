# Task Plan: Restructure Admin Panel (v2)

## Goal
Restructure the entire OwnPay Admin Panel to achieve premium UI/UX, merge duplicate options, clean up sidebar redundancies, add settings descriptions, and add subtitles to all 48 user-facing pages.

## Current Phase
Phase 5

## Phases

### Phase 1: Requirements & Discovery
- [x] Identify redundancies and duplicates (Account menu group, Theme tab vs Branding defaults, Edit Brand vs Brand Settings)
- [x] Compile list of settings requiring descriptions
- [x] Compile list of 48 templates requiring subtitles
- **Status:** complete

### Phase 2: Design & Plan Approval
- [x] Write detailed implementation plan in implementation_plan.md
- [x] Wait for user approval
- **Status:** complete

### Phase 3: Sidebar & Settings Restructuring (Implementation)
- [x] Remove duplicate Account group from `sidebar.twig`
- [x] Merge `Theme` tab into `Branding Defaults` in `index.twig` (Settings)
- [x] Move "Admin Login URL Slug" to the `Security` tab in `index.twig`
- [x] Add Brand details fields (Name, Email, Phone, Timezone, Currency) to `Identity & Branding` in brand settings
- [x] Wrap all global settings tabs in `{% if not is_brand_view %}` checks
- [x] Update `SettingsController.php` index/save to handle brand details saving and correct tab redirections
- **Status:** complete

### Phase 4: Settings Descriptions & Page Subtitles (Implementation)
- [x] Add 1-2 line descriptions to all settings options in `index.twig`
- [x] Add page subtitles to all 48 user-facing twig templates
- **Status:** complete

### Phase 5: Verification & Quality Assurance
- [x] Run `composer lint:twig` to verify Twig templates
- [x] Run `vendor/bin/phpstan analyse` to verify static analysis type safety
- [x] Run `vendor/bin/phpunit` to ensure all 547 unit/integration tests pass
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Merge Brand details into Brand Settings | Lets brand operators manage brand profile and branding visual assets in one consolidated settings tab. |

## Errors Encountered
| Error | Resolution |
|-------|------------|

