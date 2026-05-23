# Task Plan: OwnPay Diagnostic & Codebase-wide Forensic Audit

## Goal
Locate the root cause of the local server accessibility issues (`ERR_CONNECTION_REFUSED`), perform a deep-driven forensic audit of the entire codebase to discover bugs/regressions, resolve all identified issues, and verify that the system runs flawlessly.

## Current Phase
Phase 5: Documentation & Report

## Phases

### Phase 1: Environment Diagnostics & Server Status
- [x] Investigate `ERR_CONNECTION_REFUSED` for `https://ownpay.test/`
- [x] Check web server process status (Apache/Nginx/PHP-FPM/Laragon)
- [x] Test database connectivity and port binding
- [x] Inspect PHP/Nginx log files for errors or boot crashes
- **Status:** completed

### Phase 2: Microscopic Codebase Audit
- [x] Analyze routing, bootstrap, and middleware pipeline for any logic/loading regressions
- [x] Scan controllers and services for DB integrity, CSRF tokens, tenant boundaries, and ledger balance rules
- [x] Review recent changes in git diff to catch introduced bugs
- [x] Run PHPStan and PHPUnit to see existing compile-time or run-time failures
- **Status:** completed

### Phase 3: Resolution & Bug-Fixing
- [x] Resolve any local server, domain, port, or environment configuration issues
- [x] Fix any logical bugs or structural regressions discovered in the codebase
- [x] Verify each fix with syntax checks and targeted testing
- **Status:** completed

### Phase 4: Full Suite Testing & Verification
- [x] Run the complete PHPUnit test suite
- [x] Run PHPStan analysis
- [x] Test public endpoints and white-label checkout flows manually or via curl
- **Status:** completed

### Phase 5: Documentation & Report
- [x] Write down all discoveries and fixes in `findings.md`
- [x] Document final progress in `progress.md`
- [x] Present audit results and fix details to the user
- **Status:** completed

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Corrected Apache SRVROOT | Restored Apache capability to find its bin/conf directory and modules |
| Loaded SSL, Rewrite, and Shmcb modules | Enabled SSL virtual hosts and .htaccess routing rewrite rules |
| Enabled DirectoryIndex index.php | Enabled execution of index.php on root/directory requests |
| Corrected docblock array schemas | Resolves type checking issues and ensures full schema coverage in static analysis |
| Swapped end() for manual indexing | Adheres to strict PHP reference rules for class constants |
| Removed unused LedgerService method | Eliminates dead code paths and keeps codebase tidy |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| ERR_CONNECTION_REFUSED | Checked active ports | Located and fixed Apache configuration errors, then restarted the server |
| Invalid command 'SSLEngine' | Ran Apache syntax check | Uncommented `ssl_module` in `httpd.conf` |
| Directory Index Forbidden | Requested root URL | Added `index.php` to `DirectoryIndex` directive in `httpd.conf` |
| PHPStan: Offset 'expected_balance' does not exist | Updated ReconciliationService return type docblock | Corrected the return array definition |
| PHPStan: end() is passed by reference | Replaced with indexing lookup | Removed reference-by-value of array constants |
| PHPStan: Unused postJournal() method | Cleaned up LedgerService | Removed dead private code block |
