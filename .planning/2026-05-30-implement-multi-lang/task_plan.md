# Task Plan: OwnPay Multi-Language (i18n) System Implementation

## Goal
Completely implement a database-backed, professional, enterprise-grade multi-language system in OwnPay for the admin panel, allowing manual creation, JSON upload, string-by-string translation, and staff profile overrides, with all 49 admin Twig templates fully refactored to use dynamic translations.

## Current Phase
complete

## Phases

### Phase 1: Requirements & Discovery
- [x] Gather requirements through user interview (/grill-me)
- [x] Identify database, scope, validation, and JSON upload constraints
- [x] Create comprehensive implementation plan in artifacts
- **Status:** complete

### Phase 2: Database Migration & Core Translation Logic
- [x] Create SQL migration `007_create_languages_table.sql` under `database/migrations/`
- [x] Update unified database schema file `database/schema.sql`
- [x] Apply migration to `ownpay` (dev) and `ownpay_test` (test) databases
- [x] Implement `TranslationService` in `src/Service/System/TranslationService.php`
- [x] Implement `LanguageMiddleware` in `src/Middleware/LanguageMiddleware.php`
- [x] Register `LanguageMiddleware` under `'admin'` and `'admin-api'` stacks in `config/middleware.php`
- [x] Register Twig filter and function in `config/services.php`
- **Status:** complete

### Phase 3: Settings tab and Translations UI
- [x] Add `Language` tab to settings page view in `templates/admin/settings/index.twig`
- [x] Implement controller routes in `config/routes/web.php`
- [x] Implement controller methods (create, delete, upload, save default) in `SettingsController.php`
- [x] Implement strings translation grid UI page in `templates/admin/settings/translate.twig`
- [x] Implement string saving action in `SettingsController.php`
- **Status:** complete

### Phase 4: Account/Staff Profile Override
- [x] Add language override dropdown in Account settings template `templates/admin/my-account.twig`
- [x] Update profile update controller `DashboardController.php` to persist staff user language choice
- **Status:** complete

### Phase 5: Refactor Admin Twig Templates
- [x] Scan and refactor all 49 admin Twig templates under `templates/admin/` to use translation filters/functions
- **Status:** complete

### Phase 6: Integration Testing & Verification
- [x] Write integration test suite `tests/Integration/LanguageSystemTest.php`
- [x] Run PHPUnit tests and verify all pass successfully
- [x] Run PHPStan analysis and lint checks
- [x] Create walkthrough documenting implementation and results
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Database-backed Translations | Allows dynamic uploads, deletes, and direct edits without server file system write permission issues. |
| Global default + Profile Override | Standard for enterprise multi-user panels where staff might speak different languages. |
| Laravel-style `:key` placeholders | Familiar syntax for PHP developers, clean implementation. |
| Flat dotted representation in UI | Easier to search, filter, and translate than nested form trees. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
