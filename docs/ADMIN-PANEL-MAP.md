# OwnPay Admin Panel — Settings & Feature Map

**Audience:** UI/UX Designers
**Purpose:** Complete map of every setting, feature, and data section in the admin panel — which level it belongs to, who can change it, and UX recommendations.

---

## Understanding the Two Worlds

The OwnPay admin panel operates on **two distinct levels**. A designer must always know which world a user is in.

---

### 🌐 The Global Level (Platform / Installation)

There is **one super-admin** who owns the entire OwnPay installation on one server.

At the global level, the super-admin:
- Configures the server-wide system (SMTP, update engine, maintenance mode, login security)
- Sees and manages **all brands** on this installation
- Controls what features/settings are available across the whole platform
- Is the only person who can create new brands

> **Think of it like:** the back-office of a shopping mall owner — they control the building, utilities, security policies, and which stores are allowed in. Individual stores still decorate their own stalls.

---

### 🏷️ The Brand Level (Store / Merchant)

Each **brand** is an independent store on this installation. A brand has its own:
- Custom domain (`pay.yourbrand.com`)
- Logo, name, and colors
- Payment gateways with separate credentials
- Customers, transactions, invoices, ledger
- Staff team and their roles/permissions

At the brand level, settings are configured either by:
- The **super-admin** switching context into a brand
- **Brand staff members** who have been given a login

> **Think of it like:** a store owner customizing their own stall inside the mall — they pick their own signage, set prices, hire staff, and choose what payment methods to accept.

---

## How Context Switching Works

When the super-admin logs in, they start in **global view**. They can switch into any brand using a "Switch Brand" control. Once inside a brand, all pages show that brand's data only.

Brand staff members are always inside their one brand — they never see the global level or other brands.

---

## Quick Reference: Who Controls What

| Level | Who is there | What they manage |
|:---|:---|:---|
| **Global** | Super-admin only | System, SMTP, all brands, server settings |
| **Brand** | Super-admin (switched in) + brand staff | That brand's gateways, team, domain, customers, settings |
| **Both** | Settings that have a global default but each brand can override | Plugins, some checkout messages |

---

---

# PART 1 — GLOBAL SETTINGS
### (Super-Admin Only — Applies to the Entire Platform)

---

## 1.1 General System Settings

These control how the entire OwnPay installation behaves. Changing any of these affects every brand on this server.

| Setting | What it does |
|:---|:---|
| Application Name | The name of this OwnPay installation (shown in admin tab, emails) |
| Admin Panel Title | Heading text shown in the admin panel header |
| Admin Login URL | The URL path where the login page lives (e.g. `/secure-login` instead of `/login`) — changed for security |
| Maintenance Mode | One switch that takes the entire platform offline (a maintenance page shows for all users) |
| Force HTTPS | Forces all connections to use a secure HTTPS connection |
| Session Timeout | How many minutes of inactivity before an admin is automatically logged out |
| Max Login Attempts | How many wrong password attempts before an account is temporarily locked |
| Require 2FA for All Staff | Forces every staff member on the platform to set up two-factor authentication |
| IP Allowlist | A list of IP addresses allowed to access the admin panel (blocks everyone else) |

---

## 1.2 Email / Notification Settings

These configure the mail server that OwnPay uses to send all emails (password resets, payment receipts, notifications).

| Setting | What it does |
|:---|:---|
| Mail Server (SMTP Host) | The address of the mail server |
| Mail Server Port | The connection port (usually 465 or 587) |
| Encryption Type | SSL or TLS connection mode |
| Username / Password | Mail server credentials |
| From Name | The name that appears in the "From" field of emails |
| From Email Address | The email address that appears in the "From" field |
| Admin Notification Email | Where system alerts are sent (low balance, update available, etc.) |

---

## 1.3 Platform Branding & Appearance

These control the look of the public-facing landing page and the admin panel itself — not individual brand checkout pages (those are controlled per brand).

