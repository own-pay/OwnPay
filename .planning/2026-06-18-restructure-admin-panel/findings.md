# Research Findings: Restructure Admin Panel

## 1. Routing & Scoping Analysis

### Global-Only Areas
- **Brand Management**: `/admin/brands` (and all sub-routes `/create`, `/store`, `/delete` etc., except `/switch` which handles context switching).
- **Global Configuration Settings**: `/admin/settings` (SMTP, optimization, languages, system update, cron, queue, defaults).
- **Appearance Modules Management**: `/admin/themes` and `/admin/plugins`.
- **System Update Management**: `/admin/system-update`.
- **Global Financial Audits**: `/admin/balance-verification` and `/admin/audit-integrity`.

### Brand-Specific Scoped Areas
- **Transactions & Intents**: `/admin/transactions` and `/admin/payment-intents`.
- **Invoices & Payment Links**: `/admin/invoices` and `/admin/payment-links`.
- **Refunds & Disputes**: `/admin/refunds` and `/admin/disputes`.
- **Reports & Activities**: `/admin/reports`, `/admin/ledger`, `/admin/activities` (scoped when non-superadmin).
- **Brand Staff & Roles**: `/admin/staff` and `/admin/roles`.
- **Customers**: `/admin/customers`.
- **Developer Tools**: `/admin/developer` (API Keys, Webhooks list, webhooks logs).
- **Custom Domains**: `/admin/domains`.
- **SMS Center & Paired Devices**: `/admin/sms-center` and `/admin/devices`.

---

## 2. Duplicate Settings & Redundancies

### API Keys
- **Location A:** Settings -> API tab. Lists and allows generation/revocation.
- **Location B:** Developer Hub -> API Keys tab. Lists, generates, and revokes.
- **Merge Action:** Remove API Keys card completely from Global settings page. Keep Developer Hub as the single source of truth for API keys management.

### Webhooks
- **Location A:** Settings -> API tab has inputs `webhook_url` and `webhook_secret` (stored in global system settings group `general`).
- **Location B:** Developer Hub -> Webhooks tab manages multiple database-backed webhook endpoints (`op_webhooks` table) scoped per brand.
- **Merge Action:** Remove the single legacy `webhook_url` and `webhook_secret` fields from Global settings. Developer Hub's multiple webhooks list is the correct modern implementation.

### Currencies
- **Location A:** Settings -> Payment tab has supported currencies and exchange rates management.
- **Location B:** Sidebar/route `/admin/currencies` which simply redirects to Settings -> Payment.
- **Merge Action:** Retain the settings tab, as it's cleanly integrated. No code changes needed here besides keeping the redirect.

### Brand Visual Customization
- **Location A:** Edit Brand form (`/admin/brands/{id}/edit`) contains Logo upload, Favicon upload, colors, footer, custom CSS/JS, and checkout messages overrides.
- **Location B:** Global settings -> Branding and Theme tabs store the system-wide fallback defaults.
- **UX Issue:** Merchant users (non-superadmins) cannot access `/admin/brands` to edit their branding because it is hidden in brand view.
- **Merge Action:**
  1. Remove all Visual Customization and Checkout Message inputs from `/admin/brands/{id}/edit`. Keep only corporate details (name, email, phone, status, timezone, default currency).
  2. Put these branding/customization fields under the brand-wise Settings page `/admin/settings` (visible when `$activeBrandId > 0`).

---

## 3. Dynamic Page Documentation Link Map (learn.ownpay.org)

To avoid modifying 50+ templates, we define a dynamic document map in `AdminPageTrait::renderAdminPage()` based on `$active_page` and inject it as `doc_url` into Twig. We also write `window.OP_DOC_URL = "{{ doc_url }}"` in `base.twig`.
A general script in `admin.js` will select `.op-page-header h1` or `.dash-header h1` and append:
` <a href="${window.OP_DOC_URL}" target="_blank" rel="noopener" class="op-help-doc-link" title="View Documentation">📖</a>`

