# OwnPay Update System — Architecture & Audit (Remediated)

## Status: ✅ PRODUCTION READY

All 10 bugs from the initial audit have been fixed. This document is the post-remediation reference.

---

## Client-Side Components

| File | Purpose | Status |
|------|---------|--------|
| `src/Update/UpdateService.php` | 9-step update flow with SHA-256 verification + rollback | ✅ Production |
| `src/Update/BackupService.php` | Full DB dump + code ZIP for rollback | ✅ Production |
| `src/Update/HealthChecker.php` | Post-update verification (DB, files, extensions) | ✅ Fixed |
| `src/Update/MaintenanceMode.php` | File-based maintenance lock | ✅ Production |
| `src/Cron/SystemUpdateJob.php` | Periodic background check (10h interval, DB-persisted) | ✅ Fixed |
| `src/Repository/UpdateHistoryRepository.php` | `op_update_history` table CRUD | ✅ Production |
| `src/Service/System/EnvironmentService.php` | DB-backed persistent key/value store | ✅ Fixed |
| `src/Controller/Admin/SystemUpdateController.php` | Admin UI (check, install, settings) | ✅ Fixed |
| `config/services.php` | DI bindings for entire update stack | ✅ Added |

## Update Flow

### Check Flow (Unified)
```
Client ──GET──▶ https://update.ownpay.org/manifest.json?v=0.1.0
         ◀──── { channels: { stable: {...}, beta: {...} } }
```

Both `UpdateService::check()` and `SystemUpdateJob::run()` now use the same `manifest.json` endpoint.

### Execute Flow (UpdateService::execute)
```
1. Concurrent check  → Prevent duplicate updates via UpdateHistoryRepository
2. Backup            → storage/backups/backup_YYYYMMDD_HHMMSS/ (DB + code ZIP)
3. Maintenance ON    → storage/.maintenance (lock file)
4. Domain Validation → Verifies download URL host is strictly 'update.ownpay.org'
5. Download          → curl to temp file (300s timeout, redirect-safe)
6. Verify (Security) → SHA-256 checksum check (hash_equals) + asymmetric RSA signature verification (openssl_verify) using embedded public key
7. Extract           → ZipArchive with path traversal safety check
8. Migrate           → database/migrations/*.sql, tracked in op_migrations
9. Clear cache       → storage/cache/* + Twig cache
10. Health check     → DB, Kernel.php, extensions, .env, writable dirs
11. Maintenance OFF  → Remove lock file
```

### Rollback (on failure at any step)
```
→ BackupService::restore(backupPath)
→ MaintenanceMode::exit()
→ UpdateHistoryRepository::markRolledBack()
→ Event: update.rollback fired
```

---

## Bugs Fixed (All 10)

| # | Bug | Fix | File |
|---|-----|-----|------|
| 1 | HealthChecker checked `src/Bootstrap.php` (nonexistent) | → `src/Kernel.php` | `HealthChecker.php:72` |
| 2 | UpdateService used `update.json`, SystemUpdateJob used `manifest.json` | Unified to `manifest.json` | Both files + `config/app.php` |
| 3 | EnvironmentService::get/set() in-memory only | DB-backed via `op_system_settings` (group='runtime') with boot() | `EnvironmentService.php` |
| 4 | SystemUpdateJob never instantiated (dead code) | Registered in `config/services.php` with DI | `services.php` |
| 5 | No package integrity verification | SHA-256 checksum via `hash_file()` + `hash_equals()` | `UpdateService.php:160-170` |
| 6 | Migration runner was a stub | Full implementation with `op_migrations` tracking table | `UpdateService.php:280-340` |
| 7 | SystemUpdateJob expected `version_code`+`version_name` array | Now takes simple version string from `config/app.php` | `SystemUpdateJob.php` |
| 8 | No admin update routes/settings | Added `/admin/system-update/settings` route | `web.php:211` |
| 9 | `.env.example` missing update vars | Added `APP_VERSION`, `UPDATE_CHECK_URL`, `HMAC_KEY` | `.env.example` |
| 10 | Template missing hidden form fields | Added version, url, checksum hidden inputs | `system-update.twig` |

### Additional Fixes
- `active_page` in SystemUpdateController → `'system-update'` (was `'settings'`)
- PHP version check in EnvironmentService → 8.2.0 (was 8.1.0)
- EnvironmentService bootstrapped in `Kernel::boot()` after DI container build
- ZIP extraction includes path traversal safety check
- Download uses SSL verification, 300s timeout, redirect following
- Concurrent update prevention via `isUpdateInProgress()` check

---

## Database Schema (3 tables)

### `op_update_history` (existed)
Tracks update attempts, status, backup paths, errors.

### `op_maintenance_locks` (existed)
Tracks maintenance mode lock/unlock events.

### `op_migrations` (NEW — added)
Tracks executed migration files to prevent re-running.

```sql
CREATE TABLE `op_migrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(255) NOT NULL,
  `executed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## DI Container Bindings (config/services.php)

```php
UpdateHistoryRepository   → via $repoFactory
BackupService             → BackupService(backups_path, Logger, Database)
HealthChecker             → HealthChecker(Database)
MaintenanceMode           → MaintenanceMode()
UpdateService             → UpdateService(Backup, Health, Maintenance, History, Events, Logger)
SystemUpdateJob           → SystemUpdateJob(version_string, Events, SettingsRepo)
```

---

## Admin Routes

| Method | Path | Handler |
|--------|------|---------|
| GET | `/admin/system-update` | `SystemUpdateController@index` |
| POST | `/admin/system-update/check` | `SystemUpdateController@check` |
| POST | `/admin/system-update/apply` | `SystemUpdateController@install` |
| POST | `/admin/system-update/settings` | `SystemUpdateController@settings` |

Permission: `system.update` (superadmin only)