| Setting | What it does |
|:---|:---|
| Site Logo | Logo shown on the admin login page and landing page |
| Site Favicon | Browser tab icon |
| Primary Color | Main accent color for the admin panel UI |
| Accent Color | Secondary accent color |
| Site SEO Title | Page title shown in browser tabs and search engines |
| Meta Description | Short description for search engines |
| Footer Text | Text at the bottom of the admin panel |
| Brand Tagline | Short marketing tagline on the public landing page |

---

## 1.4 Public Landing Page

Whether OwnPay shows a public home page when someone visits the installation's root URL.

| Setting | What it does |
|:---|:---|
| Landing Page On/Off | Whether the installation shows a public homepage |
| Page Title | Headline text on the landing page |
| Subtitle | Supporting text under the headline |
| Call-to-Action Button Text | Text on the main button (e.g. "Get Started") |
| Call-to-Action URL | Where that button goes |
| Feature Cards | List of key features shown on the landing page (editable) |
| FAQ Section On/Off | Whether to show a FAQ accordion on the landing page |
| Show/Hide the FAQ | Individual FAQ items on the landing page |

---

## 1.5 System-Wide Payment Settings

Default payment behavior across the whole platform. Each brand may adjust some of these for their own context.

| Setting | What it does |
|:---|:---|
| Payment Expiry Time | How many minutes before an unpaid transaction automatically expires |
| Invoice Due Days | Default number of days until an invoice is due (used when creating a new invoice) |
| Checkout Success Message | Default message shown to customers after successful payment |
| Checkout Pending Message | Default message shown when a payment is awaiting confirmation |
| Checkout Failed Message | Default message shown when a payment fails |
| Countdown Timer On/Off | Whether to show a ticking countdown clock on the checkout page |
| Timer Duration | How many seconds to count down |
| Show FAQ on Checkout | Whether to show a FAQ accordion on the checkout page |

---

## 1.6 Currencies & Exchange Rates

Managed globally — all brands draw from the same currency list and rate table.

| Setting | What it does |
|:---|:---|
| Available Currencies | Which world currencies are enabled on this installation (USD, BDT, EUR, etc.) |
| Exchange Rate Mode | Whether rates are set manually or pulled from an external API |
| Exchange Rate Source URL | The URL of the external rate service (if using API mode) |
| Exchange Rate Values | Individual currency pair rates (e.g. 1 USD = 110 BDT) |

---

## 1.7 SMS & Payment Verification (Global Rules)

These are the global keyword rules used to analyse incoming SMS messages when verifying manual payments.

| Setting | What it does |
|:---|:---|
| Positive Keywords | Words in an SMS that indicate a payment was successful (e.g. "received", "credited", "successful") |
| Negative Keywords | Words that indicate a payment failed (e.g. "failed", "declined", "insufficient") |
| SMS Check Interval | How often to re-process unmatched SMS messages |

---

## 1.8 Language Management

| Setting | What it does |
|:---|:---|
| Available Languages | All installed language packs |
| Default Language | The language the admin panel and checkout shows by default |
| Language Status (On/Off) | Which languages are active |
| Translation Editor | Edit any individual text string in any language |
| Import Language File | Upload a new language file (JSON format) |

---

## 1.9 Plugins & Themes (Global Installation)

Plugins are installed at the server level by the super-admin. Once installed, each brand can independently choose to activate or deactivate them.

| Feature | What it does |
|:---|:---|
| Install Plugin | Upload a plugin ZIP — makes it available to all brands |
| Uninstall Plugin | Remove a plugin from the server entirely |
| Plugin Status (Installed / Uninstalled) | Global installation state |
| Plugin List View | Shows all installed plugins with version and description |

> **Note for designer:** Installing a plugin is a global action. Activating a plugin is a per-brand action. These are two separate steps and the UI should make this distinction visually clear.

---

## 1.10 System Update

| Feature | What it does |
|:---|:---|
| Current Version Display | Shows what version is installed |
| Check for Updates | Manually check for a new release |
| Apply Update | Download and install a new version (system enters maintenance during update) |
| Auto-Update Toggle | Turn on automatic updates when a new version is available |
| Update History | Log of every update ever applied |
| Pre-Update Backup Toggle | Whether to create a backup before applying each update |

