# Findings & Decisions

## Requirements
- Elevate the documentation structure for non-technical brand administrators and staff.
- Take full-page screenshots of each page (empty, filled, or error states where applicable) and save them under `docs/user_guide/{page_name}/screenshots/`.
- Use descriptive kebab-case names for folders, files, and screenshots.
- Structure every guide file with: Title, Purpose, Overview, Getting Here, Page Sections, Fields Reference Table, Step-by-Step, Config Guide, Best Practices, Must Do, Optional, Troubleshooting, Related Pages, Notes.
- Create a master index file `docs/user_guide/README.md` with an introduction, hyperlinked table of contents, and Quick Start.
- Tone: Professional, approachable, active voice, sentence case headings.

## Research Findings
Discovered full site map from routing files and active dashboard sidebar navigation:

1. **Authentication (Public)**
   - `/login` - Login Page
   - `/forgot-password` - Forgot Password Page
   - `/2fa` - 2FA Entry Page

2. **Dashboard**
   - `/admin` - Dashboard Home

3. **Payments**
   - `/admin/transactions` - Transactions list and detail `/admin/transactions/{id}`
   - `/admin/invoices` - Invoices list, create `/admin/invoices/create`, detail `/admin/invoices/{id}`
   - `/admin/payment-links` - Payment Links list, create `/admin/payment-links/create`, detail `/admin/payment-links/{id}`
   - `/admin/ledger` - Ledger balances and entries

4. **Gateways**
   - `/admin/gateways` - Gateway settings & manual gateways `/admin/gateways/create-manual`

5. **People**
   - `/admin/brands` - Brands list, create, edit
   - `/admin/customers` - Customers list, create, details
   - `/admin/staff` - Staff list, create, edit
   - `/admin/roles` - Roles & Permissions

6. **Mobile & SMS**
   - `/admin/devices` - Paired Devices
   - `/admin/sms-center` - SMS parsing templates
   - `/admin/sms-data` - Parsed SMS logs

7. **Reports & Finance**
   - `/admin/reports` - Reports dashboard
   - `/admin/activities` - Audit Log / Activities
   - `/admin/balance-verification` - Balance Verification

8. **Developers**
   - `/admin/developer` - Developer Hub (with sub-tabs for API Keys, Webhooks, Rate Limits)

9. **Appearance**
   - `/admin/settings#tab-branding` - Branding configuration
   - `/admin/settings#tab-landing` - Landing page settings
   - `/admin/themes` - Themes manager

10. **System**
    - `/admin/plugins` - Plugins manager
    - `/admin/addons` - Addons list
    - `/admin/domains` - Custom Domains
    - `/admin/settings` - System Settings
    - `/admin/system-update` - System Update

11. **Account**
    - `/admin/my-account` - User Account profile & 2FA setup (`/admin/my-account/2fa`)

12. **Checkout Flow (Public)**
    - `/checkout/{token}` or `/checkout/intent/{token}` - Payment Checkout
    - `/pay/{slug}` - Payment Link Checkout

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Set Admin Password | Updated password for `admin@example.com` to `admin123` via a scratch script to enable login and screen capturing. |
| Use chrome-devtools-mcp | Run automated page navigation, snapshots, and screenshot captures using Chromium. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Chrome Profile Lock | Found and stopped the orphaned Chrome process (PID 10064) locking the profile directory. |

## Resources
- `config/routes/web.php` - Routing definitions
- `src/Kernel.php` - App lifecycle
- `chrome-devtools-mcp` - Automation tool

