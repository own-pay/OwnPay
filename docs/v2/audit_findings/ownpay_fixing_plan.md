# OwnPay Fixing Plan — INV-1: Single Super-Administrator Invariant

**Date:** 2026-06-12  
**Author:** Senior Software Architect  
**Subject:** Fix plan for the Single Super-Administrator structural and logic violation identified in `ownpay_master_audit_report.md`.

---

## 1. Finding Validation Summary

After a deep cross-check of the codebase, we confirm that:
1. **INV-1: Single Super-Administrator (Business Model)** is a **REAL** and **CRITICAL** structural violation. The database schema in `database/schema.sql` defines `merchant_id` as `BIGINT UNSIGNED NOT NULL` on the `op_merchant_users` table. This creates a hard coupling between the super-administrator user and a specific merchant brand. The foreign key constraint `fk_mu_merchant` is defined as `ON DELETE CASCADE`. If the merchant to which the superadmin is assigned is deleted (e.g., during active brand cleanup), the superadmin user is cascade-deleted, locking out the system owner.
2. All other verified findings (INV-2, INV-5, etc.) are indeed **PASS**, showing robust implementation of double-entry ledger bookkeeping, brand context scoping, and installer environment variable parsing.

---

## 2. Root Cause Analysis

In `database/schema.sql` (lines 64-89):
```sql
CREATE TABLE `op_merchant_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  ...
  `is_superadmin` TINYINT(1) NOT NULL DEFAULT 0,
  ...
  CONSTRAINT `fk_mu_merchant` FOREIGN KEY (`merchant_id`) REFERENCES `op_merchants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mu_role` FOREIGN KEY (`role_id`) REFERENCES `op_roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### The Cascade Deletion Vulnerability
If an installation has multiple brands (e.g., Merchant 1 and Merchant 2), a superadmin user is assigned to Merchant 1 (default during installation).
If the superadmin switches their active brand view to Merchant 2, they can trigger `BrandController::delete(1)` to delete Merchant 1.
Because of `CONSTRAINT fk_mu_merchant FOREIGN KEY (merchant_id) REFERENCES op_merchants (id) ON DELETE CASCADE`, deleting Merchant 1 executes a cascade delete on `op_merchant_users` where `merchant_id = 1`. This deletes the superadmin user, terminating the active session and permanently locking the owner out.

---

## 3. Proposed Fix & Architecture Design

To solve the violation while preserving standard RBAC flow for staff users and avoiding excessive code churn (such as creating a separate `op_admins` table), we will make `merchant_id` and `role_id` **nullable** on the `op_merchant_users` table. A user with `is_superadmin = 1` will have both fields set to `NULL`.

### 3.1. Database Schema Migration (DDL)
An incremental migration is required to alter the column definitions and update the foreign key constraints:

```sql
-- Disable foreign keys temporarily for modification
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Modify merchant_id and role_id columns to support NULL values
ALTER TABLE `op_merchant_users`
  MODIFY `merchant_id` BIGINT UNSIGNED DEFAULT NULL,
  MODIFY `role_id` BIGINT UNSIGNED DEFAULT NULL;

