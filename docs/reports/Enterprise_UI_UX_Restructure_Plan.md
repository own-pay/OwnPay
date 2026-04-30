# OwnPay — Enterprise UI/UX Audit & Restructure Plan

> **Document Type:** Exhaustive Frontend Audit Report & Execution Roadmap
> **Scope:** Admin Panel Core (Dashboard, Transactions, Invoices, Settings, Login, Installer)
> **Excludes:** `app/modules/themes/*` (customer-facing checkout themes)
> **Audit Date:** March 2026
> **Framework:** Custom PHP Framework + Flowbite Admin Dashboard (Tailwind CSS 3.4)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Sub-task 1: Deep Code & DOM Architecture Audit](#2-sub-task-1-deep-code--dom-architecture-audit)
3. [Sub-task 2: Enterprise Visual Design & Pixel-Perfect UI](#3-sub-task-2-enterprise-visual-design--pixel-perfect-ui)
4. [Sub-task 3: Fintech UX, Friction & Cognitive Load Analysis](#4-sub-task-3-fintech-ux-friction--cognitive-load-analysis)
5. [Sub-task 4: The New UI/UX Restructuring Plan](#5-sub-task-4-the-new-uiux-restructuring-plan)
6. [Execution Roadmap](#6-execution-roadmap)
7. [Verification & QA Plan](#7-verification--qa-plan)

---

## 1. Executive Summary

OwnPay's admin frontend is built on a solid foundation (Flowbite + Tailwind CSS with a custom `.op-*` design system). However, **44 specific issues** were identified across the core admin panel that prevent it from meeting enterprise fintech standards. The issues break down as:

| Severity | Count | Examples |
|----------|-------|----------|
| **Critical** | 3 | Monolithic 623-line index.php, N+1 sidebar queries, unparameterized SQL |
| **High** | 8 | Missing CSS class definitions, unpinned CDN, badge color mismatches, non-functional search |
| **Medium** | 15 | Inline styles, duplicated markup, responsive failures, form validation gaps |
| **Low** | 6 | Color contrast fine-tuning, spacing normalization |

The backend (SOA with double-entry ledger, idempotency, multi-gateway engine) is enterprise-grade. The frontend must match this caliber. This report provides a complete restructuring plan organized into a **6-phase execution roadmap** with exact file paths, line numbers, and before/after architectural vision.

---

## 2. Sub-task 1: Deep Code & DOM Architecture Audit

### 2.1 Monolithic File Structure

**File:** `app/admin/index.php` (623 lines)

This single file contains:
- **Navbar** (lines 38-123) — Logo, search, dark mode toggle, user dropdown
- **Sidebar** (lines 128-320) — Brand switcher with DB queries, all navigation items
- **Main content wrapper** (lines 322-348) — Content slot + footer
- **Modal 1: 2FA Verify** (lines 350-381) — Two-step verification dialog
- **Modal 2: Action Confirmation** (lines 383-403) — Generic confirmation dialog
- **Script loading** (lines 405-411) — 6 JS files including CDN scripts
- **JavaScript** (lines 415-619) — Brand switching, URL parsing, SPA navigation, event delegation

**Impact:** Any developer touching the navbar must work in the same file as the sidebar, modals, and all JavaScript. Merge conflicts are inevitable in team environments. Testing any component requires loading the entire 623-line file.

### 2.2 Critical Performance Issues

#### N+1 Query in Brand Dropdown
**File:** `app/admin/index.php`, lines 150-166

```php
// Current: Executes a DB query PER brand inside a foreach loop
$response_permission = json_decode(getData($db_prefix . 'permission', 'WHERE a_id = :a_id AND status = :status AND brand_id != :brand_id', '* FROM', $params_perm), true);
if ($response_permission['status'] == true) {
    foreach ($response_permission['response'] as $row) {
        // THIS QUERY RUNS FOR EVERY BRAND
        $response_brand = json_decode(getData($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params_brand), true);
    }
}
```

**Impact:** With 10 brands = 11 queries (1 permission query + 10 brand queries). With 50 brands = 51 queries. Executes on **every single page load** since this is the admin shell.

**Fix:** Single JOIN query: `SELECT b.* FROM {prefix}permission p JOIN {prefix}brands b ON p.brand_id = b.brand_id WHERE p.a_id = :a_id AND p.status = 'active' AND p.brand_id != :current_brand_id`

#### Full Table Scan for Badge Count
**File:** `app/admin/index.php`, lines 213-220

```php
// Current: Fetches ALL pending transaction rows just to count them
$response_dashboard_info = json_decode(getData($db_prefix . 'transaction', 'WHERE brand_id = :brand_id AND status = :status', '* FROM', $params_trcount), true);
if ($response_dashboard_info['status'] == true) {
    $count = count($response_dashboard_info['response']); // PHP count of all rows
}
```

**Impact:** If a merchant has 10,000 pending transactions, this fetches all 10,000 rows into PHP memory just to count them. Runs on every page load.

**Fix:** `getData($db_prefix . 'transaction', 'WHERE brand_id = :brand_id AND status = :status', 'COUNT(*) as total FROM', $params_trcount)`

#### Unparameterized SQL in Dashboard
**File:** `app/admin/dashboard/dashboard.php`, lines 44, 65, 86-89, 108, 229, 319-342

```php
// Current: String concatenation (legacy pattern)
getData($db_prefix . 'transaction', ' WHERE brand_id = "' . $global_response_brand['response'][0]['brand_id'] . '" AND status = "completed"', 'SUM(amount) as total FROM')

// Should be: Parameterized (same function supports it)
getData($db_prefix . 'transaction', ' WHERE brand_id = :brand_id AND status = :status', 'SUM(amount) as total FROM', [':brand_id' => $brand_id, ':status' => 'completed'])
```

**Impact:** While the data comes from a trusted session variable (not user input), this pattern contradicts the SOA layer's security standards and makes the codebase inconsistent. All SOA repositories use parameterized queries.

### 2.3 DOM Structure Issues

| File | Line(s) | Issue | Fix |
|------|---------|-------|-----|
| `app/admin/index.php` | 267-269 | Sidebar "Automation" section uses `<li>` wrapping `<span>` with inline `style="display:block; margin-bottom:0;"` instead of the `<li class="ap-sidebar-section-title">` pattern used by Overview (184), Payments (205), Operations (246), and Settings (290). | Use same `<li class="ap-sidebar-section-title">Automation</li>` pattern. |
| `app/admin/dashboard/dashboard.php` | 37, 58, 79, 101 | KPI card icon backgrounds use inline `style="background: rgba(99, 102, 241, 0.1);"` and `style="color: #818cf8;"` — repeated 4 times with different colors. | Create `.ap-icon-box` + `.ap-icon-box-primary`, `.ap-icon-box-warning`, `.ap-icon-box-success`, `.ap-icon-box-info` classes. |
| `app/admin/dashboard/dashboard.php` | 126, 273, 284, 295, 306 | Hardcoded `style="color: rgba(148,163,184,0.5);"` — 5 occurrences. | Create Tailwind utility: `text-muted` or use `text-gray-400 dark:text-gray-500`. |
| `app/admin/dashboard/dashboard.php` | 136, 177 | Filter dropdown containers use inline `style="background: rgba(30,41,59,0.95); border: 1px solid rgba(255,255,255,0.08); backdrop-filter: blur(16px);"` — hardcoded dark-only styles. | Use `.ap-dropdown` or `bg-white dark:bg-slate-800/95 border border-gray-200 dark:border-white/8 backdrop-blur-xl`. |
| `app/admin/dashboard/invoice/create.php` | 118, 125, 132, 139, 250-258 | Currency prepend input group markup duplicated 8 times. Full class string: `inline-flex items-center px-3 text-sm text-gray-500 bg-gray-100 border border-e-0 border-gray-300 rounded-s-lg dark:bg-gray-600 dark:text-gray-400 dark:border-gray-600` | Create `.ap-input-group-text` component class in source.css. |
| `app/admin/dashboard/transaction/index.php` | 265-288 | 24-line HTML template literal in JavaScript with no HTML escaping. If `item.name`, `item.email`, or `item.gateway` contain HTML entities, they render as raw HTML (potential XSS). | Escape all interpolated values: `escapeHtml(item.name)` using a utility function. |

### 2.4 Tailwind CSS & Flowbite Issues

#### Dual Light-Mode System
**File:** `assets/css/source.css`, lines 385-557

The design system uses **two competing approaches** for light/dark mode:

1. **Tailwind `dark:` prefix** — Used inline in HTML:
   ```html
   <div class="bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
   ```

2. **`html:not(.dark)` CSS overrides** — Used in source.css for every `.op-*` component:
   ```css
   html:not(.dark) .ap-card { background: #ffffff; border: 1px solid #e2e8f0; }
   html:not(.dark) .ap-card-header { border-bottom: 1px solid #e2e8f0; }
   html:not(.dark) .ap-card-title { color: #0f172a; }
   /* ... 175 lines of overrides ... */
   ```

**Impact:** Maintaining two systems means every component change requires updates in two places. Specificity conflicts are possible when both systems target the same element.

**Additionally:** Lines 643-663 use `:root:not(.dark)` while lines 385-557 use `html:not(.dark)` — two different CSS selectors for the same purpose.

#### Missing CSS Class Definition
**File:** `app/admin/dashboard/transaction/index.php`, line 24

```html
<button class="ap-tab active" data-type="all">All</button>
```

The `.ap-tab` class is **never defined** in `assets/css/source.css`. The tabs rely on whatever Tailwind utilities happen to be in the class name (none — "ap-tab" is a custom class) plus browser defaults for `<button>`. The `.active` variant is also undefined.

**Impact:** Status tabs on the transaction page have no custom styling. They render as unstyled buttons.

#### Badge Class Duplication
**File:** `assets/css/source.css`, lines 282-314

```css
/* Base badge exists but is disconnected from variants */
.ap-badge { @apply text-xs font-semibold px-2.5 py-0.5 rounded-full; }

/* Each variant REPEATS the base styles */
.ap-badge-success { @apply text-xs font-semibold px-2.5 py-0.5 rounded-full; background: ...; color: ...; }
.ap-badge-danger  { @apply text-xs font-semibold px-2.5 py-0.5 rounded-full; background: ...; color: ...; }
.ap-badge-warning { @apply text-xs font-semibold px-2.5 py-0.5 rounded-full; background: ...; color: ...; }
.ap-badge-info    { @apply text-xs font-semibold px-2.5 py-0.5 rounded-full; background: ...; color: ...; }
.ap-badge-primary { @apply text-xs font-semibold px-2.5 py-0.5 rounded-full; background: ...; color: ...; }
```

**Fix:** Variants should use the base: `<span class="ap-badge ap-badge-success">` where `.ap-badge-success` only adds color.

#### Unpinned CDN Version
**File:** `app/admin/index.php`, line 408

```html
<script src="https://cdn.jsdelivr.net/npm/apexcharts@latest/dist/apexcharts.min.js"></script>
```

**Impact:** `@latest` resolves to whatever version is current at load time. A breaking change in ApexCharts could break all dashboard charts in production without any code change.

**Fix:** Pin to specific version: `apexcharts@3.49.0` (or whatever is currently tested).

#### All Scripts Loaded Globally
**File:** `app/admin/index.php`, lines 406-411

Every page load pulls in:
- `flowbite.min.js` (135KB) — Needed everywhere
- `app.js` (20KB) — Needed everywhere
- `apexcharts.min.js` (539KB) — Only needed on Dashboard + Reports
- `choices.min.js` (75KB) — Only needed on pages with select dropdowns
- `hugerte.min.js` (~200KB) — Only needed on Invoice create/edit
- `qrcode.min.js` (19KB) — Only needed on Payment Link pages

**Impact:** ~833KB of JavaScript loaded on every page, even when only ~155KB is needed.

### 2.5 Responsive Design Failures

| Area | File:Line | Issue | Impact |
|------|-----------|-------|--------|
| **Navbar search** | `index.php:57` | `hidden lg:flex` — No search on screens < 1024px | Users on iPad (768px) and phones cannot search |
| **Transaction table** | `transaction/index.php:88-104` | 9-column table with only `overflow-x-auto`, no responsive strategy | On 375px screens, ~6 columns are off-screen with no scroll indicator |
| **Invoice form sidebar** | `invoice/create.php:34` | `lg:grid-cols-3` — Total sidebar goes below form on mobile | User must scroll past all items to see running total |
| **Settings hub tabs** | `brand-setting/index.php:17-57` | `lg:w-56` sidebar — on mobile, 6 buttons stack vertically (~240px) before content | Wastes first viewport on navigation, not content |
| **Bulk action button** | `transaction/index.php:79` | Small inline button in cramped filter row | Touch target too small on mobile |

---

## 3. Sub-task 2: Enterprise Visual Design & Pixel-Perfect UI

### 3.1 Spacing & Alignment Inconsistencies

| Component | File:Line | Current | Standard | Issue |
|-----------|-----------|---------|----------|-------|
| Card body padding | `source.css:130` | `p-5` (20px) | — | OK for card bodies |
| Modal body padding | `index.php:360` | `p-4` (16px) | `p-4 md:p-6` | Should increase on larger screens |
| Sidebar section title spacing | `source.css:63` | `pt-5 pb-1` (20px/4px) | `pt-6 pb-2` | Top-heavy, bottom too tight |
| Table header vs data padding | `source.css:262, 267` | Both `px-4 py-3` | Header: `py-2.5`, Data: `py-3` | Headers should be denser than data rows |
| Footer margin | `index.php:331` | `mt-8 mb-4` | `mt-auto mb-4` | Footer should push to bottom of viewport |
| Dashboard grid gap | `dashboard.php:32` | `gap-4` (16px) | `gap-4` | Consistent with 4-point grid — OK |
| Settings layout gap | `brand-setting/index.php:14` | `gap-6` (24px) | `gap-6` | Wider gap for settings — OK |

### 3.2 Typography & Financial Data

| Issue | File:Line | Current | Required |
|-------|-----------|---------|----------|
| **No tabular figures** | `source.css:106` (`.ap-stat-value`) | `@apply text-2xl font-bold text-white;` | Add `font-variant-numeric: tabular-nums;` — Ensures `$1,234.56` aligns with `$9,876.54` in columns |
| **Currency hardcoded** | `dashboard.php:48` | `echo '$' . number_format($total_revenue, 0);` | `echo $global_brand_currency_symbol . number_format($total_revenue, 2);` |
| **Inconsistent decimals** | `dashboard.php:48` vs `dashboard.php:244` | Revenue: `$0` (0 dp), Table: `$0.00` (2 dp) | Always 2 decimal places for all monetary values |
| **Redundant page title** | `transaction/index.php:16-17` | Pretitle: "Transactions", Title: "Transactions" | Pretitle: "Payments", Title: "Transactions" (or remove pretitle) |
| **Generic greeting** | `dashboard.php:17` | "Welcome back, here's what's happening today." | "Welcome back, {first_name}. Here's today's activity." |
| **Transaction IDs** | Transaction table cells | Inter font (proportional) | Add `font-mono` class for transaction ID column — fixed-width characters align better |

### 3.3 Color & Contrast

#### Badge Color Map — Current vs. Standard

| Status | Dashboard Table (`dashboard.php:233`) | Transaction List JS (`transaction/index.php:257`) | Fintech Standard | Fix |
|--------|---------------------------------------|---------------------------------------------------|------------------|-----|
| Completed | `ap-badge-success` (green) | `ap-badge-primary` (indigo) | Green | Change JS to `ap-badge-success` |
| Pending | `ap-badge-warning` (amber) | `ap-badge-warning` (amber) | Amber | OK |
| Refunded | — | `ap-badge-warning` (amber) | Blue | Change to `ap-badge-info` |
| Failed | `ap-badge-danger` (red) | — | Red | OK |
| Canceled | — | `ap-badge-danger` (red) | Red | OK |
| Expired | `ap-badge-info` (blue) | — | Gray | Consider `ap-badge` (neutral) |

#### Light-Mode Contrast

| Element | CSS Selector | Current Color | WCAG AA (4.5:1) | Recommendation |
|---------|-------------|---------------|-----------------|----------------|
| `.ap-label` (light) | `html:not(.dark) .ap-label` | `#374151` (gray-700) | Passes (7.4:1) | Consider `#1f2937` (gray-800) for labels next to financial data |
| `.ap-stat-title` (light) | `html:not(.dark) .ap-stat-title` | `#64748b` (gray-500) | Passes (4.6:1) | Consider `#475569` (gray-600) for better hierarchy |
| Filter dropdown | Inline style | `rgba(30,41,59,0.95)` (dark only) | Fails in light mode | Replace with `bg-white dark:bg-slate-800/95` |

---

## 4. Sub-task 3: Fintech UX, Friction & Cognitive Load Analysis

### 4.1 User Journey Analysis

#### Journey 1: New Merchant Onboarding
```
Login → Dashboard
         ↓
    KPI Cards: $0 | 0 | 0% | 0
    Charts: Empty (no data)
    Recent Transactions: "No transactions yet"
    Quick Actions: Generic links
         ↓
    User thinks: "Now what? What do I do first?"
         ↓
    NO ONBOARDING. NO GUIDED SETUP. NO "GET STARTED" CTA.
```

**Impact:** High churn risk. First impression is a wall of zeros. Enterprise competitors (Stripe, Razorpay) show setup wizards with progress indicators.

**Fix:** When total transactions = 0, replace the KPI cards + charts section with an onboarding card:
```
Get Started with OwnPay
─────────────────────────────
[x] Create your account        ← Already done
[ ] Set up a payment gateway   → Configure Gateways
[ ] Create your first invoice  → Create Invoice
[ ] Share a payment link       → Create Payment Link
[ ] Test a payment             → View Documentation
```

#### Journey 2: Admin Bulk Operations
```
Select checkboxes (click 1-N)
    → Click "Actions" button (click N+1)
        → Modal opens: select action from dropdown (click N+2)
            → Click "Confirm" (click N+3)
                → 2FA modal opens (click N+4)
                    → Enter code + click "Confirm" (click N+5)
```

**5 interaction steps** for a bulk operation. Stripe does this in 3 (select → action dropdown → confirm).

**Fix:** 2FA verification should only trigger for destructive actions (delete, refund), not approvals. Combine action selection with confirmation into a single step where possible.

#### Journey 3: Staff with Limited Permissions
```
Sidebar rendered:
    Overview          ← Section title (visible)
      Dashboard       ← hidden (no permission)
      Reports         ← hidden (no permission)
    Payments          ← Section title (visible, EMPTY)
      Transactions    ← visible (has permission)
      Invoices        ← hidden
      Payment Links   ← hidden
    Operations        ← Section title (visible, EMPTY)
      Gateways        ← hidden
      Customers       ← hidden
    Automation        ← Section title (visible, EMPTY)
      SMS Data        ← hidden
      Devices         ← hidden
    Settings          ← Section title (visible, EMPTY)
      Settings        ← hidden
      Brands          ← hidden
      Activity Log    ← visible
```

**Result:** 5 section headings, 3 of them with zero items underneath = visual noise.

**Fix:** Dynamically hide section title `<li>` elements when all items in that section have the `.hidden` class. JavaScript check on DOM ready.

#### Journey 4: Non-functional Search Bar
**File:** `app/admin/index.php`, lines 60-61

```html
<input type="text" placeholder="Search transactions, customers, settings..."
    class="pl-10 pr-4 py-2 w-80 text-sm rounded-lg ...">
```

This search input has **no JavaScript event listener**. It's a purely visual element that promises functionality but delivers nothing. Users will type, press Enter, and nothing happens.

**Impact:** High. Search is a fundamental navigation pattern. A broken search is worse than no search — it actively frustrates users.

### 4.2 Form & Data Entry Issues

| Issue | File:Line | Details | Severity |
|-------|-----------|---------|----------|
| **No inline validation** | `invoice/create.php` (all) | Form uses only `required` HTML attribute. On submit failure, single toast with generic message. No field-level error highlighting via `.ap-input-error`. User cannot identify which field failed. | High |
| **Hardcoded currency in JS** | `invoice/create.php:250` | Dynamic item template uses `<span class="...currency-code">USD</span>` — hardcoded "USD" instead of selected currency. `FNcurrency()` corrects it after DOM append, causing a visible flash of wrong currency. | Medium |
| **Password toggle no feedback** | `login.php:69-73` | Eye icon SVG doesn't change between show/hide states. Same icon regardless of `type="password"` vs `type="text"`. User can't tell if toggle worked. | Medium |
| **Non-numeric entries input** | `transaction/index.php:73` | `<input type="text" class="ap-input w-16 text-center show_limit" value="8">` — Allows typing "abc", "!@#", etc. No input validation. AJAX sends garbage. | Medium |
| **CSP nonce missing** | `brand-setting/index.php:153` | `<script>` tag lacks `nonce="<?= $csp_nonce ?? '' ?>"`. In strict CSP mode, this script block will be blocked by the browser. | High |
| **Demo credentials** | `login.php:57, 68` | `value="demo@OwnPay.com"` / `value="12345678"` when `$op_demo_mode` is set. If the flag is accidentally enabled in production, credentials are pre-filled in the login form. | Low (config-dependent) |

### 4.3 Missing UI States

| State | Where Missing | Current Behavior | Required |
|-------|---------------|------------------|----------|
| **Zero-data onboarding** | `dashboard.php` when 0 transactions | Shows `$0` / `0` / `0%` / `0` KPI cards with flat sparklines + empty charts. No guidance. | Show setup wizard/checklist card instead of zero KPI cards. |
| **Loading skeleton for KPI** | `dashboard.php` KPI cards | Server-rendered with final values. No transition. Chart containers show empty `<div>` until JS renders. | Add shimmer skeleton that transitions to real data. Or at minimum, a `min-height` on chart containers to prevent layout shift. |
| **Contextual errors** | All AJAX handlers (`apFetch` catch blocks) | Generic: `"Something Wrong! For further assistance, please contact our support team."` regardless of error type. | Differentiate: Network error ("Connection failed"), Auth error ("Session expired"), Validation error (field-specific), Rate limit ("Too many requests"). |
| **Offline detection** | `load_content()` in `index.php:520-523` | `catch(error) { hideProgress(); console.error('Error:', error); }` — No user-visible feedback. | Show inline banner: "Connection lost. Check your network and try again." with retry button. |
| **Empty filter results** | `transaction/index.php` | Shows generic "No Transactions Found" empty state | Show filter-aware message: "No transactions match your filters." with "Clear all filters" CTA. |
| **Session expiry** | All SPA AJAX calls | After long idle, requests fail with 401. No redirect. User sees nothing or generic error. | Global 401 interceptor in `apFetch` → show "Session expired" modal → redirect to login. |

---

## 5. Sub-task 4: The New UI/UX Restructuring Plan

### 5.1 Before vs. After — Frontend Architecture

#### BEFORE (Current State)
```
app/admin/
├── index.php              ← 623 lines: navbar + sidebar + footer + 2 modals + brand
│                             switcher with DB queries + 200 lines of JavaScript
├── login.php              ← 142 lines: standalone HTML page
├── forgot.php             ← standalone HTML page
├── 2fa.php                ← standalone HTML page
└── dashboard/
    ├── dashboard.php       ← 527 lines: PHP queries + HTML + inline JS charts
    ├── activities.php
    ├── customers.php
    ├── my-account.php
    ├── reports.php
    ├── sms-data.php
    ├── transaction/
    │   ├── index.php       ← 351 lines: HTML + inline JS table logic
    │   └── edit.php
    ├── invoice/
    │   ├── index.php
    │   ├── create.php      ← 309 lines: HTML + inline JS form + calculations
    │   └── edit.php
    ├── payment-link/
    │   ├── index.php
    │   ├── create.php
    │   └── edit.php
    ├── gateways/
    │   ├── index.php
    │   ├── edit.php
    │   └── create-bank.php
    ├── brands/
    │   ├── index.php
    │   ├── create.php
    │   └── edit.php
    ├── devices/
    │   ├── index.php
    │   └── balance-verification.php
    ├── domains/
    │   └── index.php
    ├── staff-management/
    │   ├── index.php
    │   ├── create.php
    │   ├── edit.php
    │   ├── edit-permissions.php
    │   └── permissions-list.php
    ├── addons/
    │   ├── index.php
    │   └── edit.php
    ├── brand-setting/
    │   ├── index.php       ← 171 lines: settings hub with tab cards
    │   ├── general-setting.php
    │   ├── themes-setting.php
    │   ├── themes.php
    │   ├── api-setting.php
    │   ├── currency-setting.php
    │   └── faq-setting.php
    └── system-settings/
        ├── index.php
        ├── geneal.php      ← typo in filename (should be "general")
        ├── cron-job.php
        ├── import.php
        └── update.php

assets/
├── css/
│   ├── source.css          ← 665 lines: Tailwind source with all components +
│   │                         175 lines of html:not(.dark) overrides
│   ├── admin.css           ← Compiled (65KB minified)
│   └── choices.min.css
└── js/
    ├── app.js              ← 20KB: toast, theme, modal, select, clipboard, sidebar
    ├── ap-fetch.js         ← 5.7KB: fetch wrapper, CSRF, loading states
    ├── custom-toast.js     ← 2.5KB: LEGACY (superseded by APToast in app.js)
    ├── flowbite.min.js     ← 135KB
    ├── apexcharts.min.js   ← 539KB
    ├── choices.min.js      ← 75KB
    └── qrcode.min.js       ← 19KB
```

**Problems:**
1. Monolithic `index.php` — all shell concerns in one 623-line file
2. Dashboard views mix PHP queries, HTML, and 200+ lines of inline JS
3. No reusable PHP components — every page reimplements stat cards, tables, input groups, page headers
4. Duplicate markup patterns (currency prepend × 8, inline styles × 20+)
5. Legacy `custom-toast.js` still in the asset directory
6. All JS libraries loaded on every page regardless of need

#### AFTER (Proposed Structure)
```
app/admin/
├── index.php              ← < 200 lines: HTML shell only
│                             Includes layouts/* and loads scripts
├── layouts/
│   ├── _navbar.php        ← Extracted navbar (~50 lines)
│   ├── _sidebar.php       ← Extracted sidebar with smart empty-section hiding (~80 lines)
│   │                         Single JOIN query for brands (replaces N+1)
│   │                         COUNT(*) for pending badge (replaces full scan)
│   ├── _footer.php        ← Extracted footer (~15 lines)
│   └── _modals.php        ← Global modals: 2FA verify + action confirmation (~60 lines)
├── components/
│   ├── _page-header.php   ← function ap_page_header($pretitle, $title, $breadcrumbs = [], $actions = [])
│   ├── _stat-card.php     ← function ap_stat_card($title, $value, $icon, $colorTheme, $chartId)
│   ├── _data-table.php    ← function ap_data_table($columns, $emptyIcon, $emptyTitle, $emptyDesc)
│   │                         Built-in: skeleton loading, empty state, filter bar,
│   │                         pagination, checkbox bulk actions
│   ├── _empty-state.php   ← function ap_empty_state($icon, $title, $desc, $ctaLabel, $ctaUrl)
│   ├── _input-group.php   ← function ap_input_group($label, $prepend, $inputName, $attrs = [])
│   │                         Replaces 8× duplicated currency prepend markup
│   └── _onboarding.php    ← function ap_onboarding_card($steps)
│                             Shown on dashboard when merchant has zero transactions
├── auth/
│   ├── login.php          ← Cleaned: proper password toggle, demo mode review
│   ├── forgot.php
│   └── 2fa.php
└── dashboard/
    ├── dashboard.php       ← < 150 lines: uses _stat-card, _data-table, _onboarding
    ├── transaction/
    │   ├── index.php       ← < 150 lines: uses _data-table, _page-header
    │   └── edit.php
    ├── invoice/
    │   ├── create.php      ← < 150 lines: uses _input-group, _page-header
    │   └── ...
    └── ...                 ← All other dashboard pages follow same pattern

assets/
├── css/
│   ├── source.css          ← Consolidated: dark: prefix pattern, no html:not(.dark)
│   │                         New components: .ap-tab, .ap-input-group-text, .ap-icon-box
│   │                         Financial utilities: tabular-nums
│   └── admin.css           ← Rebuilt
└── js/
    ├── app.js              ← + mobile search, smart sidebar, session expiry
    ├── ap-fetch.js         ← + global 401 handler, contextual error messages
    ├── flowbite.min.js
    ├── apexcharts.min.js   ← Pinned version, loaded only on dashboard/reports
    ├── choices.min.js      ← Loaded only on pages with selects
    └── qrcode.min.js       ← Loaded only on payment-link pages
    (custom-toast.js REMOVED — superseded by APToast)
```

### 5.2 CSS Architecture Changes

#### Current source.css (665 lines) → Proposed Restructure

**Change 1: Eliminate `html:not(.dark)` Override Block**

Current (175 lines, source.css:385-557):
```css
/* Every component has a dark-first definition + light override */
.ap-card {
    background: rgba(30, 41, 59, 0.5);    /* dark default */
    border: 1px solid rgba(255,255,255,0.06);
}
html:not(.dark) .ap-card {
    background: #ffffff;                    /* light override */
    border: 1px solid #e2e8f0;
}
```

Proposed (unified):
```css
.ap-card {
    @apply rounded-xl overflow-hidden
           bg-white border border-gray-200
           dark:bg-slate-800/50 dark:border-white/[0.06];
    @apply dark:backdrop-blur-xl;
}
```

**Impact:** Eliminates 175 lines. Each component is self-contained with both modes.

**Change 2: New Component Classes**

```css
/* Tab pills (missing — used in transaction/index.php) */
.ap-tab {
    @apply px-4 py-2 text-sm font-medium rounded-lg transition-all duration-200 cursor-pointer
           text-gray-500 hover:text-gray-900 hover:bg-gray-100
           dark:text-gray-400 dark:hover:text-white dark:hover:bg-white/5;
}
.ap-tab.active {
    @apply text-white bg-primary-600 dark:bg-primary-500 shadow-sm;
}

/* Input group text (replaces 8× duplicated markup) */
.ap-input-group-text {
    @apply inline-flex items-center px-3 text-sm rounded-s-lg border border-e-0
           text-gray-500 bg-gray-100 border-gray-300
           dark:text-gray-400 dark:bg-gray-600 dark:border-gray-600;
}

/* Icon box (replaces inline styles on KPI card icons) */
.ap-icon-box {
    @apply w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0;
}
.ap-icon-box-primary { @apply bg-primary-500/10 text-primary-400; }
.ap-icon-box-warning { @apply bg-amber-500/10 text-amber-400; }
.ap-icon-box-success { @apply bg-emerald-500/10 text-emerald-400; }
.ap-icon-box-info    { @apply bg-blue-500/10 text-blue-400; }

/* Financial typography */
.ap-stat-value {
    @apply text-2xl font-bold text-gray-900 dark:text-white;
    font-variant-numeric: tabular-nums;
}
```

**Change 3: Normalize Badge Variants**

```css
/* Base badge (shared styles defined once) */
.ap-badge {
    @apply text-xs font-semibold px-2.5 py-0.5 rounded-full;
}
/* Variants only add color */
.ap-badge-success { @apply ap-badge bg-emerald-500/12 text-emerald-400 dark:text-emerald-300; }
.ap-badge-danger  { @apply ap-badge bg-red-500/12 text-red-400 dark:text-red-300; }
.ap-badge-warning { @apply ap-badge bg-amber-500/12 text-amber-400 dark:text-amber-300; }
.ap-badge-info    { @apply ap-badge bg-blue-500/12 text-blue-400 dark:text-blue-300; }
.ap-badge-primary { @apply ap-badge bg-primary-500/12 text-primary-400 dark:text-primary-300; }
```

### 5.3 Progressive Disclosure Strategy

| Complex Backend Feature | User-Facing UI | Disclosure Level |
|------------------------|----------------|------------------|
| **Multi-gateway SMS/Email engine** | Settings → Automation tab → Simple on/off toggles per gateway. "Advanced" expandable section for regex patterns, retry logic. | Default: collapsed. Shows only active/inactive status. |
| **Double-entry ledger** | Dashboard → "Financial Summary" card with Revenue / Fees / Net breakdown. No ledger terminology exposed. | Default: summary only. Link to detailed Reports page for drill-down. |
| **Staff permissions (granular matrix)** | Settings → Team & Access → Role presets as clickable cards (Admin, Manager, Operator, Viewer). "Custom permissions" toggle reveals full matrix. | Default: preset selection. Custom = advanced users only. |
| **API keys + webhooks** | Settings → API & Security → Step-by-step wizard: (1) Generate API key → (2) Set callback URL → (3) Test with sample payload. Copy-pasteable curl examples. | Default: guided wizard. "Manual configuration" for developers. |
| **50+ payment gateways** | Gateways page → Search bar + filter by type (MFS/Bank/Global). "Popular" section at top with most-used gateways. Each card shows setup progress (0/3 fields filled). | Default: popular first. Full list available via search. |
| **Cron jobs & system settings** | Settings → System tab → Plain-English descriptions ("Check for new payments every 5 minutes") instead of cron expressions. | Default: human-readable. Technical details in tooltip on hover. |

---

## 6. Execution Roadmap

### Phase 1: Foundation & Architecture
**Priority:** Critical — Do first. All other phases depend on this.

| # | Task | Files | Effort |
|---|------|-------|--------|
| 1.1 | Extract navbar from `index.php` → `layouts/_navbar.php` | `app/admin/index.php`, new `app/admin/layouts/_navbar.php` | Small |
| 1.2 | Extract sidebar from `index.php` → `layouts/_sidebar.php`. Fix N+1 brand query (single JOIN). Fix pending count (`COUNT(*)`). Add smart empty-section hiding. | `app/admin/index.php`, new `app/admin/layouts/_sidebar.php` | Medium |
| 1.3 | Extract footer → `layouts/_footer.php` | `app/admin/index.php`, new `app/admin/layouts/_footer.php` | Small |
| 1.4 | Extract modals → `layouts/_modals.php` | `app/admin/index.php`, new `app/admin/layouts/_modals.php` | Small |
| 1.5 | Consolidate CSS light/dark mode — Replace `html:not(.dark)` overrides with Tailwind `dark:` prefix in base component definitions | `assets/css/source.css` | Large |
| 1.6 | Define missing `.ap-tab` component class | `assets/css/source.css` | Small |
| 1.7 | Pin CDN versions — `apexcharts@3.49.0`, etc. | `app/admin/index.php` | Small |
| 1.8 | Add CSP nonce to settings tab script | `app/admin/dashboard/brand-setting/index.php:153` | Tiny |
| 1.9 | Remove legacy `custom-toast.js` (superseded by APToast in app.js) | `assets/js/custom-toast.js` | Tiny |

### Phase 2: Reusable Components
**Priority:** High — Creates the building blocks for all subsequent page refactors.

| # | Task | Files | Effort |
|---|------|-------|--------|
| 2.1 | Create `components/_stat-card.php` — Accepts: title, value, icon SVG, color theme, chart ID. Eliminates inline styles from KPI cards. | New `app/admin/components/_stat-card.php` | Small |
| 2.2 | Create `components/_input-group.php` — Accepts: label, prepend text, input name, attributes. Replaces 8× duplicated currency prepend. | New `app/admin/components/_input-group.php` | Small |
| 2.3 | Create `components/_page-header.php` — Accepts: pretitle, title, breadcrumbs array, action buttons array. | New `app/admin/components/_page-header.php` | Small |
| 2.4 | Create `components/_data-table.php` — Standardized table wrapper with skeleton loading, empty state, filter bar, pagination placeholder, bulk action support. | New `app/admin/components/_data-table.php` | Medium |
| 2.5 | Create `components/_empty-state.php` — Accepts: icon, title, description, CTA label, CTA action. | New `app/admin/components/_empty-state.php` | Small |
| 2.6 | Add `.ap-input-group-text`, `.ap-icon-box` variants, `.ap-icon-btn` to source.css | `assets/css/source.css` | Small |
| 2.7 | Refactor badge CSS — Base `.ap-badge` + color-only variants | `assets/css/source.css` | Small |

### Phase 3: Data Display & Financial Formatting
**Priority:** High — Directly impacts trust and professionalism.

| # | Task | Files | Effort |
|---|------|-------|--------|
| 3.1 | Add `font-variant-numeric: tabular-nums` to `.ap-stat-value` and financial table cells | `assets/css/source.css` | Tiny |
| 3.2 | Fix hardcoded `$` currency — Use brand-configured symbol | `app/admin/dashboard/dashboard.php:48` | Tiny |
| 3.3 | Standardize 2 decimal places for all monetary values | `app/admin/dashboard/dashboard.php:48, 69, 91, 112` | Small |
| 3.4 | Fix badge color consistency — completed=success, pending=warning, refunded=info, failed=danger | `app/admin/dashboard/dashboard.php`, `app/admin/dashboard/transaction/index.php` | Small |
| 3.5 | Replace inline dark styles on filter dropdowns with proper light/dark classes | `app/admin/dashboard/dashboard.php:136, 177` | Small |
| 3.6 | Parameterize SQL queries in dashboard views | `app/admin/dashboard/dashboard.php:44, 65, 86-89, 108, 229, 319-342` | Medium |
| 3.7 | Refactor dashboard.php to use `_stat-card.php` and `_page-header.php` | `app/admin/dashboard/dashboard.php` | Medium |

### Phase 4: Forms & Validation
**Priority:** Medium — Improves data quality and user confidence.

| # | Task | Files | Effort |
|---|------|-------|--------|
| 4.1 | Add `.ap-input-error` state + error message element pattern to forms | `assets/css/source.css`, `assets/js/app.js` | Medium |
| 4.2 | Fix password toggle — Add eye-slash SVG that swaps on toggle | `app/admin/login.php:69-73, 94-97` | Small |
| 4.3 | Fix entries input — `type="number" min="1" max="100"` | `app/admin/dashboard/transaction/index.php:73` | Tiny |
| 4.4 | Fix invoice currency in JS template — Use dynamic currency variable | `app/admin/dashboard/invoice/create.php:250` | Tiny |
| 4.5 | Add HTML escaping utility for JS template literals | `assets/js/app.js` or `assets/js/ap-fetch.js` | Small |
| 4.6 | Refactor invoice/create.php to use `_input-group.php` and `_page-header.php` | `app/admin/dashboard/invoice/create.php` | Medium |

### Phase 5: UX & User Journeys
**Priority:** Medium — Transforms the platform from functional to delightful.

| # | Task | Files | Effort |
|---|------|-------|--------|
| 5.1 | Create `_onboarding.php` component for zero-data dashboard | New `app/admin/components/_onboarding.php`, `dashboard.php` | Medium |
| 5.2 | Add mobile search — Expandable search icon for < 1024px | `app/admin/layouts/_navbar.php`, `assets/js/app.js` | Medium |
| 5.3 | Wire up search functionality — Add event listener to search input | `assets/js/app.js`, `app/admin/layouts/_navbar.php` | Medium |
| 5.4 | Smart sidebar sections — Hide section titles when all items are hidden | `assets/js/app.js` | Small |
| 5.5 | Contextual error messages in `apFetch` — Network vs validation vs auth | `assets/js/ap-fetch.js` | Medium |
| 5.6 | Session expiry detection — Global 401 handler → login redirect | `assets/js/ap-fetch.js` | Small |
| 5.7 | Add "Personalized greeting" with user's first name on dashboard | `app/admin/dashboard/dashboard.php:17` | Tiny |
| 5.8 | Fix sidebar "Automation" section title DOM structure | `app/admin/index.php:267-269` (or `layouts/_sidebar.php`) | Tiny |

### Phase 6: Responsive & Accessibility
**Priority:** Final polish — Enterprise-grade on every device and for every user.

| # | Task | Files | Effort |
|---|------|-------|--------|
| 6.1 | Responsive transaction table — Card layout on mobile or priority columns | `app/admin/dashboard/transaction/index.php` | Large |
| 6.2 | Mobile invoice total — Sticky summary bar at bottom on small screens | `app/admin/dashboard/invoice/create.php` | Medium |
| 6.3 | Settings tabs on mobile — Horizontal scrollable pills instead of vertical stack | `app/admin/dashboard/brand-setting/index.php` | Medium |
| 6.4 | WCAG AA audit — Focus rings, `aria-label` on icon-only buttons, skip-nav link | All admin files | Large |
| 6.5 | Keyboard navigation — Escape closes modals, Tab order | `assets/js/app.js` | Medium |
| 6.6 | Lazy-load heavy JS libraries — ApexCharts on dashboard/reports only, HugeRTE on invoice only | `app/admin/index.php` | Medium |
| 6.7 | Rename `system-settings/geneal.php` → `system-settings/general.php` (typo fix) | `app/admin/dashboard/system-settings/geneal.php` | Tiny |

---

## 7. Verification & QA Plan

### Automated Checks

| Check | Tool | Target |
|-------|------|--------|
| Accessibility score | Lighthouse | > 90 |
| Performance score | Lighthouse | > 80 |
| First Contentful Paint | Lighthouse | < 2.0s |
| CSS validation | Tailwind build (`npm run build`) | Zero errors |
| Broken links | Manual navigation of all admin routes | All pages load |

### Manual Visual Regression

Test every admin page in this matrix:

| Page | Light 375px | Light 768px | Light 1280px | Dark 375px | Dark 768px | Dark 1280px |
|------|:-----------:|:-----------:|:------------:|:----------:|:----------:|:-----------:|
| Login | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Dashboard (with data) | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Dashboard (zero data) | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Transactions | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Transaction Edit | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Invoice List | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Invoice Create | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Payment Links | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Gateways | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Customers | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Settings Hub | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| Staff Management | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |

### Functional Smoke Tests

- [ ] SPA navigation — Click every sidebar link, verify content loads
- [ ] Browser back/forward — History navigation works correctly
- [ ] Dark mode toggle — All components respond, no flash of unstyled content
- [ ] Brand switching — Dropdown works, page reloads with new brand context
- [ ] Form submission — Invoice create, customer create, settings save
- [ ] Bulk actions — Select multiple transactions, bulk approve/delete
- [ ] 2FA modal — Appears for protected actions, validates code
- [ ] Toast notifications — Success/error/warning/info all display correctly
- [ ] Search — Input accepts text and returns results (once implemented)
- [ ] Session expiry — After idle, redirects to login gracefully

---

*This document serves as the complete audit findings and restructuring blueprint for the OwnPay admin panel frontend. Implementation should follow the phased roadmap strictly, completing Foundation (Phase 1) before any other phase begins.*