---

## 1.11 System Health & Maintenance

| Tool | What it does |
|:---|:---|
| Clear Cache | Wipes the application cache (useful after config changes) |
| Optimise Database | Runs database maintenance for better performance |
| Clean Temporary Files | Deletes temporary uploaded files older than 24 hours |
| Log Retention Setting | How many days to keep activity logs (30 / 60 / 90 / 180 days) |
| Cron Secret Key | The secret token used to trigger scheduled background jobs |
| Manual Job Runner | Trigger individual background jobs manually (check DNS, retry webhooks, etc.) |
| Run Full Cron | Trigger all scheduled jobs at once |

---

## 1.12 Audit & Activity Log (Global View)

The super-admin can see all activity across every brand.

| View | What it shows |
|:---|:---|
| Activity Log | Every action taken by any staff member across all brands |
| Audit Integrity Check | Scan the log for any tampered entries (each entry is cryptographically signed) |
| Login Attempts | All login successes and failures across the platform |

---

---

# PART 2 — BRAND SETTINGS
### (Per Brand — Each Brand/Store Controls These Independently)

> Everything in this section is completely isolated. Brand A cannot see Brand B's settings or data.

---

## 2.1 Brand Profile

The basic identity of this brand/store.

| Setting | What it does |
|:---|:---|
| Brand Name | The public-facing name of this store (shown on checkout pages) |
| Brand Slug | Short URL-safe identifier used internally (e.g. `acme-corp`) |
| Contact Email | Primary email for this brand |
| Contact Phone | Contact phone number |
| Timezone | This brand's local timezone (affects date/time display and scheduling) |
| Default Currency | The currency this brand primarily works in |
| Default Language | The language this brand's checkout shows to customers |
| Brand Status | Active / Suspended / Pending — controls whether this brand can accept payments |

---

## 2.2 Brand Appearance

Each brand customises how their checkout pages look. Customers only ever see these — they never see the global OwnPay branding.

| Setting | What it does |
|:---|:---|
| Logo | This brand's logo shown on their checkout page |
| Favicon | Browser tab icon for this brand's checkout |
| Primary Color | Main brand color applied to buttons and highlights on checkout |
| Accent Color | Secondary brand color |
| Custom CSS | Advanced: add custom stylesheet rules to this brand's checkout pages |
| Custom JavaScript | Advanced: add custom scripts to this brand's checkout pages |
| Footer Text | Text shown at the bottom of this brand's checkout pages |
| Support Email | The email customers see on the checkout page when they need help |
| Checkout Success Message | Override the global success message specifically for this brand |
| Checkout Pending Message | Override the global pending message |
| Checkout Failed Message | Override the global failed message |

---

## 2.3 Custom Domain

Each brand can have its own domain so customers land on `pay.yourbrand.com` instead of the OwnPay installation URL.

| Feature | What it does |
|:---|:---|
| Add Domain | Enter a domain name to map to this brand |
| Domain Type | Checkout domain / Admin domain / API domain |
| Primary Domain | Which domain is the main one |
| DNS Verification Status | Confirms the domain is correctly pointed (TXT record verified / A record verified) |
| SSL Status | Whether the domain has a valid HTTPS certificate |
| Remove Domain | Unmap a domain from this brand |

> **Note for designer:** DNS verification is a multi-step process with two separate checks (ownership proof + routing confirmation). The UI should show progress and guide the merchant through each step with plain-English instructions.

---

## 2.4 Payment Gateways

Each brand selects which payment gateways to accept and enters their own API credentials. Two brands can both use Stripe but with completely different Stripe accounts.

### API/Online Gateways
| Setting | What it does |
|:---|:---|
| Gateway Enable/Disable | Turn a specific gateway on or off for this brand |
| API Key / Secret Key | The credentials from the payment provider's dashboard |
| Webhook Secret | Secret key to validate notifications from the payment provider |
| Live Mode / Test (Sandbox) Mode | Switch between real money and test mode |
| Display Order | The order gateways appear on the checkout page |

