# DESIGN.md — Admin UI/UX & Custom Framework Audit (Deliverable 2)

> Companion to `ownpay_master_audit_report.md`. Deep-dive UI/UX assessment of the OwnPay administrative panel and an engineering feasibility analysis of the bespoke PHP framework. Evidence-grounded in the actual templates (`templates/admin/*`), design tokens (`public/assets/css/admin.css`), and core (`src/*`).

---

## PART 1 — Admin Panel UI/UX Evaluation

### 1.1 Current state (what exists, verified)
- **Shell**: fixed 260px left sidebar + sticky 60px top navbar + 1400px-max content (`admin.css:73-99`). Collapsible sidebar (64px rail) with localStorage persistence (`admin.js`).
- **Information architecture** (`templates/admin/layout/sidebar.twig`): 8 top-level groups, each an expandable section:
  1. **Dashboard**
  2. **Payments** → Transactions · Invoices · Payment Links · Disputes · Customers
  3. **Reports & Finance** → Reports · Ledger · Balance Verification · Audit Integrity · Activities
  4. **Mobile & SMS** → Paired Devices · SMS Center · SMS Data
  5. **Team & Brands** → Brands · Staff · Roles & Permissions
  6. **Developers** → Developer Hub · Webhooks DLQ · Documentation (external)
  7. **System & Settings** → Settings · Payment Gateways · Themes · Plugins · Addons · Domains · System Update
  8. **My Account**
- **Theming**: dark by default (`:root`), light via `[data-theme="light"]` (`admin.css:8-63`); fully tokenized (~40 CSS custom properties); i18n via `__('menu.*')` + `storage/languages/en.json`.
- **Design tokens (as-built)**: font `Inter`; `--op-primary #6C5CE7`, hover `#7c6df7`, active `#a29bfe`; status `--op-success #00b894`, `--op-warning #fdcb6e`, `--op-danger #e17055`, `--op-info #74b9ff`; radius 8/12; logo/avatar gradient `linear-gradient(135deg,#6C5CE7,#a29bfe)`; stat cards lift on hover (`translateY(-2px)`).

### 1.2 Scannability, searchability & ease-of-use — assessment
**Strengths (genuinely good):**
- The IA is **logical and discoverable**. Every operator task the brief asked about has a clear, correctly-grouped home:
  - Payment gateways → *System & Settings › Payment Gateways*.
  - Paired companion devices → *Mobile & SMS › Paired Devices*.
  - Staff & role permissions → *Team & Brands › Staff / Roles & Permissions*.
  - API keys → *Developers › Developer Hub*.
  - Ledger balance verification → *Reports & Finance › Ledger / Balance Verification / Audit Integrity*.
- Active-state affordance is clear (3px accent left-border + tinted bg + brighter text, `admin.css:90-94`).
- Dark-first palette with consistent tokenization is the right baseline for an ops console used for long sessions.
- Stat cards, tables, cards, dropdowns are consistently styled — low visual entropy.

**Weaknesses / stranding & confusion risks:**
1. **No global command/search palette.** With 8 groups and ~25 leaf destinations, a `⌘K`/`Ctrl-K` "search settings, gateways, transactions, devices" launcher is the single highest-leverage addition. Operators currently must remember which group owns a feature (e.g. is "Addons" under System or Developers?).
2. **API-key discoverability.** API keys live *inside* "Developer Hub" rather than as a labelled leaf — a first-time operator looking for "API Keys" in the nav won't see it. Promote to a named sub-item.
3. **Naming ambiguity.** "Webhooks DLQ" (dead-letter queue) is jargon for non-engineers; "Activities" vs "Audit Integrity" vs "Reports" overlap semantically. Consider "Failed Webhooks", and a short helper subtitle per finance item.
4. **Plugins vs Addons vs Themes** are three separate leaves under System — operators may not know the distinction (gateway plugins vs functional addons vs visual themes). A single "Extensions" hub with tabs would reduce cognitive load.
5. **Balance-verification trust surface.** For a payment console, the ledger/balance/audit-integrity screens are the most safety-critical; they deserve a dedicated, prominent "Finance health" dashboard widget (reconciliation status, unbalanced-journal count, last audit-hash check) on the main Dashboard — not buried three clicks deep.
6. **Palette inconsistency (real defect).** Two different "primary" indigos coexist: CSS token `--op-primary #6C5CE7` vs the installer-seeded brand `primary_color #6366f1` (`InstallerController.php:596`). Buttons rendered from the CSS token and brand-driven elements will not match. The accent set (`#00b894/#e17055/#fdcb6e`) is the "Flat-UI" palette, not derived from a single hue system — it reads slightly dated for a premium fintech product and is hard to keep accessible (contrast) across dark/light.

