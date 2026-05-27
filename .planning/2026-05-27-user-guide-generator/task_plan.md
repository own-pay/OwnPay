# Task Plan: OwnPay User Guide Generator

## Goal
To produce a complete, publication-ready, multi-brand and gateway-focused User Guide for the OwnPay platform in Markdown, located under `docs/user_guide/` with screenshots.

## Current Phase
Phase 8: Complete

## Phases

### Phase 1: Requirements & Discovery
- [x] Bootstrapped session and solved browser profile lock.
- [x] Configured test administrator password to `admin123` via database.
- [x] Discovered full site map.
- [x] Documented constraints and findings in `findings.md`.
- **Status:** complete

### Phase 2: Authentication & Main Dashboard
- [x] Document Login page (`/login`)
- [x] Document Forgot Password page (`/forgot-password`)
- [x] Document 2FA entry page (`/2fa`)
- [x] Document Main Dashboard (`/admin`)
- **Status:** complete

### Phase 3: Payments & Gateways
- [x] Document Transactions page (`/admin/transactions`) & transaction detail
- [x] Document Invoices page (`/admin/invoices`) & invoice creation
- [x] Document Payment Links page (`/admin/payment-links`) & creation
- [x] Document Ledger page (`/admin/ledger`)
- [x] Document Payment Gateways page (`/admin/gateways`) & manual gateways
- [x] Document Currencies & Rates page (`/admin/settings/payment`)
- **Status:** complete

### Phase 4: People & Access Control
- [x] Document Brands page (`/admin/brands`)
- [x] Document Customers page (`/admin/customers`)
- [x] Document Staff page (`/admin/staff`)
- [x] Document Roles & Permissions page (`/admin/roles`)
- **Status:** complete

### Phase 5: Mobile & SMS
- [x] Document Paired Devices page (`/admin/devices`)
- [x] Document SMS Center page (`/admin/sms-center`)
- [x] Document SMS Data page (`/admin/sms-data`)
- **Status:** complete

### Phase 6: Finance & Developers
- [x] Document Reports page (`/admin/reports`)
- [x] Document Audit Log / Activities page (`/admin/activities`)
- [x] Document Balance Verification page (`/admin/balance-verification`)
- [x] Document Developer Hub page (`/admin/developer`)
- **Status:** complete

### Phase 7: Appearance & System
- [x] Document Branding settings (`/admin/settings#tab-branding`)
- [x] Document Landing Page settings (`/admin/settings#tab-landing`)
- [x] Document Themes page (`/admin/themes`)
- [x] Document Plugins page (`/admin/plugins`)
- [x] Document Addons page (`/admin/addons`)
- [x] Document Domains page (`/admin/domains`)
- [x] Document System Settings page (`/admin/settings`)
- [x] Document System Update page (`/admin/system-update`)
- **Status:** complete

### Phase 8: Account, Public checkout, and Master index
- [x] Document My Account page (`/admin/my-account` & `/admin/my-account/2fa`)
- [x] Document Checkout Flow (`/checkout/{token}` or `/checkout/intent/{token}`)
- [x] Document Payment Link checkout flow (`/pay/{slug}`)
- [x] Create Master Index file (`docs/user_guide/README.md`)
- [x] Scan and verify quality gates on all generated documents
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Group pages logically | Mirror the sidebar structure (Dashboard, Payments, Gateways, People, Mobile & SMS, Reports & Finance, Developers, Appearance, System, Account) to make it easy for users to navigate. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
| Chrome lockfile | Killed the parent chrome process PID 10064. |