### Manual / Offline Gateways
These are payment methods that don't go through an API — the customer is instructed to send money manually (bank transfer, mobile money transfer, cash, etc.).

| Setting | What it does |
|:---|:---|
| Gateway Name | Name shown on checkout (e.g. "Bank Transfer – Dutch Bangla") |
| Instructions | Step-by-step instructions shown to the customer |
| Account Details / Reference | Bank account number, mobile number, reference info for the customer |
| Custom Fields | Extra fields to collect from the customer before they pay (e.g. "Enter your bKash number") |
| QR Code | Optional QR code image to show the customer |
| Brand Color | Accent color for this gateway's display card |

---

## 2.5 Fee Rules

Each brand can set their own transaction fee structure, independently of other brands.

| Setting | What it does |
|:---|:---|
| Fee Type | Flat (fixed amount) / Percentage / Tiered (different rates for different amounts) |
| Fee Amount / Rate | The actual fee value |
| Minimum Fee | If the calculated fee is below this amount, charge this minimum instead |
| Maximum Fee | If the calculated fee is above this amount, cap it here |
| Gateway Scope | Apply this fee rule to all gateways, or only specific ones |

---

## 2.6 Team & Roles

Each brand manages its own staff independently. Staff from one brand never have access to another brand.

### Staff Members
| Feature | What it does |
|:---|:---|
| Invite / Create Staff | Add a person to this brand's team with a name, email, and password |
| Assign Role | Give this staff member a role (which controls what they can do) |
| Active / Suspended Toggle | Temporarily block a staff member without deleting them |
| Remove Staff | Remove someone from this brand's team |
| Two-Factor Authentication Status | See if this staff member has 2FA enabled |

### Roles & Permissions
| Feature | What it does |
|:---|:---|
| Create Role | Create a named role (e.g. "Support Agent", "Finance Manager") |
| Assign Permissions | Choose which actions this role can perform (view transactions, issue refunds, manage gateways, etc.) |
| Edit Role | Update name or permissions |
| Delete Role | Remove a role |
| Assign Role to Staff | Link a staff member to a role |

---

## 2.7 API Keys

Each brand generates its own API keys for connecting external apps and services.

| Feature | What it does |
|:---|:---|
| Generate New Key | Create a new API key with a name and scope |
| Key Scope | Read-only / Read+Write / Admin |
| Expiration Date | Optional date after which the key stops working |
| Revoke Key | Immediately deactivate a key |
| Last Used | Shows when this key was last used |

---

## 2.8 Webhooks

Each brand registers external URLs that should receive real-time notifications when payments happen.

| Feature | What it does |
|:---|:---|
| Add Webhook Endpoint | Enter a URL to receive payment events |
| Event Subscriptions | Choose which events to send (payment completed, refund issued, dispute opened, etc.) |
| Signing Secret | A secret used to verify that webhook calls genuinely came from OwnPay |
| Active / Inactive Toggle | Pause a webhook without deleting it |
| Delivery Log | History of every webhook sent — status, response code, timing |
| Retry Failed | Re-send a failed webhook delivery |
| Delete Endpoint | Remove a webhook endpoint |

---

## 2.9 Mobile App & Devices

The OwnPay mobile companion app connects to a brand and allows staff to monitor payments and forward SMS messages for verification.

| Feature | What it does |
|:---|:---|
| Pair New Device | Generate a one-time code to connect a phone or tablet to this brand |
| Device List | All paired devices — name, model, last seen online, status |
| Revoke Device | Disconnect a specific device |
| Revoke All Devices | Disconnect everything at once |
| Device Notifications | Push notification log showing what was sent to which device |

---

## 2.10 SMS Verification Templates

When a manual/offline payment is made, the customer sends money and then a payment app (bKash, Nagad, etc.) sends an SMS to the merchant's phone. OwnPay reads that SMS and automatically confirms the payment.

