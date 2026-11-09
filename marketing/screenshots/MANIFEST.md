# OwnPay Marketing Screenshots Manifest

Generated for a "first look" promotional video (YouTube, Facebook, Instagram).

- Desktop shots: 1920x1080 (16:9) - file suffix `-desktop.png`
- Mobile shots: 1080x1920 (9:16) - file suffix `-mobile.png`
- Theme: light (default) across all shots.
- All API keys, secrets, tokens, webhook signing secrets, IP addresses and personal data are absent or shown as placeholders in these captures. No real `.env` or database connection string is displayed.

## Screenshot index

| # | File (desktop) | Mobile variant | Route / URL | Page title | What the page shows | Suggested scene |
|---|----------------|----------------|-------------|------------|---------------------|-----------------|
| 1 | 01-landing-desktop.png | 01-landing-mobile.png | `/` | Landing | Public marketing landing page with product overview and call to action | Intro |
| 2 | 02-login-desktop.png | 02-login-mobile.png | `/login` | Login | Admin login form (email + password) | Setup |
| 3 | 03-forgot-password-desktop.png | 03-forgot-password-mobile.png | `/forgot-password` | Forgot Password | Password reset request form | Setup |
| 4 | 04-dashboard-desktop.png | 04-dashboard-mobile.png | `/admin` | Dashboard | Admin dashboard with stats, recent activity and notifications | Dashboard |
| 5 | 05-transactions-desktop.png | 05-transactions-mobile.png | `/admin/transactions` | Transactions | Transaction list with varied statuses (completed, pending, failed, processing) | Transactions |
| 6 | 06-transaction-detail-desktop.png | 06-transaction-detail-mobile.png | `/admin/transactions/{id}` | Transaction Detail | Single transaction details, customer info, actions | Transactions |
| 7 | 07-payment-intents-desktop.png | 07-payment-intents-mobile.png | `/admin/payment-intents` | Payment Intents | Payment intent list and status | Payment flow |
| 8 | 08-invoices-desktop.png | 08-invoices-mobile.png | `/admin/invoices` | Invoices | Invoice list with totals and status | Payment flow |
| 9 | 09-payment-links-desktop.png | 09-payment-links-mobile.png | `/admin/payment-links` | Payment Links | Payment link list and usage | Payment flow |
| 10 | 10-payment-link-create-desktop.png | 10-payment-link-create-mobile.png | `/admin/payment-links/create` | Create Payment Link | Form to create a new payment link | Payment flow |
| 11 | 11-checkout-desktop.png | 11-checkout-mobile.png | `/pay/{slug}` -> `/checkout/{token}` | Checkout | Hosted checkout page rendered for a payment link | Payment flow |
| 12 | 12-customers-desktop.png | 12-customers-mobile.png | `/admin/customers` | Customers | Customer list and management | Dashboard |
| 13 | 13-brands-desktop.png | 13-brands-mobile.png | `/admin/brands` | Brands | Multi-brand (store) management | Self-hosting |
| 14 | 14-gateways-desktop.png | 14-gateways-mobile.png | `/admin/gateways` | Gateways | Payment gateway configuration list | Payment flow |
| 15 | 15-settings-desktop.png | 15-settings-mobile.png | `/admin/settings` | Settings | System settings tabs (general, branding, security, cron, etc.) | Self-hosting |
| 16 | 16-developer-desktop.png | 16-developer-mobile.png | `/admin/developer` | Developer Hub | API keys, endpoint reference, rate limits | Webhooks/API |
| 17 | 17-webhooks-desktop.png | 17-webhooks-mobile.png | `/admin/developer` (Webhooks tab) | Webhooks | Webhook endpoint management and delivery log | Webhooks/API |
| 18 | 18-ledger-desktop.png | 18-ledger-mobile.png | `/admin/ledger` | Ledger | Double-entry ledger view | Transactions |
| 19 | 19-reports-desktop.png | 19-reports-mobile.png | `/admin/reports` | Reports | Financial reports and export | Transactions |
| 20 | 20-disputes-desktop.png | 20-disputes-mobile.png | `/admin/disputes` | Disputes | Dispute list and resolution | Transactions |
| 21 | 21-refunds-desktop.png | 21-refunds-mobile.png | `/admin/refunds` | Refunds | Refund management | Transactions |
| 22 | 22-domains-desktop.png | 22-domains-mobile.png | `/admin/domains` | Domains | Custom domain management and SSL checks | Self-hosting |
| 23 | 23-staff-desktop.png | 23-staff-mobile.png | `/admin/staff` | Staff | Staff user management | Self-hosting |
| 24 | 24-roles-desktop.png | 24-roles-mobile.png | `/admin/roles` | Roles & Permissions | RBAC roles and permissions | Self-hosting |
| 25 | 25-plugins-desktop.png | 25-plugins-mobile.png | `/admin/plugins` | Plugins | Plugin/addon management | Self-hosting |
| 26 | 26-themes-desktop.png | 26-themes-mobile.png | `/admin/themes` | Themes | Theme management and activation | Appearance |
| 27 | 27-appearance-desktop.png | 27-appearance-mobile.png | `/admin/appearance` | Appearance | Brand appearance and theming | Appearance |
| 28 | 28-my-account-desktop.png | 28-my-account-mobile.png | `/admin/my-account` | My Account | Admin profile and 2FA setup | Setup |
| 29 | 29-devices-desktop.png | 29-devices-mobile.png | `/admin/devices` | Paired Devices | Mobile device pairing management | Mobile |
| 30 | 30-sms-center-desktop.png | 30-sms-center-mobile.png | `/admin/sms-center` | SMS Center | SMS template and parsing center | Mobile |
| 31 | 31-activities-desktop.png | 31-activities-mobile.png | `/admin/activities` | Activities | Activity/audit log | Dashboard |
| 32 | 32-audit-integrity-desktop.png | 32-audit-integrity-mobile.png | `/admin/audit-integrity` | Audit Integrity | Audit integrity scan | Self-hosting |
| 33 | 33-login-attempts-desktop.png | 33-login-attempts-mobile.png | `/admin/login-attempts` | Login Security Log | Login attempts and lockouts | Self-hosting |
| 34 | 34-balance-verification-desktop.png | 34-balance-verification-mobile.png | `/admin/balance-verification` | Balance Verification | Ledger balance verification tool | Transactions |
| 35 | 35-system-update-desktop.png | 35-system-update-mobile.png | `/admin/system-update` | System Update | Self-update and version management | Self-hosting |

