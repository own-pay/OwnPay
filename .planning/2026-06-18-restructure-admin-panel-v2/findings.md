# Findings & Decisions

## Requirements
- **Sidebar Cleanup:** Remove the redundant `{# 10. Account #}` menu group from `sidebar.twig`.
- **Settings Tab Organization:**
  - Merge the global `Theme` settings tab into `Branding Defaults`.
  - Move the "Admin Login URL Slug" field from `Landing Page` to `Security`.
  - Wrap all global-only settings tabs (`general`, `landing`, `payment`, `email`, `security`, `notification`, `theme` [merged], `cron`, `language`, `queue`, `optimization`) in `{% if not is_brand_view %}` checks.
  - Automatically redirect brand-view requests trying to load global-only settings tabs to `/admin/settings/branding`.
- **Descriptions:**
  - Add descriptions/tooltips for every setting field/input in `settings/index.twig`.
  - Add page-level subtitle descriptions under `<h1>` on every administrative page (approx 49 templates).

## Research Findings
- **Account Redundancy:** The sidebar user footer (lines 298–331) already renders "My Account", "2FA Setup", and "Logout" options, making the separate sidebar group #10 redundant.
- **Global Settings Tabs:** The `cron`, `language`, and `queue` tabs were previously visible in the panels even when `is_brand_view` was true. They will be wrapped in `{% if not is_brand_view %}`.
- **Redirections:** `SettingsController::index` must redirect to `/admin/settings/branding` if a brand-scoped user requests a global-only tab.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Merge Theme tab into Branding | Simplify settings organization, as Theme was only setting colors. |
| Move Admin Slug to Security | The URL slug is a security-by-obscurity measure, logically belonging to Security settings. |
| Page Subtitles in Twig | Improves the premium feel of the dashboard by clearly explaining each page's purpose. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- [sidebar.twig](file:///c:/laragon/www/ownpay/templates/admin/layout/sidebar.twig)
- [index.twig](file:///c:/laragon/www/ownpay/templates/admin/settings/index.twig)
- [SettingsController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/SettingsController.php)

