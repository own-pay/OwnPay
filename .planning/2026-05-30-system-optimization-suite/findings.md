# Findings: system-optimization-suite

## Technical Discoveries
*   **Cache Management**:
    *   Cache contracts are governed by `OwnPay\Cache\CacheInterface` with a dedicated `.flush()` method.
    *   Twig templates compiled files are cached in `storage/cache/twig/` recursively.
    *   The admin login slug is cached as a flat file in `storage/cache/login_slug.cache`.
*   **Database Schema & Tables**:
    *   Hot/high-churn tables: `op_transactions`, `op_ledger_entries`, `op_sms_parsed`, and `op_audit_logs`.
    *   Running `ANALYZE TABLE` is extremely fast and completely non-blocking for InnoDB tables.
    *   Running `OPTIMIZE TABLE` on hot tables reclaims disk space and defragments indices. Running them table-by-table mitigates potential production locks.
*   **Logging & Sessions**:
    *   Logging tables: `op_audit_logs`, `op_login_attempts`, `op_comm_log`, and `op_webhook_delivery_logs`.
    *   Session tables: `op_sessions`.
    *   `op_audit_logs` has a cryptographic HMAC field (`signature`) verified by `AuditLogRepository::verifyIntegrity()`. Purging old logs will safely reduce table sizes without violating existing signatures.
*   **Temporary Files Storage**:
    *   Transient upload files and temporary templates are stored in `storage/temp/`. We can safely scan and purge aged files.

## Paths & Patterns
*   **Controller**: `src/Controller/Admin/SettingsController.php`
*   **Template**: `templates/admin/settings/index.twig`
*   **Styles**: `public/assets/css/pages/settings.css`
*   **Routes**: `config/routes/web.php`
