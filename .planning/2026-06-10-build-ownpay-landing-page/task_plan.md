# Task Plan: Build OwnPay Landing Page

## Goal
Build a premium, fully functional, secure, and interactive PHP landing page and admin portal for OwnPay in the `ownpay_org_landing_page/` directory.

## Current Phase
Completed

## Phases

### Phase 1: Architecture Plan & Database Schema
- [x] Discovery phase: Analyze existing assets, pages, styles, scripts, and helper PHP files.
- [x] Create detailed Implementation Plan (`implementation_plan.md`) outlining the directory tree, database schema, security layout, page inventory, and third-party integrations.
- [x] Obtain user approval on the implementation plan.
- **Status:** complete

### Phase 2: Design System & Foundation Build
- [x] Setup base CSS styles mapping to OwnPay colors (obsidian dark, gold accent).
- [x] Establish directory structure for the app (app, config, admin, templates, assets/css, assets/js).
- [x] Setup base layout files (header, footer, nav, shell).
- **Status:** complete

### Phase 3: Home Page Implementation (Section by Section)
- [x] Hero Section (subscription form with AJAX waitlist submit, GitHub star counts with caching).
- [x] Backed By / Trusted By Section.
- [x] Deployment SVG Animation (intersection observer, prefers-reduced-motion fallback).
- [x] Why OwnPay.
- [x] Sponsor Bento Box Grid (click-to-modal, details card).
- [x] Development Roadmap nice map.
- [x] Open Source / GitHub Stars section.
- [x] FAQ accordion.
- [x] Combined Contributors + Sponsors showcase.
- [x] Footer.
- **Status:** complete

### Phase 4: Additional Pages Build
- [x] `/donate` Supporter Center (integrate with EPS gateway).
- [x] `/donors` public donor hall of fame (paginated).
- [x] `/sponsors` full showcase.
- [x] `/privacy-policy` real policy.
- [x] `/architecture` deep dive into the custom PHP SOA.
- [x] `/security` posture and disclosure details.
- [x] `robots.txt` and `sitemap.xml` dynamically generated.
- **Status:** complete

### Phase 5: Admin Panel Implementation
- [x] Admin authentication (bcrypt, rate limiting, session-based, CSRF protected).
- [x] Admin Dashboard (live stats, recent items).
- [x] Subscribers Module (search, filter, CSV export, MailerLite sync).
- [x] Donations Module (public display toggle, stats).
- [x] Sponsors Module (CRUD with logo file upload sanitization).
- [x] Contributors Module (CRUD with avatar upload).
- [x] Settings/CMS Module (edit headline, social links, SMTP config, announcement banner).
- [x] Audit Log (read-only action history, CSV export).
- **Status:** complete

### Phase 6: Security Hardening & Performance
- [x] Configure root `.htaccess` (force HTTPS, security headers, block sensitive paths, WAF rules).
- [x] Prevent information leakage (PHP display_errors, zero console.logs, custom error pages).
- [x] Asset minification, image lazy-loading, font preloading.
- **Status:** complete

### Phase 7: Verification & Testing
- [x] Execute validation script or manual test plan to check all modules.
- [x] Verify security compliance (static analysis, OWASP rules).
- [x] Create walkthrough.md.
- **Status:** complete

## Key Questions
1. Do we have a working MySQL database instance? (Yes, verified and connected).
2. What are the MailerLite group IDs and credentials? (Can be managed via settings page).
3. Do we need to run git commands or code-checking scripts? (Yes, verified and passing).

## New Refactoring Phase (Active)
- [x] Implement Light Theme variables and adjust navbar, backed-by, footer, and other background colors.
- [x] Refactor Supported by community section: remove FlexoHost, keep only Namepart.
- [x] Embed `flow.svg` for Architecture Flow section in `home.php`.
- [x] Sponsors Bento Grid: make logos full color, wrap in direct anchor links to website_url with `rel="nofollow noopener noreferrer"`.
- [x] Redesign Roadmap to look premium and pro.
- [x] Check and fix Community Proof stars counter and layout.
- [x] Make FAQ more space-efficient (two-column grid on desktop).
- [x] Upgrade Footer to be premium, adding missing links (Learn, Blog, Developer, Support, Donate).
- [x] Run dev server and test using Chrome devtools browser.

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Create isolated plan directory | Follows mandatory `planning-with-files` rule |
| No external packages for landing page | Avoids third-party dependencies as per prompt guidelines (raw PHP, CSS, JS) |
| Remove all console.logs | Clean console output, prevent info leaks in production |
| Add CSP nonce to inline script tags | Ensures strict browser CSP does not block our scripts |
| Shift to 100% Light Theme | Fulfill user request to make theme light |
| Output flow.svg inline | Fulfill user request and leverage CSS transitions/animations |
| Direct links on sponsors | Fulfill user request to bypass bento details popup and open website directly |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| PHPStan class not found | 1 | Used `--autoload-file` to bootstrap configuration file |
| Redundant null coalescing / type-checking errors | 1 | Refactored type checks and cleaned up types for PHPStan level 5 |

