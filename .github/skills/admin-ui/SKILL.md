---
name: admin-ui
description: Build OwnPay admin panel pages and UI. Use when adding or editing an admin route, controller in src/Controller/Admin, Twig view under templates/admin, op-* component styling, brand/tenant scoping in the admin, tab navigation, modals, or admin accessibility (aria-modal and focus).
---

# Admin UI

## The three locations

| Layer | Location |
| --- | --- |
| Routes | `config/routes/web.php` (pages) and `config/routes/api.php` (REST) |
| Controllers | `src/Controller/Admin/*Controller.php`, sharing `AdminPageTrait` |
| Views | `templates/admin/**.twig`, layout in `templates/admin/layout/` |

There is no top-level `routes/` or `app/` directory. Front-end page scripts live in `public/assets/js/pages/*.js` with the shell in `public/assets/js/admin.js`.

## Shared render context — do not re-inject

`AdminPageTrait::renderAdminPage()` (`src/Controller/Admin/AdminPageTrait.php:40`) already supplies every admin template with `app_name`, `app_version`, `csrf_token` (via `SecurityHelpers::csrfToken()`), `csp_nonce`, `current_user`, `is_superadmin` (`:61`), `flash_success` / `flash_error`, `brands`, `active_brand`, `active_brand_id`, and the notification bell data. Controllers pass only page-specific keys; templates never guard for these being absent.

Templates can gate on `is_superadmin` directly — it is injected on every render, so do not pass it from a controller again.

## Tenant scoping in admin code

- **Two guards, reuse them.** `requireActiveBrand()` (`src/Controller/Admin/AdminPageTrait.php:220`) flashes and redirects when no concrete brand is selected; `requireGlobalView()` (`:252`) blocks platform-level actions attempted from a brand view. Do not invent a third guard style.
- **`TenantScope` is opt-in.** A repository only filters tenants if it `use`s the trait (`src/Repository/TenantScope.php:14`) and someone calls `forTenant()`. A missing `use` is a cross-tenant hole; a repository that never receives `forTenant()` reads globally.
- **`forTenant(0)` is not a global read** — it filters `merchant_id = 0` and returns nothing. Only an unscoped repository or `forAllTenants()` (`:41`) aggregates every brand.
- **`SettingsRepository::get()` / `set()` are global-only**: both match `merchant_id IS NULL` (`src/Repository/SettingsRepository.php:97`, `:116`). Calling `set()` where a brand override was intended silently creates a platform-wide setting; use `setScoped()` (`:260`) for per-brand values.
- Global-only pages are gated in two places: the sidebar hides the link when `active_brand_id` is set (`templates/admin/layout/sidebar.twig:130`, `:146`, `:204`), and the prefix list in `src/Middleware/PermissionMiddleware.php:112` blocks the path when a brand is active. A new global-only page needs both.

## The `op-*` component library — compose, do not invent

`public/assets/css/admin.css` already ships the vocabulary: `op-card` (`-header` / `-body`; `admin.css` has no `-footer` — `.op-card-footer` is page-specific, `public/assets/css/pages/developer.css:251`), `op-table`, `op-badge`, `op-btn` (`-primary` / `-outline` / `-danger` / `-ghost` / `-sm` / `-xs`), `op-input`, `op-alert`, `op-tabs` / `op-tab` / `op-tab-panel`, `op-modal`, `op-form-group` / `op-form-row`, `op-stats-grid`, plus page-specific classes. Spacing, radius and status scales live in `public/assets/css/tokens.css`. A new page is existing components in a new arrangement; grep `admin.css` before writing a bespoke class.

**Dark is the hardcoded default theme.** `templates/admin/layout/base.twig:2` ships `<html … data-theme="dark">` and `admin.css` overrides the token set under `[data-theme="dark"]`. Any standalone mockup or screenshot comparison must set `data-theme="dark"` on its root to match what users actually see.

Shell class names: `.op-layout` → `.op-sidebar` + `.op-main` → `.op-content` (which carries its own padding). An absolutely positioned overlay placed outside `.op-content` resolves against the viewport and slides under the fixed sidebar — wrap overlays in a positioned container inside `.op-content`.

## Tab navigation uses two different hash contracts

- `public/assets/js/pages/settings.js:45` strips a `#tab-` prefix: targets look like `#tab-domains`, matched against `.op-tab[data-tab]`.
- `public/assets/js/pages/developer.js:25` uses the raw hash: `#api-keys`, matched against `#dev-tabs .op-tab[data-tab]`.

A hash that matches no tab **fails silently**: `settings.js` ignores the unmatched hash and leaves the default tab, while `developer.js` falls back to the first tab on initial load (`pages/developer.js:28-31`) and ignores unmatched `hashchange` events (`:41-42`) — no error either way. Before linking to `…#anchor`, grep that page's tab strip for the exact `data-tab` value; never copy a hash shape between the two pages.

## Twig traps

- **`|default()` does not rescue zero.** Twig's `empty` test treats `''`, `null`, `[]` and `false` as empty but `0`, `'0'` and `0.0` as non-empty, so `{{ x|default(1) }}` renders `0` when the stored value is zero — while `{% if x %}` treats the same `0` as false. Attribute and hint can then disagree. Guard explicitly (`{% if x is defined and x > 0 %}`), and verify the semantics with a one-liner against the installed Twig instead of reasoning:

  ```bash
  php -r 'require "vendor/autoload.php"; $t=new Twig\Environment(new Twig\Loader\ArrayLoader(["x"=>"{{ v|default(1) }}"])); foreach([null,"0",0,"50"] as $v) echo json_encode($v)," => ",$t->render("x",["v"=>$v]),"\n";'
  ```

- **`?? 'fallback'` hides variables no renderer passes.** `??` compiles to a defined-guard and happily yields the fallback with no warning, so a template can read a key the rendering controller never sets. Find the controller that actually renders the template (grep `renderAdminPage(` / the route target) and confirm the key is in its data array before crediting a change.
- **Verify the link in both directions**: a context variable is a no-op unless the template reads it, and a template form is unreachable unless some button submits it. Grep both ends.

## Modals and accessibility

Admin modals are plain `.op-modal` divs (no `<dialog>`), e.g. `templates/admin/layout/modals.twig:2`, marked `role="dialog" aria-modal="true" aria-labelledby="…" hidden`. `aria-modal="true"` is only honest when focus actually moves, so:

- Reuse the `op-modal` markup and let `public/assets/js/admin.js` handle it: it saves the opener when opening (`admin.js:996`), moves initial focus into the modal (`:1006`), restores the opener on close (`:1020`), and traps Tab within `.op-modal:not([hidden])` cycling `first`/`last` focusables (`:1252`, `:1257`).
- If you hand-roll a modal, port that focus handling explicitly; otherwise drop `aria-modal` rather than lie to assistive technology.
- Exercise focus with real key events (Tab / Escape), not by calling the show API and eyeballing the screenshot.

## Verification

```bash
vendor/bin/phpstan analyse
vendor/bin/twig-cs-fixer lint templates
vendor/bin/phpunit <tests touching the page>
npm run lint
```

Render the template through Twig (or the route through the integration suite) to check its guards rather than grepping the markup, and assert on CSS classes rather than display text when a partial switches variants by class name.