-- Re-enable foreign keys
SET FOREIGN_KEY_CHECKS = 1;
```

> [!NOTE]
> We keep `ON DELETE CASCADE` on `fk_mu_merchant`. When a merchant is deleted, any staff user with `merchant_id = deleted_id` is cascade-deleted (expected behavior). The global superadmin user has `merchant_id = NULL`, which does not match the deleted parent key, and is therefore safely preserved.

### 3.2. Installer Bootstrap Modifications
We update `InstallerController::createAdmin()` to insert the superadmin with `NULL` for both `merchant_id` and `role_id`:

```php
// File: src/Controller/Install/InstallerController.php (Lines ~383-388)
// Modify insert statement and parameters:
$stmt = $pdo->prepare(
    "INSERT INTO {$p}merchant_users (merchant_id, role_id, name, username, email, password_hash, is_superadmin, status, created_at, updated_at)
     VALUES (NULL, NULL, ?, ?, ?, ?, 1, 'active', ?, ?)"
);
$stmt->execute([$name, $username, $email, $hash, $now, $now]);
```

### 3.3. Authentication and Session Lifecycle Updates
No major changes are required for `Authenticator.php` because it already handles database columns dynamically:
- When the superadmin logs in, `Authenticator::startSession()` reads `merchant_id = NULL` and `role_id = NULL`.
- It sets `$_SESSION['auth_merchant_id'] = NULL` and `$_SESSION['active_brand_id'] = NULL`.
- `PermissionMiddleware.php` reads `is_superadmin = 1` and bypasses all RBAC permission checks, ignoring the `NULL` role ID.

### 3.4. Brand Context Resolution & Deletion Resiliency
When `$_SESSION['active_brand_id']` is `NULL` (such as at login for a global superadmin), `BrandContext::resolveFromRequest()` falls back to the first available merchant ID:
```php
// File: src/Service/Brand/BrandContext.php
// Current fallback logic handles this perfectly:
$first = $this->db->fetchOne("SELECT id FROM op_merchants ORDER BY id ASC LIMIT 1");
if ($first && isset($first['id']) && is_scalar($first['id'])) {
    $this->activeBrandId = (int) $first['id'];
}
```
However, to prevent issues when the currently active brand is deleted, we must refine `BrandContext::getActiveBrandId()` to verify that the active brand still exists in the database. If it does not, it should fall back to the first available brand and update the session:

```php
// File: src/Service/Brand/BrandContext.php
// Refined getActiveBrandId() logic:
public function getActiveBrandId(): ?int
{
    $id = null;
    if ($this->activeBrandId !== null) {
        $id = $this->activeBrandId;
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        $abId = $_SESSION['active_brand_id'] ?? null;
        if (is_scalar($abId)) {
            $id = (int) $abId;
        } else {
            $amId = $_SESSION['auth_merchant_id'] ?? null;
            if (is_scalar($amId)) {
                $id = (int) $amId;
            }
        }
    }

    if ($id !== null && $id > 0) {
        // Validate if the merchant brand still exists
        $exists = $this->db->fetchOne("SELECT id FROM op_merchants WHERE id = :id", ['id' => $id]);
        if ($exists) {
            return $id;
        }
        
        // Brand was deleted; trigger fallback
        $first = $this->db->fetchOne("SELECT id FROM op_merchants ORDER BY id ASC LIMIT 1");
        if ($first && isset($first['id']) && is_scalar($first['id'])) {
            $fallbackId = (int) $first['id'];
            $this->setActiveBrandId($fallbackId);
            return $fallbackId;
        }
    }

    return null;
}
```

### 3.5. Staff List UI & Template Safety
In the global staff list:
```sql
SELECT u.*, m.name as brand_name, r.name as role
FROM op_merchant_users u
LEFT JOIN op_merchants m ON u.merchant_id = m.id
LEFT JOIN op_roles r ON u.role_id = r.id
ORDER BY u.name
```
For the superadmin, `brand_name` and `role` will be resolved as `NULL` via the `LEFT JOIN`.
We will update the templates (e.g., `templates/admin/staff/index.twig`) to display safe fallbacks:
```twig
<td>{{ user.brand_name ?? 'System (Global)' }}</td>
<td>{{ user.role ?? 'Superadmin' }}</td>
```

---

## 4. Verification & Testing Plan

To ensure the fix is robust and does not introduce regressions:
1. **Automated Tests:** Execute the full test suite (`vendor/bin/phpunit`) to verify that the core user and tenant scoping tests continue to pass.
2. **Schema Audit:** Verify that `database/schema.sql` imports clean database states and that column definitions match the updated nullable types.
3. **Manual Verification Flow:**
   - Run the installer wizard, create a new superadmin.
   - Assert that the superadmin user is successfully created in `op_merchant_users` with `merchant_id = NULL` and `role_id = NULL`.
   - Log in as the superadmin, verify they can switch between brands and access the global dashboard view.
   - Create a second brand, switch context to the second brand, and delete the first brand.
   - Assert that the first brand is successfully deleted, the superadmin user remains intact in `op_merchant_users`, and the active brand context resolves to the second brand without throwing database errors.
