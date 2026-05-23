# Task Plan: PHPStan Level 9 Hardening

## Goal
Resolve all remaining PHPStan Level 9 static analysis errors in `src` to reach 100% type-safety (0 errors).

## Current Phase
Phase 3: Implementation

## Phases

### Phase 1: Discovery & Errors Mapping
- [x] Run PHPStan on the entire `src/Controller/Admin` directory.
- [x] Map out remaining 116 errors across 6 controller files in Admin.
- [x] Run PHPStan on the whole `src` directory to find remaining non-Admin errors.
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Create active session planning files.
- [x] Formulate specific type narrowing and safe container resolution strategies.
- **Status:** complete

### Phase 3: Implementation
- [x] Fix all errors in `src/Controller/Admin` directory.
- [x] Fix `src/Controller/BaseController.php` and non-Admin controllers.
- [x] Fix `src/Controller/Webhook/` controllers.
- [x] Fix `src/Core/RouteHelper.php`.
- [x] Fix `src/Event/EventManager.php` (1 error).
- [x] Fix `src/Plugin/` files (PluginInstaller.php, PluginLoader.php, PluginManager.php, PluginManifest.php, PluginMigrator.php, PluginRegistry.php).
- [x] Fix `src/Queue/` files (FileQueue.php, RedisQueue.php).
- [x] Fix `src/Security/` files (Authenticator.php, FieldEncryptor.php, LogSanitizer.php, PiiMasker.php, RequestValidator.php, SecurityHelpers.php).
- [x] Fix `src/Update/` files (BackupService.php, MaintenanceMode.php, UpdateService.php).
- **Status:** complete

### Phase 4: Testing & Verification
- [x] Fix global helper function redeclaration bug in `config/services.php`.
- [x] Run PHPStan analysis on the whole `src` globally and verify 0 errors.
- [x] Run PHPUnit tests to ensure zero functional regressions.
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Dynamic type guards | Instead of `(string) $val` or `(int) $val` directly on `mixed`, use type guards (`is_string($val) ? $val : ''`, `is_numeric($val) ? (int)$val : 0`, etc.) to narrow types explicitly. |
| Container service resolution assertions | Assert instance types for class lookups from PSR-11 container (`$service instanceof Class`). |
