# Task Plan: Find Missing Admin UI

## Goal
Audit the OwnPay admin features mapped in docs/frontend_contribution/ADMIN-PANEL-MAP.md against the codebase, identifying all settings, forms, features, and views that have backend logic (controllers, routes, database tables) but lack corresponding templates/GUIs. Create a comprehensive markdown report listing all identified gaps.

## Current Phase
Phase 1: Codebase Routing & Controller Discovery

## Phases

### Phase 1: Discovery & Mapping
- [x] Initialized planning session
- [ ] List and discover all admin routes in `config/routes/web.php`
- [ ] Inspect existing admin controller methods in `src/Controller/Admin/`
- [ ] Map existing admin Twig templates in `templates/admin/`
- **Status:** in_progress

### Phase 2: Global Settings Audit (Part 1 of Map)
- [ ] Audit General settings (1.1)
- [ ] Audit Email settings (1.2)
- [ ] Audit Platform Branding (1.3)
- [ ] Audit Public Landing Page (1.4)
- [ ] Audit System Payment Settings (1.5)
- [ ] Audit Currencies & Exchange Rates (1.6)
- [ ] Audit SMS & Payment Verification (1.7)
- [ ] Audit Language Management (1.8)
- [ ] Audit Plugins & Themes (1.9)
- [ ] Audit System Update (1.10)
- [ ] Audit System Health (1.11)
- [ ] Audit Activity Logs (1.12)
- **Status:** pending

### Phase 3: Brand Settings Audit (Part 2 of Map)
- [ ] Audit Brand Profile (2.1)
- [ ] Audit Brand Appearance (2.2)
- [ ] Audit Custom Domain (2.3)
- [ ] Audit Payment Gateways (2.4)
- [ ] Audit Fee Rules (2.5)
- [ ] Audit Team & Roles (2.6)
- [ ] Audit API Keys (2.7)
- [ ] Audit Webhooks (2.8)
- [ ] Audit Mobile App & Devices (2.9)
- [ ] Audit SMS Verification Templates (2.10)
- [ ] Audit Plugin Activation (2.11)
- **Status:** pending

### Phase 4: Operational Data & Unified Review (Part 3 & 4 of Map)
- [ ] Audit live transaction and ledger views
- [ ] Audit communication, refund, customer, invoice views
- [ ] Check schema.sql for extra tables/fields not represented in any GUI
- **Status:** pending

### Phase 5: Report Generation & Delivery
- [ ] Compile complete markdown list of all missing GUI features
- [ ] Document findings and recommendations in walkthrough.md
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|

## Errors Encountered
| Error | Resolution |
|-------|------------|
