# Findings: Move Inline CSS/JS to External Assets

We identified multiple occurrences of inline CSS `<style>` blocks in the admin panel templates. In order to fully comply with modern secure coding practices (and a strict Content Security Policy posture with zero inline code allowed), we must extract all these CSS blocks to static, page-specific files under `public/assets/css/pages/` and load them via the page `<head>`.

## Identified Inline CSS Blocks

1. **Setup Wizard (`templates/admin/dashboard/_setup_wizard.twig`)**:
   - Contains a huge CSS block (lines 3-595).
   - Action: Move to a new file `public/assets/css/pages/setup-wizard.css`.
2. **Developer Hub (`templates/admin/developer/index.twig`)**:
   - Contains styling for modal key reveal and monospace inputs (lines 22-75).
   - Action: Move to a new file `public/assets/css/pages/developer.css`.
3. **Paired Devices (`templates/admin/devices/index.twig`)**:
   - Contains utility alignments and stat gradients (lines 4-26).
   - Action: Move to a new file `public/assets/css/pages/devices.css`.
4. **Custom Domains (`templates/admin/domains/index.twig`)**:
   - Contains basic list-none and custom cursor styling (lines 4-17).
   - Action: Move to a new file `public/assets/css/pages/domains.css`.
5. **My Account 2FA (`templates/admin/my-account-2fa.twig`)**:
   - Contains form-2fa, QR image, summary details, and verify inputs styling (lines 4-11).
   - Action: Move to a new file `public/assets/css/pages/my-account-2fa.css`.
6. **System Settings (`templates/admin/settings/index.twig`)**:
   - Contains logo/favicon forms hide, currency table limit, and copy-tooltips styling (lines 827-871).
   - Action: Move to a new file `public/assets/css/pages/settings.css`.

## Identified Inline JS Scripts
We also need to check for any inline `<script>` tags that may still exist.
We ran a grep check and found:
- `templates/admin/layout/base.twig` has:
  - Line 14: Prevent FOUC script.
  - Line 50: `window.OP_CSRF='{{ csrf_token }}';`
- `templates/admin/dashboard.twig` has:
  - Line 104-112: `dateRange.addEventListener('change', ...)`
- Any other pages.

Let's move these inline scripts where possible, or document why they exist (like `window.OP_CSRF` which binds backend-generated CSRF tokens dynamically, or the FOUC prevention script which must run inline *before* page paint to prevent theme flickering). For dateRange listener, we can move it to a JS asset.

## Constraints
- Do not introduce `'unsafe-inline'` policies.
- Must ensure proper asset mapping (pages load only their specific assets using Twig `{% block head %}` and `{% block scripts %}`).
