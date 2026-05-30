# Task Plan: robust-language-fallback

## Goal
Implement a robust, auto-recovering translation system that stores the master `en.json` file in a system directory (`config/languages/en.json`), copies it to the `storage/languages/en.json` directory during installation and dynamically on-the-fly in production if missing, and loads all active translations directly from the `storage/languages/` JSON files, keeping them fully synchronized with the database.

## Current Phase
Phase 5: Delivery

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user requirements for robust `en.json` recovery and file-based translation loading.
- [x] Identify target files for modification: `TranslationService.php`, `InstallerController.php`, and new `config/languages/en.json`.
- [x] Document discoveries in findings.md.
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define location of master `en.json` file: `config/languages/en.json`.
- [x] Write detailed Implementation Plan inside `implementation_plan.md` artifact.
- **Status:** complete

### Phase 3: Implementation
- [x] Create master `en.json` file at `config/languages/en.json`.
- [x] Update `InstallerController.php` to create `storage/languages` and copy `config/languages/en.json` to `storage/languages/en.json`.
- [x] Update `TranslationService.php` to load translations from `storage/languages/{code}.json` with automatic copying of `en.json` if missing, and write files on save/upload/create actions.
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Verify translation files are copied during installation and recovered instantly in production if missing.
- [x] Run full PHPUnit test suite to ensure zero regressions.
- [x] Run PHPStan static analysis check.
- **Status:** complete

### Phase 5: Delivery
- [x] Finalize walkthrough.md documenting changes.
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Master `en.json` at `config/languages/` | Keeps it version-controlled and separate from mutable runtime files in `storage/`. |
| Load from `storage/languages/` | Speeds up boot speed and keeps custom/uploaded languages accessible even on database failures. |
| Auto-recovery on-the-fly | If `storage/` gets wiped, the system restores the baseline language instantly without admin intervention. |
| DB-File Dual-Write | Ensures database queries and file lookups are perfectly in-sync. |

## Errors Encountered
| Error | Resolution |
|-------|------------|