### 1.3 Proposed premium enterprise-fintech design system
A cohesive **HSL token system** (single-source hues → tints/shades by lightness), replacing the ad-hoc hex set. This keeps dark/light parity and WCAG contrast predictable.

```css
:root {
  /* Brand hue = Indigo 248° (reconciles #6C5CE7 ≈ hsl(248 79% 63%)) */
  --h-brand: 248; --h-accent: 268; /* violet companion for gradients */
  --op-primary:        hsl(var(--h-brand) 79% 63%);
  --op-primary-strong: hsl(var(--h-brand) 80% 56%);
  --op-primary-soft:   hsl(var(--h-brand) 79% 63% / 0.12);
  --op-grad:           linear-gradient(135deg, hsl(var(--h-brand) 79% 63%), hsl(var(--h-accent) 84% 70%));

  /* Semantic — single saturation/lightness scale per hue for parity */
  --op-success: hsl(160 84% 39%);   --op-success-soft: hsl(160 84% 39% / .14);
  --op-warning: hsl(38 92% 50%);    --op-warning-soft: hsl(38 92% 50% / .14);
  --op-danger:  hsl(0 84% 60%);     --op-danger-soft:  hsl(0 84% 60% / .14);
  --op-info:    hsl(212 92% 64%);   --op-info-soft:    hsl(212 92% 64% / .14);

  /* Dark neutral spectrum (cohesive, not arbitrary) — slate w/ indigo tint */
  --op-bg:      hsl(240 38% 10%);   /* #0f0f23 family, tuned */
  --op-surface: hsl(240 30% 14%);
  --op-surface-2: hsl(240 28% 18%);
  --op-border:  hsl(240 24% 26%);
  --op-text:    hsl(214 32% 91%);
  --op-text-muted: hsl(215 20% 65%);
}
[data-theme="light"]{
  --op-bg: hsl(220 33% 98%); --op-surface:#fff; --op-surface-2: hsl(220 33% 96%);
  --op-border: hsl(214 32% 91%); --op-text: hsl(222 47% 17%); --op-text-muted: hsl(215 16% 47%);
}
```

- **Typography**: keep **Inter** for UI body/labels; add **Outfit** for display/numerals (dashboard KPIs, balances, money values) — `font-variant-numeric: tabular-nums` on all currency cells so columns align. Type scale: 12/14/16/20/28/40 with 1.5 line-height for prose, 1.2 for headings.
- **Elevation & depth**: 3-tier surface system (`--op-bg` → `--op-surface` → `--op-surface-2`) with one soft shadow token; avoid heavy borders in dark mode (use surface contrast instead).
- **Micro-animations (tasteful, 120–200ms, `cubic-bezier(.2,.8,.2,1)`)**: nav-link left-border grow on hover; stat-card lift (already present — formalize); button press scale `.98`; toast slide+fade; skeleton shimmer for table loads; respect `prefers-reduced-motion`.
- **Premium touches**: gradient logo mark already exists — extend a subtle gradient to primary CTAs and the active KPI card; add a thin top "brand bar" gradient; status pills use the `*-soft` background + solid text for AA contrast.
- **Dashboard "Finance Health" band** (new): reconciliation status, unbalanced-journal count, last audit-hash verification, pending-SMS queue, paired-device heartbeat — turning the safety-critical signals into a glanceable strip.
- **Global ⌘K command palette** + per-table column-search; sticky table headers; empty-states with a clear next action (prevents stranding).

> The design recommendations are forward-looking; they do not change the audit verdict. They are independent of the HOLDBACK (which is driven by FIND-003/FIND-004 in the core report).

---

## PART 2 — Custom Framework Feasibility Analysis