To teach OwnPay how to read each type of SMS, each brand creates templates.

| Feature | What it does |
|:---|:---|
| Create Template | Add a new SMS parsing template |
| Gateway | Which payment method does this SMS come from (e.g. "bKash", "Nagad") |
| Sender Pattern | The sender ID or phone number pattern (e.g. "+8801700") |
| Message Pattern / Regex | The formula that extracts amount, transaction ID, and sender from the SMS text |
| Test Template | Paste a sample SMS and see if the template extracts it correctly |
| Enable / Disable | Turn this template on or off |
| SMS Log | History of all received and processed SMS messages |

---

## 2.11 Plugin Activation (Per Brand)

Plugins are installed globally but each brand chooses which ones to turn on.

| Feature | What it does |
|:---|:---|
| Plugin List | Shows all installed plugins |
| Activate / Deactivate | Turn a plugin on or off for this specific brand |
| Plugin Settings | If the plugin has configurable settings, they appear here (scoped to this brand) |

---

---

# PART 3 — OPERATIONAL DATA
### (Always Per Brand — Not Settings, Just Live Data)

This is the live activity that flows through a brand. There are no "settings" to configure here — this is the data the brand accumulates over time.

| Section | What's Here |
|:---|:---|
| **Transactions** | Every payment attempted — amount, gateway, status, customer, timestamps |
| **Customers** | Everyone who has ever made a payment through this brand |
| **Invoices** | Invoices created and sent to customers |
| **Payment Links** | Reusable shareable links to accept payment |
| **Disputes / Chargebacks** | Contested payments and their resolution status |
| **Ledger** | The accounting record — every money movement as debits and credits |
| **Refunds** | All refunds issued |
| **Reports** | Filtered views and CSV exports of transactions |
| **Webhook Deliveries** | Log of every outbound event notification sent to the brand's webhook endpoints |
| **Communication Log** | History of every email and SMS sent by this brand |
| **Activity Log** | Every action taken by staff members in this brand |

---

---

# PART 4 — FEATURES THAT SPAN BOTH LEVELS

These are features where the global level sets a foundation and the brand level can adjust within that foundation.

| Feature | Global Level | Brand Level |
|:---|:---|:---|
| **Checkout Messages** | Default success / pending / failed messages for all brands | Each brand can override with their own custom message |
| **Currencies** | Super-admin enables which currencies exist on the platform | Each brand selects their own default currency from the enabled list |
| **Exchange Rates** | Super-admin maintains the rate table | Brands use these rates automatically for currency conversion |
| **Language / i18n** | Super-admin installs and manages translation files | Each brand (and each staff member) can choose which language to display in |
| **Plugins** | Super-admin installs plugins on the server | Each brand activates/deactivates from the installed list and configures their own settings |
| **FAQ on Checkout** | Super-admin controls the FAQ section on/off globally | Each brand can add their own FAQ items to their checkout |

---

---

# PART 5 — ADMIN PANEL NAVIGATION MAP

### Global View Navigation (Super-Admin only sees this)

```
Dashboard
│
├── All Brands              ← Manage, create, suspend brands; switch into a brand
│
├── System Settings
│   ├── General             ← App name, login URL, maintenance, session timeout
│   ├── Email / SMTP        ← Mail server configuration
│   ├── Branding            ← Platform logo, favicon, colors, footer
│   ├── Landing Page        ← Public homepage content
│   ├── Payment Defaults    ← Expiry time, invoice due days
│   ├── Checkout Defaults   ← Success/failed/pending messages, timer
│   ├── Currencies          ← Enable currencies, exchange rates
│   ├── Languages           ← Install, edit, set default language
│   ├── SMS Rules           ← Global keyword rules for SMS verification
│   └── Security            ← 2FA, IP allowlist, login attempts policy
│
├── Plugins & Themes        ← Install / uninstall plugins globally
│
├── System Update           ← Check, apply, schedule updates
│
├── System Health
│   ├── Cache / Optimise
│   ├── Log Retention
│   ├── Cron Jobs           ← Configure and manually trigger background jobs
│   └── Temp File Cleanup
│
└── Activity Log (All Brands)
```