### Documentation Map:
- `dashboard` -> `https://learn.ownpay.org/dashboard`
- `transactions` -> `https://learn.ownpay.org/payments/transactions`
- `payment-intents` -> `https://learn.ownpay.org/payments/intents`
- `invoices` -> `https://learn.ownpay.org/payments/invoices`
- `payment-links` -> `https://learn.ownpay.org/payments/links`
- `disputes` -> `https://learn.ownpay.org/payments/disputes`
- `refunds` -> `https://learn.ownpay.org/payments/refunds`
- `reports` -> `https://learn.ownpay.org/reports/finance`
- `ledger` -> `https://learn.ownpay.org/reports/ledger`
- `balance-verification` -> `https://learn.ownpay.org/audit/balance`
- `audit_integrity` -> `https://learn.ownpay.org/audit/integrity`
- `activities` -> `https://learn.ownpay.org/audit/activities`
- `brands` -> `https://learn.ownpay.org/system/brands`
- `customers` -> `https://learn.ownpay.org/people/customers`
- `staff` -> `https://learn.ownpay.org/people/staff`
- `roles` -> `https://learn.ownpay.org/people/roles`
- `gateways` -> `https://learn.ownpay.org/integrations/gateways`
- `fee-rules` -> `https://learn.ownpay.org/integrations/fee-rules`
- `developer` -> `https://learn.ownpay.org/developer`
- `sms-center` -> `https://learn.ownpay.org/integrations/sms`
- `sms-data` -> `https://learn.ownpay.org/integrations/sms-logs`
- `devices` -> `https://learn.ownpay.org/integrations/devices`
- `settings` -> `https://learn.ownpay.org/system/settings` (Global) or `https://learn.ownpay.org/brand/settings` (Brand)
- `themes` -> `https://learn.ownpay.org/system/appearance`
- `plugins` -> `https://learn.ownpay.org/system/plugins`
- `addons` -> `https://learn.ownpay.org/system/addons`
- `system-update` -> `https://learn.ownpay.org/system/update`
- `domains` -> `https://learn.ownpay.org/system/domains`

---

## 4. UX Enhancement Design

### Toast Notifications
A client-side javascript toast container appended to `body`:
```html
<div class="op-toast-container" id="toast-container" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>
```
A JavaScript helper `window.opShowToast(message, type = 'success')`:
- Creates a floating toast card with transition effects.
- Type matches styling: `success` (teal, checkmark icon), `error`/`danger` (red, alert icon).
- Fades out after 4 seconds automatically.

### Custom Confirmation Modal
Instead of native blocking `window.confirm()`, we will trigger a customized confirmation modal.
In `modals.twig`:
```html
<div class="op-modal" id="confirm-modal">
    <div class="op-modal-backdrop" id="confirm-modal-backdrop"></div>
    <div class="op-modal-card">
        <div class="op-modal-header"><h3 id="confirm-modal-title">Confirm Action</h3></div>
        <div class="op-modal-body" id="confirm-modal-message">Are you sure?</div>
        <div class="op-modal-footer">
            <button class="op-btn op-btn-outline" id="confirm-modal-cancel">Cancel</button>
            <button class="op-btn op-btn-danger" id="confirm-modal-confirm">Confirm</button>
        </div>
    </div>
</div>
```
A JavaScript scanner intercepting elements with `data-confirm`:
```javascript
document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-confirm]');
    if (btn) {
        e.preventDefault();
        window.opShowConfirm(
            btn.getAttribute('data-confirm-title') || 'Confirm Action',
            btn.getAttribute('data-confirm'),
            btn.getAttribute('data-confirm-text') || 'Confirm',
            btn.getAttribute('data-cancel-text') || 'Cancel',
            function() {
                if (btn.tagName === 'A') {
                    window.location.href = btn.href;
                } else {
                    var form = btn.closest('form');
                    if (form) form.submit();
                }
            }
        );
    }
});
```

### Tooltips
We add `op-help-tooltip` wrapper elements in templates with a `data-tooltip` attribute. CSS styles tooltip positioning and displays it elegantly on hover.

### Brand Switcher "Create New Brand" Button
Add a button styled matching the dropdown items at the end of the brand dropdown menu in `sidebar.twig`.
Filter click logic in `admin.js` to avoid intercepting and POSTing switcher links that do not have `data-brand-id`.
