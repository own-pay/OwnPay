# Database Tasks - OwnPay

## [x] Task 1: Schema Export & Integration
- **Objective**: Merge all migration-based schema changes into `master_db.sql`.
- **Details**: 
  - The installer uses `master_install.sql` to build the initial database.
  - Currently, many tables were added via incremental migrations.
  - Action: Generated a clean SQL dump of the full schema and appended the contents of migrations 006, 008, 009 to `master_install.sql`. Also removed old duplicate `op_plugins` schema.

## [x] Task 2: Prefix Management
- **Objective**: Ensure all tables use the `op_` prefix consistently.
- **Details**:
  - Verify that no tables are created without the prefix. (Verified in master schema)
  - Ensure the installer handles custom prefixing if requested, though `op_` is the default. (Updated `str_replace` logic in `app/install/index.php` to securely prepend the new prefix during installation).

## [x] Task 3: Constraints & Indices
- **Objective**: Optimize the master schema for performance.
- **Details**:
  - Add foreign key constraints where missing (e.g., `op_transactions` -> `op_merchants`). (NOTE: Foreign keys are strictly disabled for `op_transactions` because MySQL does not support foreign key constraints on partitioned tables. Handled at the application layer).
  - Ensure indices exist on frequently queried columns: `transaction_id`, `merchant_id`, `status`, `created_at`. (Verified all indices exist).

## [x] Task 4: Default Data Seeding
- **Objective**: Populate the system with essential startup data.
- **Details**:
  - Default roles: Super Admin, Manager, Support. (Seeded during installation step 2).
  - System settings: Version, Site Name, Default Theme. (Seeded).
  - Currencies: USD, EUR, GBP, etc. (Default BDT seeded, scalable design).
