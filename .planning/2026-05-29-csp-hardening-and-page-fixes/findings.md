# Findings & Discovery: CSP Hardening & Admin Page Fixes

## 1. Requirements
- Audit all pages of the admin panel to detect and eliminate inline styles and scripts (CSP hardening).
- Replace inline attributes (`style="..."`, `onclick="..."`, `onsubmit="..."`, `onchange="..."`) with clean CSS classes and unobtrusive nonced JavaScript event listeners.
- Keep the Content Security Policy fully strict (no `'unsafe-inline'`).
- Ensure all pages (Developer Hub, Devices, Domains, Roles, Staff, Gateways, Activities, My Account, Setup Wizard, etc.) remain fully functional.
- Maintain passing PHPUnit test suite and Level 9 PHPStan.

## 2. Codebase Audit Results

### Inline Style Attributes (`style="..."`)
We found the following templates with inline style attributes:
- [activities.twig](file:///c:/laragon/www/ownpay/templates/admin/activities.twig) (line 12)
- [brands/edit.twig](file:///c:/laragon/www/ownpay/templates/admin/brands/edit.twig) (multiple lines)
- [customers/create.twig](file:///c:/laragon/www/ownpay/templates/admin/customers/create.twig) (line 8)
- [dashboard/_setup_wizard.twig](file:///c:/laragon/www/ownpay/templates/admin/dashboard/_setup_wizard.twig) (multiple lines)
- [developer/index.twig](file:///c:/laragon/www/ownpay/templates/admin/developer/index.twig) (multiple lines)
- [devices/index.twig](file:///c:/laragon/www/ownpay/templates/admin/devices/index.twig) (multiple lines)
- [domains/index.twig](file:///c:/laragon/www/ownpay/templates/admin/domains/index.twig) (multiple lines)
- [roles/index.twig](file:///c:/laragon/www/ownpay/templates/admin/roles/index.twig) (multiple lines)
- [my-account.twig](file:///c:/laragon/www/ownpay/templates/admin/my-account.twig) (line 41)
- [customers.twig](file:///c:/laragon/www/ownpay/templates/admin/customers.twig) (SVG line 11)

### Inline Event Handler Attributes (`onclick`, `onsubmit`, etc.)
We found the following templates with inline script handlers:
- [layout/modals.twig](file:///c:/laragon/www/ownpay/templates/admin/layout/modals.twig) (closeModal, openDeleteModal support)
- [developer/index.twig](file:///c:/laragon/www/ownpay/templates/admin/developer/index.twig) (toggleKeyVisibility, copyGeneratedKey, generateSecret)
- [devices/index.twig](file:///c:/laragon/www/ownpay/templates/admin/devices/index.twig) (close/pair panels, confirmation prompts)
- [domains/index.twig](file:///c:/laragon/www/ownpay/templates/admin/domains/index.twig) (modal toggle, confirm prompts)
- [roles/index.twig](file:///c:/laragon/www/ownpay/templates/admin/roles/index.twig) (modal toggle, edit roles, confirm prompts)
- [my-account-2fa.twig](file:///c:/laragon/www/ownpay/templates/admin/my-account-2fa.twig) (confirm prompts)
- [system-update.twig](file:///c:/laragon/www/ownpay/templates/admin/system-update.twig) (confirm prompts)
- [brands/index.twig](file:///c:/laragon/www/ownpay/templates/admin/brands/index.twig) (confirm prompts on submit)
- [customers.twig](file:///c:/laragon/www/ownpay/templates/admin/customers.twig) (confirm prompts on submit)
- [plugins/index.twig](file:///c:/laragon/www/ownpay/templates/admin/plugins/index.twig) (confirm prompts on submit)
- [themes/index.twig](file:///c:/laragon/www/ownpay/templates/admin/themes/index.twig) (confirm prompts on submit)
- [staff/index.twig](file:///c:/laragon/www/ownpay/templates/admin/staff/index.twig) (confirm prompts on submit)
- [gateways/index.twig](file:///c:/laragon/www/ownpay/templates/admin/gateways/index.twig) (confirm prompts on submit)
- [dashboard/_setup_wizard.twig](file:///c:/laragon/www/ownpay/templates/admin/dashboard/_setup_wizard.twig) (step navigation, otp copy/display, mail selection)

## 3. Technical Constraints & Design Rules
- CSP headers are enforced globally via `SecurityHeadersMiddleware.php` with no `'unsafe-inline'` allowed.
- Inline styles must be extracted to class rules in `public/assets/css/admin.css` or placed inside a nonced `<style nonce="{{ csp_nonce }}">` block within the template.
- Inline event handlers must be replaced by unobtrusive JS event listeners (using event delegation or page-specific nonced scripts).
- Form submission prompts using `onsubmit="return confirm(...)"` can be replaced with `data-confirm="..."` attributes handled via event delegation in `public/assets/js/admin.js`.
