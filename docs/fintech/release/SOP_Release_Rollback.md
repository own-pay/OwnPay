# Own Pay — Standard Operating Procedure (SOP)
## Release Management & Emergency Rollback

### 1. Pre-Deployment Preparation
Before triggering a deployment to the production environment, ensure the following steps are completed.

#### 1.1 Infrastructure Checks
- **Database Backup:** Ensure a fresh backup of the `ownpay` MySQL database is safely stored in an off-server location (e.g., S3 bucket).
- **Disk Space:** Verify the server has at least 20% free disk space for log rotation and composer cache.
- **CI/CD Status:** Ensure all GitHub Actions (PHP Lint Guard, SQL Pattern Guard) are passing green on the release branch.

#### 1.2 Communication
- Notify internal stakeholders (Support, Engineering via Slack) that a deployment window is beginning.
- If expected downtime > 1 minute, display an active maintenance banner on the gateway landing page.

---

### 2. Execution (Deployment)
Execute these steps sequentially on the production server.

1. **Pull Latest Code:**
   ```bash
   git pull origin main
   ```

2. **Install/Update Dependencies:**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Run Database Migrations (If applicable):**
   *(Note: Own Pay currently handles schema manually or via installer sync. If running manual SQL scripts, execute them now.)*
   ```bash
   mysql -u root -p ownpay < updates.sql
   ```

4. **Clear Caches:**
   ```bash
   # Restart php-fpm or Apache to clear opcode caches
   sudo systemctl restart php8.3-fpm
   # or
   sudo systemctl restart apache2
   ```

---

### 3. Post-Deployment Verification (Smoke Testing)
Immediately after the cache is cleared, run the automated smoke suite.

```bash
php tests/smoke_test.php
```

**Expected Output:**
- `[PASS] PHP Version`
- `[PASS] Configuration file found`
- `[PASS] Database connection successful`
- `[PASS] Core File exists`
- `[PASS] Composer dependencies found`
- `[SUCCESS] All critical smoke tests passed. System is ready.`

**Manual Health Checks:**
- Visit `https://your-domain.com/v1/health` (if implemented).
- Attempt a mock login on the admin panel `https://your-domain.com/app/admin/login.php`.

---

### 4. Emergency Rollback Procedure
If the smoke test fails or critical P0 bugs are discovered in production within the first 15 minutes of deployment, initiate an emergency rollback.

#### 4.1 Code Revert
1. Identify the last stable commit hash (`git log --oneline`).
2. Hard reset the repository to that commit:
   ```bash
   git reset --hard <PREVIOUS_STABLE_COMMIT_HASH>
   ```

#### 4.2 Dependency Revert
```bash
composer install --no-dev --optimize-autoloader
```

#### 4.3 Database Restore
**WARNING:** If database schema changes were made during the deployment that corrupt legacy code, you MUST restore the database.
*Note: Restoring a database means any real transactions processed in the last 15 minutes will be lost. Communicate this immediately.*

1. Drop and Recreate:
   ```bash
   mysql -u root -p -e "DROP DATABASE ownpay; CREATE DATABASE ownpay;"
   ```
2. Import Pre-deployment Backup:
   ```bash
   mysql -u root -p ownpay < /path/to/pre_deploy_backup.sql
   ```

#### 4.4 Finalizing Rollback
1. Clear caches again (restarting PHP-FPM / Apache).
2. Run `php tests/smoke_test.php` to confirm the older version is stable.
3. Notify stakeholders that the rollback is complete.
