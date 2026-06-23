# Task Plan: Restructure Admin Panel

## Goal
Restructure the OwnPay admin panel to cleanly separate global system-wide administration (master) from brand-specific configurations, merge duplicate/redundant settings, enhance the user experience with tooltips, page-level documentation guides, modern toast notifications, custom confirmation modals, and a "Create New Brand" link in the brand switcher. Refine the sidebar menu to strictly follow the standard order: Dashboard -> Payments -> Gateways -> People -> Mobile & SMS -> Reports & Finance -> Developers -> Appearance -> System -> Account.

## Current Phase
Phase 3: Core Implementation

## Phases

### Phase 1: Research & Discovery
- [x] Catalog all admin routes and identify global-only vs. brand-specific items.
- [x] Analyze settings index template (`index.twig`) and list all current tabs.
- [x] Identify duplicates (e.g., API Keys, Webhooks, Currencies, Brand Editing fields).
- [x] Trace active brand context switching logic and identify redirection needs.
- [x] Document discoveries in `findings.md`.
- **Status:** complete

### Phase 2: Design & Planning (User Review Required)
- [x] Design the new Sidebar Menu structure for Global View vs. Brand View.
- [x] Define the tabs for Global Settings (master defaults) and Brand Settings (scoped overrides).
- [x] Specify route access guards to prevent brand-scoped users from accessing global routes.
- [x] Design the JS toast notifier, custom confirmation modal, and dynamic help-doc book icons.
- [x] List all options requiring tooltips and their detailed explanation strings.
- [x] Outline the implementation plan in `implementation_plan.md`.
- **Status:** complete

### Phase 3: Core Implementation
- [/] Re-organize `sidebar.twig` to follow: Dashboard -> Payments -> Gateways -> People -> Mobile & SMS -> Reports & Finance -> Developers -> Appearance -> System -> Account.
- [ ] Ensure consistent, standard SVG icons for all menu links in `sidebar.twig` (e.g. speedo/home, credit card, key/plug, users, smartphone, chart bar, code, palette, cog, user).
- [ ] Modify `SettingsController.php` index and save to support global settings tabs vs brand-scoped settings tabs.
- [ ] Modify `BrandController.php` corporate details save and remove branding visual customizations.
- [ ] Simplify `edit.twig` brand view to only show Business Details.
- [ ] Restructure `settings/index.twig` to dynamically display global vs brand-wise settings tabs.
- [ ] Remove duplicate API Keys/Webhooks cards from settings views.
- **Status:** in_progress

### Phase 4: UX & UI Features (Toasts, Modals, Book Icons, Tooltips)
- [ ] Implement Toast Notification utility in `admin.js` and CSS styles in `admin.css`.
- [ ] Implement Custom Confirmation Modal in `modals.twig`, `admin.js`, and `admin.css`.
- [ ] Convert target forms/actions to use `data-confirm` instead of `window.confirm`.
- [ ] Map documentation paths in `AdminPageTrait.php` and inject book `📖` icons next to page headers via JS.
- [ ] Add CSS-based tooltip definitions to `admin.css` and tooltips to settings inputs.
- **Status:** pending

### Phase 5: Verification & Testing
- [ ] Run linter tasks: `npm run lint` and `composer lint:twig`.
- [ ] Run test suite: `vendor/bin/phpunit`.
- [ ] Manually verify switching contexts, alert toasts, confirmation modal, and doc links.
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| CSS-based Tooltips | Avoids loading heavy external tooltip libraries, keeping performance optimal. |
| Global Settings URL | Keep `/admin/settings` as the unified route, but dynamically load different views based on the active brand context (`0` vs `> 0`). |
| Meta/JS Doc Injector | Dynamic injection of `window.OP_DOC_URL` in `base.twig` and appending the icon in JS avoids editing 50+ templates manually. |

## Errors Encountered
| Error | Resolution |
|-------|------------|