## Suggested 60-90 second scene order

1. Intro (3s) - 01 landing
2. Problem (5s) - 04 dashboard + 05 transactions (failed/pending)
3. Setup (8s) - 02 login, 28 my-account, 13 brands
4. Dashboard (6s) - 04 dashboard, 31 activities
5. Payment flow (15s) - 09 payment-links, 10 create, 11 checkout, 14 gateways, 08 invoices
6. Transactions (10s) - 05 transactions, 06 detail, 18 ledger, 19 reports, 34 balance-verification
7. Webhooks/API (10s) - 16 developer, 17 webhooks, 15 settings
8. Self-hosting (12s) - 22 domains, 25 plugins, 26 themes, 27 appearance, 33 login-attempts, 35 system-update
9. Call to action (5s) - 01 landing + GitHub link

## Pages not captured (and why)

- Install wizard (`/install`) steps: used to bootstrap the local instance; not a normal post-install user-facing page.
- Email templates (password reset, payment received, refund processed): rendered server-side for email, not browsable pages.
- Error pages (404, 429, 500, 503, maintenance): not part of the standard happy-path flow.
- Checkout status polling endpoint (`/checkout/{token}/status`) and manual verify endpoints: background/AJAX endpoints, not standalone pages.
- Mobile companion app screens: separate Flutter client, not in this web repository.
- Invoice PDF export (`/admin/invoices/{id}/pdf`): file download, not an HTML page.
- 2FA challenge page (`/2fa`) and reset password page (`/reset-password`): require a pending session/token state not available in the seeded demo data.
- Webhook event detail/log pages (`/admin/webhooks/events/{id}/logs`): require a recorded webhook delivery; none seeded.
- Customer/brand/staff detail pages (`/admin/customers/{id}`, `/admin/brands/{id}`, `/admin/staff/{id}`): require specific seeded records; the list views are captured instead.
EOF
echo written