---

### Brand View Navigation (Super-admin switched into brand, or brand staff)

```
Dashboard
│
├── Payments
│   ├── Transactions        ← All payments, filter/search, view detail, manual status change
│   ├── Payment Intents     ← Active checkout sessions
│   └── Disputes            ← Chargebacks and resolutions
│
├── Finance
│   ├── Invoices            ← Create, send, track invoice status
│   ├── Refunds             ← Issue and track refunds
│   ├── Payment Links       ← Create reusable payment URLs
│   └── Ledger              ← Double-entry accounting view
│
├── Customers               ← Customer database for this brand
│
├── Reports                 ← Transaction reports and CSV export
│
├── Gateways
│   ├── Online Gateways     ← API gateways — credentials, live/test mode, on/off
│   └── Manual Gateways     ← Offline methods — bank transfer, cash, mobile money
│
├── Mobile App
│   ├── Paired Devices      ← View, manage, revoke devices
│   ├── SMS Templates       ← Parsing rules for payment SMS
│   └── SMS Log             ← History of processed SMS messages
│
├── Developer
│   ├── API Keys            ← Generate and manage API credentials
│   └── Webhooks            ← Endpoint URLs, event subscriptions, delivery log
│
├── Team
│   ├── Staff Members       ← Add, invite, suspend staff
│   └── Roles & Permissions ← Create roles and assign permissions
│
├── Brand Settings
│   ├── Profile             ← Name, email, phone, timezone, currency, language
│   ├── Appearance          ← Logo, colors, custom CSS/JS, footer, checkout messages
│   ├── Custom Domain       ← Map and verify a custom domain
│   ├── Fee Rules           ← Transaction fee configuration
│   └── Plugins             ← Activate/deactivate installed plugins; plugin settings
│
├── Notifications / Devices ← Mobile push notification log
│
└── Activity Log (This Brand)
```

---

---

# PART 6 — UX RECOMMENDATIONS FOR THE DESIGNER

The following are structural and interaction recommendations based on how OwnPay's data model works. These are observations about current complexity and opportunities to simplify the user experience.

---

## R1 — Make the Two Worlds Visually Distinct

The super-admin switches between "global view" and a "brand view". Currently, these are the same interface — only the data changes. This creates confusion.

**Recommendation:** Use a persistent visual indicator that makes it unmistakably clear which context the user is in:
- Global view → a top bar or sidebar in a **neutral/dark tone** with "Platform Settings" labelling
- Brand view → a top bar or sidebar in **brand primary color** showing the brand name/logo prominently
- The "Switch Brand" control should be obvious and always visible when in brand context

---

## R2 — Separate "Configure" from "Monitor"

The admin panel mixes configuration (settings, gateways, roles) with monitoring (transactions, SMS log, webhook deliveries). Staff members with limited permissions often only need monitoring screens, not configuration.