### 2.1 Is it enterprise-grade / next-gen? — verdict: **Yes, with disciplined caveats**
| Capability | Status | Evidence |
|---|---|---|
| Autowired PSR-11 container | **Yes** | `src/Container.php` reflection autowiring + singleton/instance/param bindings; `psr/container` implemented. |
| Strict middleware pipeline | **Yes (PSR-15-like, not PSR-15)** | `Kernel::runMiddleware` builds an onion (`Kernel.php:327-368`); groups in `config/middleware.php`. Uses a `handle(Request,$next)` convention, not the `Psr\Http\Server\MiddlewareInterface` contract. |
| Sandboxed event/filter layer | **Yes** | `EventManager` actions/filters with per-owner sandbox SQL re-check + brand-scoped activation (`EventManager.php:308-330`). |
| Automatic tenant scoping | **Yes** | `TenantScope` clone-scoping injects/asserts `merchant_id`; `requireTenant()` fails closed. |
| Centralized error mask | **Yes** | `Kernel::handleException` hides traces in prod, sanitizes file paths/credentials even in debug (`Kernel.php:414-484`). |
| PSR-7 request/response | **No (custom)** | `src/Http/Request|Response` are bespoke immutable wrappers, not PSR-7. |

It is a genuinely competent, security-conscious micro-framework — not a toy. The design choices (clone-based tenant scoping, fail-closed `requireTenant`, brand-scoped plugin activation, sandboxed `db.query.before`, path-masking exception handler, hardcoded-HS256 JWT) reflect real fintech threat-modeling.

### 2.2 Custom framework vs Laravel/Symfony — trade-off analysis
**Arguments FOR the custom framework (well-supported here):**
- **Low-resource shared hosting**: dependency surface is tiny (twig, firebase/php-jwt, ramsey/uuid, phpdotenv, chillerlan/php-qrcode). `composer audit` = 0 advisories — a direct security dividend of a small tree. Per-request bootstrap is light vs Laravel's service-provider/facade boot; realistic to bootstrap in low single-digit MB. This is the right call for a self-hosted product that must run on 512MB/1-vCPU shared hosting (see core report §8).
- **Tenant isolation safety**: `merchant_id` scoping lives at the repository layer (`TenantScope`) and *throws* if unscoped — stronger and more auditable than bolting multi-tenancy onto a general-purpose ORM. The whole brand-isolation boundary (one of the strongest parts of the audit) is enabled by owning this layer.
- **Direct lifecycle control**: a single `Kernel::handle` with explicit boot order, one exception mask that prevents path/SQL leakage, and an explicit middleware onion. No framework "magic" to reason around for a transaction-critical system.
- **Maintenance independence**: no exposure to large-framework breaking-change treadmills or transitive-dependency CVEs.

**Arguments AGAINST / the real costs (also supported by this audit):**
- **Hand-wired bootstrap is where the CRITICAL slipped in.** FIND-003 (`Database::getInstance()` never initialized in prod) is *exactly* the class of bug a mature framework's container/lifecycle would have prevented — a service-locator/DI split that no integration test caught (because PHPUnit can't even run on PHP 8.2, FIND-006). Owning the framework means owning these failure modes.
- **No ecosystem.** No first-party migrations, queue workers, mailer, validation, or test scaffolding maturity. You re-implement (and must secure) primitives Laravel/Symfony provide audited out of the box. The gateway-adapter inconsistency (FIND-004/005) is partly a symptom of having to hand-roll the plugin contract without a framework-enforced interface + conformance tooling.
- **Standards drift**: not PSR-7/15 means third-party middleware/HTTP libraries aren't drop-in; contributors must learn a bespoke API.
- **Security burden is yours**: CSRF, headers/CSP, rate-limiting, SSRF validation are all hand-built here (and mostly well — see core report §7), but every one is a surface you must maintain rather than inherit.

### 2.3 Recommendation
**Keep the custom framework** — for a self-hosted, low-resource, tenant-isolated, transaction-critical gateway the trade is justified, and the codebase demonstrates the team can wield it. **But pay down the two structural debts the audit exposed**, or the bespoke-framework risk profile is not worth it:
1. **DI discipline** — eliminate the static `Database::getInstance()` service-locator; inject via the container (closes FIND-003 and the whole bug-class). Adopt a rule: no static service locators anywhere in `src/Service`/`src/Controller`.
2. **Contract enforcement for plugins** — an abstract gateway base + CI conformance tests (no un-gated `mock_`, real `verifyWebhook`, real/explicit `refund`) — closes FIND-004/005 fleet-wide and is the framework feature most missing today.
3. **Make the test tier runnable** (FIND-006) so regressions like FIND-003 are caught — align PHP floor and PHPUnit version. Without a runnable test suite, a custom framework's risk is materially higher.
4. Optionally adopt the **PSR-15 middleware interface** and **PSR-7** messages to regain ecosystem compatibility without abandoning the lean kernel.

> Net: the framework is a defensible, even smart, foundation; its risks are concentrated in hand-wired bootstrap and unenforced plugin contracts — both fixable without a rewrite.
