# Findings & Decisions

## Requirements
- Audit all settings, forms, and pages in the Admin portal to ensure no missing GUIs remain.
- Validate dynamic data and settings fields from the feature map.
- Align the active highlighting classes on sub-tab navigation links in logs.

## Research Findings
- The Admin portal settings tabs cover all 1.x-5.x sections including General, SMTP, Branding, Landing Page, Default Payments, Checkout defaults, Currencies/Rates, Languages, SMS Rules, Security, Queue Monitor, Optimization, and Webhooks.
- In `templates/admin/activities.twig` and `templates/admin/settings/login-attempts.twig`, the active tab layout link class for the Login Attempts sub-tab was hardcoded or incorrectly configured.
- The `LoginAttemptController::index()` controller did not pass the `active_subpage` parameter to differentiate active sub-tabs.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Dynamic Sub-tab active classes | Utilizing Twig conditional classes linked to `active_subpage` ensures robust sub-navigation styling. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Inconsistent Active tab highlighting | Passed active_subpage from the controller and updated Twig conditionals. |

## Resources
- [ADMIN-PANEL-MAP.md](file:///c:/laragon/www/ownpay/docs/frontend_contribution/ADMIN-PANEL-MAP.md)
- [schema.sql](file:///c:/laragon/www/ownpay/database/schema.sql)
- [routes/web.php](file:///c:/laragon/www/ownpay/config/routes/web.php)