**Recommendation:** Separate the navigation into two clear modes:
- **Operations** (what's happening now) — Dashboard, Transactions, Customers, Invoices, SMS, Disputes
- **Configuration** (how things are set up) — Gateways, Domains, Team, API Keys, Appearance

Users who only monitor don't need to ever see configuration screens.

---

## R3 — Gateway Setup is the Most Complex Onboarding Step

Setting up payment gateways requires:
1. Logging into the payment provider's dashboard
2. Finding and copying API keys
3. Pasting them into OwnPay
4. Switching to live mode
5. Setting up a webhook in the provider's dashboard pointing back to OwnPay
6. Testing the connection

**Recommendation:** Each gateway should have a dedicated setup guide wizard built into its settings page — showing step-by-step instructions specific to that gateway (with screenshots or links). A "Test Connection" button should verify the credentials before the merchant goes live.

---

## R4 — Custom Domain Verification Needs a Progress Flow

DNS verification has two steps (TXT ownership check + A-record routing check) and can fail for many reasons. Currently this is just status text.

**Recommendation:** Design a stepper/wizard with:
- Step 1: Enter domain
- Step 2: Copy TXT record → verify button → live status check
- Step 3: Confirm A record → verify button → live status check
- Step 4: Active ✅

Show clearly what to do, where to do it (in their domain registrar), and what "verified" looks like.

---

## R5 — SMS Template Setup Needs a "Test" Experience

Setting up SMS parsing templates is the most technical thing a non-technical merchant will encounter. They must write a pattern (regex) to teach OwnPay how to read payment SMS messages.

**Recommendation:** Build a live SMS testing tool directly in the template editor:
- Merchant pastes a real SMS message they received
- They can see in real time what OwnPay would extract from it (amount, sender, transaction ID)
- Highlight the matched parts in colour
- Show a "Match found ✅" / "No match ❌" result clearly

This removes fear from a complex feature.

---

## R6 — Plugin Two-Step Needs Clear Communication

Installing a plugin (global) and activating a plugin per-brand are two separate actions, but they look like one thing. Merchants often think installing a plugin means it's active — it isn't.

**Recommendation:** Show two distinct states:
- Global install list: shows what's on the server (super-admin area)
- Per-brand activation: shows "installed but not active" and "active" separately with one-click toggle

The global install screen and the per-brand activation screen should look visually different.

---

## R7 — Brand Switching Should Feel Like "Opening a Store Door"

When the super-admin switches into a brand, the full context changes. Currently this could feel jarring.

**Recommendation:**
- Show a brief "You are now managing [Brand Name]" toast/modal on switch
- Always show the active brand name in the header while inside a brand context
- "Exit brand" / "Back to Platform" action should always be one click away

---

## R8 — Settings Tabs vs Separate Pages

The current settings section uses tabs (General, Email, Branding, Checkout, etc.). With many settings, tabs can feel overwhelming.

**Recommendation:** Consider a settings sidebar navigation (like VS Code's settings page) where each category is a distinct page. This:
- Allows linking directly to a specific setting
- Scales better as features grow
- Allows different user roles to see only the settings they're allowed to change

---

## R9 — Role Permission Builder Needs Grouping

Permissions are granular — there could be 50+ individual permissions. A role editor that lists them all in a flat list is unusable.

**Recommendation:** Group permissions by section with a toggle to enable/disable the whole group:
- "Payments" (view transactions, issue refunds, export CSV)
- "Customers" (view, edit, delete customers)
- "Gateways" (view credentials, add gateway, edit gateway)
- "Team" (invite staff, assign roles, suspend staff)
- etc.

Show a "Quick preset" for common roles (View Only, Support Agent, Finance Manager, Full Access).

---

## R10 — Onboarding Wizard Should be More Prominent

OwnPay has a setup wizard for first-time setup. Based on the codebase, this covers: settings → create first brand → setup mail → setup gateway → complete.

**Recommendation:** The wizard should:
- Be impossible to miss on first login (full-screen modal or dedicated setup page)
- Show progress visually (step 1 of 5)
- Allow resuming if the user closes it mid-way
- Show a "completion score" on the dashboard until all setup steps are done (e.g. "Setup 60% complete — add a payment gateway")

---

## R11 — Ledger is for Accountants, Dashboard is for Merchants

The ledger section shows double-entry accounting entries (debits and credits). Most merchants won't know what this means.

**Recommendation:**
- Rename the ledger section to "Accounting" or "Finance" for the merchant-facing navigation
- Show a simplified summary view first (total received, total refunded, current balance)
- Put the detailed double-entry view behind an "Advanced / Accountant View" toggle

---

## R12 — Empty States Should Guide Action

Many sections (Gateways, Webhooks, Staff, SMS Templates) start completely empty for a new brand.

**Recommendation:** Every empty state should:
- Clearly explain what this section is for (one sentence)
- Show a primary action button ("Add your first gateway", "Invite a team member")
- Optionally link to the relevant documentation

An empty table with no context is a dead end.

---

---