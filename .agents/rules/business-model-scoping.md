---
trigger: always_on
---

# Scoping & Business Model Rules

## Business Model Definition

- **OwnPay is NOT a SaaS/multi-tenant platform**. It is a single-owner, multi-brand/store system.
- **One super-administrator** owns the entire platform and controls everything globally. No customer self-registration exists.
- **Multiple Brands/Stores** (`op_merchants` table) are managed by the admin.
- **Staff Members** are created by the admin and assigned to specific brands with role-based permissions (`op_roles` and `op_role_permissions`).
- In the database and repository API, the scoping column remains `merchant_id` (even though UI/user views label it as "Brand" or "Store").

## Scoping Requirements for Repositories

- All repositories extending `BaseRepository` that handle brand-specific data MUST use the `TenantScope` trait.
- Before executing any scoped repository query or operation, the brand context MUST be set explicitly by calling:

  ```php
  $scopedRepo = $this->repo->forTenant($merchantId);
  ```

- Use the dedicated `TenantScope` methods for brand-specific CRUD operations:
  - `paginateScoped(int $page, int $perPage): array` (Never use `listPaginated` - it does not exist)
  - `findScoped(int $id): ?array`
  - `createScoped(array $data): string`
  - `updateScoped(int $id, array $data): int`
  - `deleteScoped(int $id): int`
  - `countScoped(string $where, array $params): int`
- Bypassing tenant scope via `forAllTenants()` is ONLY permitted for global, super-administrator viewpoints and views.

## Brand Context Resolution

- The active brand context MUST be resolved using `OwnPay\Service\Brand\BrandContext`.
- Inside admin-level controllers, you must call `BrandContext::resolveFromRequest($req)` to resolve and cache the brand ID, and retrieve it via `getActiveBrandId()`.
- Standard staff users MUST never be allowed to access or switch to brands to which they are not assigned.
- Switching into the global **All Brands** view requires the `brands.access_all` permission (or `is_superadmin`); enforced in `BrandController::switchBrand`.

## Platform Scope & Data Ownership ("All Brands")

- A single reserved platform-owner row exists in `op_merchants` with `is_platform = 1` (slug `__platform__`). It represents the global "All Brands" scope and OWNS All-Brands-level operational data and configuration. It is EXCLUDED from `getAllBrands()` / the brand switcher and is never a selectable brand.
- Resolve it via `BrandContext::getPlatformId()` (lazy-resolves the `is_platform = 1` row; the id differs per database - NEVER hard-code it).
- For WRITES, choose the owner with `BrandContext::getWriteMerchantId()`: it returns the platform id in All-Brands view, else the active brand id. Use this (not `getActiveBrandId()`, which is 0 in All-Brands view) when creating All-Brands-owned records (e.g. admin-generated API keys, manual-gateway templates).
- READS: brand view is hard-scoped to its own `merchant_id`; the All-Brands view uses `forAllTenants()` (unscoped) and therefore reads every brand's rows PLUS the platform-owned rows. Brand-owned data is readable by that brand and by All Brands; platform-owned data is readable only by All Brands.
- Config cascade: `SettingsRepository::getScoped(group, key, merchantId)` resolves brand override → All-Brands (global) default → code default. Brand overrides apply only to that brand.
