# Business Model Migration — Development Task Tracker

> **Plan:** [business_model.md](./business_model.md)
> **Status:** ✅ Approved — Executing
> **Target:** OwnPay v0.1.0 — Single-Owner Multi-Brand

---

## Phase 1: Auth & User Model Simplification
- [x] Add `is_superadmin` column to `op_merchant_users` in `schema.sql`
- [x] Update `Authenticator.php` → store `is_superadmin` in session
- [x] Update `AuthSessionService.php` → add `switchBrand()`, `getActiveBrandId()`, `getAllBrands()`
- [ ] Update installer → set first admin user as `is_superadmin = 1`
- [ ] Verify login still works after session changes

## Phase 2: Brand Context System
- [x] Create `src/Service/Brand/BrandContext.php`
  - [x] `getActiveBrandId(): ?int`
  - [x] `setActiveBrandId(int $id): void`
  - [x] `isGlobalView(): bool`
  - [x] `getAllBrands(): array`
  - [x] `resolveFromRequest(Request $req): int`
- [x] Register `BrandContext` in `config/services.php`
- [x] Update `TenantScope.php` → add `forAllTenants()` method
- [x] Wire `BrandContext` into DI container

## Phase 3: Controller Refactoring
- [x] Rename `MerchantController.php` → `BrandController.php`
- [x] Fix `BrandController` SQL queries (`op_custom_domains` → `op_domains`, `business_name` → `name`)
- [x] Fix `StaffController` SQL queries (`op_users` → `op_merchant_users`)
- [x] Update `DashboardController` → support global view + brand-scoped view
- [x] Replace `$req->getAttribute('merchant_id')` → `BrandContext::getActiveBrandId()` in:
  - [x] `ApiKeyController.php`
  - [x] `BalanceVerificationController.php`
  - [x] `CurrencyController.php`
  - [x] `CustomerController.php`
  - [x] `DashboardController.php`
  - [x] `DeviceController.php`
  - [x] `DomainController.php`
  - [x] `GatewayController.php`
  - [x] `InvoiceController.php`
  - [x] `PaymentLinkController.php`
  - [x] `SettingsController.php`
  - [x] `SmsDataController.php`
  - [x] `SmsTemplateAdminController.php`
  - [x] `StaffController.php`
  - [x] `TransactionController.php`

## Phase 4: UI/Template Changes
- [x] Add Brand Selector dropdown to `templates/admin/layout/base.twig`
- [x] Add Brand Selector logic to `templates/admin/layout/sidebar.twig`
- [x] Rename `templates/admin/merchants/` → `templates/admin/brands/`
- [x] Update sidebar menu: "Merchants" → "Brands"
- [x] Search-replace "Merchant" → "Brand" in all admin templates
- [x] Ensure brand name + logo shown in header when brand selected

## Phase 5: Route & Config Updates
- [x] Update `config/routes/web.php` → `/admin/merchants` → `/admin/brands`
- [x] Update `src/Core/RouteConfig.php` if needed
- [x] Update `PermissionMiddleware.php` → permission map `/admin/merchants` → `/admin/brands`
- [x] Update permission slugs: `merchants.view` → `brands.view`, `merchants.manage` → `brands.manage`

## Phase 6: Database Seed & Installer
- [x] Update installer to create default Brand + Owner role + superadmin user
- [x] Remove any merchant self-signup logic (if any)
- [x] Update `database/schema.sql` with `is_superadmin` column
- [x] Write SQL migration script for existing installations

## Phase 7: Landing Page & Public Routes
- [x] Update `LandingController.php` → remove merchant-specific copy
- [x] Clean up any public merchant signup routes

---

## Post-Migration Verification
- [ ] PHP lint all modified files
- [ ] Browser test: Login flow
- [ ] Browser test: Brand creation
- [ ] Browser test: Brand switching
- [ ] Browser test: Brand-scoped dashboard stats
- [ ] Browser test: Global dashboard stats
- [ ] API test: Brand-scoped API key auth
- [ ] Test: Custom domain → correct brand resolution
- [ ] Test: Checkout page shows correct brand gateways
