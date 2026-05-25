# Findings: Plugins Organization and Logo Loading

## 1. Root Causes identified

### A. Admin Panel Plugin Logo Missing
- In `templates/admin/plugins/index.twig`, it doesn't attempt to load `plugin.logo_path`. Instead, it uses hardcoded fallback SVGs based strictly on type (`gateway`, `theme`, `addon`).
- In `PluginController::index()`, the database query `op_plugins` does not have a `logo_path` column, and the controller does not enrich the plugin objects with their public icon paths.

### B. Checkout Page Plugin Logo Issues
- Active plugins' logos are copied to `public/assets/img/gateways/{slug}.{ext}` during registration.
- In `GatewayConfigRepository::listActiveForCheckout()`, the database selects `g.logo_path` from `op_gateways`.
- If the plugin was activated before fixing previous bugs, or if the destination file is deleted, it doesn't get recopied unless activated again.
- In `templates/checkout/partials/_gateway-grid.twig`, the background color CSS has `{{ (gw.color ?? '#ECEEF5')|e('css') }}0F` which escapes the `#` to `\23 ` and concatenates with `0F` to form an invalid CSS hex escape (`\23ECEEF50F`). This breaks color rendering in the browser and could cause CSS parse errors.

### C. Admin Plugins Mixed Together
- Gateway plugins, theme plugins, and addon plugins are all loaded in a mixed grid.
- JavaScript only filters by status (Active, Inactive, Available, Trash). It does not separate them by types (Gateways, Themes, Addons).

## 2. Technical Solution Plan

1. **Copying and Path Resolution**:
   - Expose `PluginManager::resolveIconPath()` as `public`.
   - Update `PluginController::index()` to call `resolveIconPath` for all database and discovered plugins. This ensures that the destination logo file is copied to the public directory on the fly when the admin visits the page, and `logo_path` is correctly populated for rendering.
   
2. **Admin Panel Presentation**:
   - Update `templates/admin/plugins/index.twig` to render the actual logo using `<img>` if `plugin.logo_path` is set, falling back to the default type SVG on load failure (`onerror`).
   - Restructure tabs to filter by type: `All | Gateways | Addons | Themes | Trash`.
   - Add status filter dropdown (`All Statuses`, `Active Only`, `Inactive Only`, `Not Installed Only`) and local search input.
   - Update `public/assets/js/pages/plugins.js` to handle combined type + status + search filtering.
   
3. **Checkout Page Color Fix**:
   - Update `templates/checkout/partials/_gateway-grid.twig` to safely render the hex color code without the breaking `|e('css')` filter: `#{{ gw.color|default('ECEEF5')|replace({'#': ''})|e('html_attr') }}0F`.

## 3. Phase 6: Gateway Dashboard Refinement
- **GatewayController**: Update `index()` method to dynamically resolve the logo path of each API gateway using `PluginManager::resolveIconPath()`.
- **Gateways Template**: Update `templates/admin/gateways/index.twig` to render logo image with `onerror` attribute to fall back to the name initials wrapper dynamically.

## 4. Phase 7: Gateways Reorganization
- **Filtering UI**:
  - Filter Tabs: `All Gateways` | `API Gateways` | `Manual Gateways`
  - Search Input: Real-time search matching gateway name, slug, and mode/type.
  - Status Dropdown: Options for `All Statuses`, `Active`, `Inactive`, `Uninstalled`.
- **Layout Unification**:
  - Both API Gateways and Manual Gateways will be rendered in a unified card grid. This replaces the mismatched grid-vs-table layout, making the interface completely consistent.
  - Manual Gateways will display a badge identifying them as `manual`, and display their SMS verification status cleanly.
  - API Gateways will display an `API` badge and live/test mode badge when active.
  - Cards will have appropriate layout classes to ensure styling is uniform.
- **Client-Side JavaScript**:
  - Implement dynamic JS-based filtering in `public/assets/js/pages/gateways.js` (very similar to `plugins.js`).

## 5. Phase 8: Payment Link and Invoice Link Fix
- **Root Cause**:
  - Payment link and invoice link copy buttons used `location.origin`, which points to the admin master domain instead of the dynamic custom domain of the brand context resolved via `DomainUrlService`.
  - In addition, they called `navigator.clipboard.writeText()` directly, which throws an error and fails in non-secure HTTP contexts or older browsers.
- **Solution**:
  - Retrieve the dynamic `base_url` for the brand context in `InvoiceController::index()` and `PaymentLinkController::index()` using `DomainUrlService` and pass it to the Twig template contexts.
  - Implement a global `window.opCopyText(text, button, successCallback)` utility function in `public/assets/js/admin.js` that attempts `navigator.clipboard.writeText()` but seamlessly falls back to a temporary offscreen `<textarea>` copy command in non-secure HTTP contexts.
  - Refactor all data copy actions in `admin.js`, `developer.js`, and `domains.js` to utilize this global helper to ensure robust copy functionality everywhere in the application.

