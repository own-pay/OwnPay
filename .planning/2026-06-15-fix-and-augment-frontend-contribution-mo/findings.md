# Findings & Decisions

## Requirements
- Fix the `admin/brands/switch` route which currently returns a 404 in the template mock environment.
- Add missing mock data for the Audit Trail Cryptographic Integrity interface.
- Add mock data for Disputes and ensure dispute routes (`/admin/disputes/{id}`) render correctly without 404.
- Mock all form POST actions and updates on the Audit Integrity and System Update pages to prevent 404 errors.

## Research Findings
- The preview script [preview.php](file:///c:/laragon/www/ownpay/docs/frontend_contribution/preview.php) does not initialize PHP sessions.
- In [navbar.twig](file:///c:/laragon/www/ownpay/docs/frontend_contribution/templates/admin/layout/navbar.twig), the brand switch uses a GET request to `/admin/brands/switch?id=X`.
- The audit integrity checker form in [audit_integrity.twig](file:///c:/laragon/www/ownpay/docs/frontend_contribution/templates/admin/audit_integrity.twig) POSTs to `/admin/audit-integrity/scan`.
- System update features in [system-update.twig](file:///c:/laragon/www/ownpay/docs/frontend_contribution/templates/admin/system-update.twig) POST to `/admin/system-update/apply`, `/admin/system-update/status`, `/admin/system-update/check`, and `/admin/system-update/settings`.
- Disputes templates exist (`index.twig` and `show.twig` under `admin/disputes/`), but `disputes` data is missing in [mock_data.json](file:///c:/laragon/www/ownpay/docs/frontend_contribution/mock_data.json) and there's no dynamic route matching for `admin/disputes/show.twig` (only fallback to `edit.twig` for singular ID resources).

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Enable PHP Sessions | Allows persisting active brand state and scan status between requests in the preview server. |
| Add `disputes` list and `update_available` variables to `mock_data.json` | Ensures the disputes and system update pages populate with design-ready content. |
| Map `/admin/brands/switch` | Updates the active brand in the session and redirects back to the referer. |
| Map `/admin/audit-integrity/scan` | Toggles the system security status (secure/compromised) in the session to show both design states. |
| Map `/admin/balance-verification/run` | Toggles the reconciliation results in the session. |
| Map `/admin/system-update/apply` & `/status` | Implements an interactive real-time stepper update animation by sleeping 6s on apply and tracking progress on status. |
| Support `admin/<resource>/show.twig` fallback | Ensures disputes (which only have a `show.twig` detail view) load correctly. |

