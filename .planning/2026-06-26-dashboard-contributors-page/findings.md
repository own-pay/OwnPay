# Findings & Decisions

## Requirements
- Render the contributors section at `/admin/contributors`.
- Lock the founder "Fattain Naime" (Founder of OwnPay) as the fixed first card.
- Pull dynamic contributors from public GitHub repository `own-pay/OwnPay` on the client-side using `fetch()`.
- Filter out duplicate identity matching the fixed lead user (`fattain-naime`) from the dynamic list.
- Map and store only `login`, `avatar_url`, and `contributions` fields from the GitHub API payload.
- Cache the filtered data in browser localStorage with `ownpay_contributors_cache` and `ownpay_contributors_timestamp` keys.
- Enforce a 24-hour browser-caching policy (86,400,000 milliseconds).
- Implement a network failure/rate throttle fallback where the expired cache is rendered if fetching fresh data fails.
- Sanitize public usernames to prevent XSS by using `document.createElement` and explicit `.textContent` value assignments instead of `.innerHTML`.

## Research Findings
- **Controller**: [ContributorController.php](file:///c:/laragon/www/ownpay/src/Controller/Admin/ContributorController.php) serves this page. It currently returns a hardcoded list of static contributors.
- **Twig Template**: [contributors.twig](file:///c:/laragon/www/ownpay/templates/admin/contributors.twig) renders a list of cards using CSS classes (`.op-contributors-grid`, `.op-contributor-card`, etc.) defined in `admin.css`.
- **Styling**: Already defined in [admin.css](file:///c:/laragon/www/ownpay/public/assets/css/admin.css) around line 594.
- **Fixed Lead Contributor Info**:
  - Name: `Fattain Naime`
  - Role: `Founder of OwnPay`
  - GitHub Login: `fattain-naime`
  - Avatar URL: `https://github.com/fattain-naime.png`
  - Commits: `542 commits` (historically tracked or hardcoded for the lead card).

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Use `ContributorController` to pass only Fattain Naime to Twig | Allows Twig to handle server-side rendering of the fixed lead card, keeping the layout skeleton clean. |
| Store JS in `public/assets/js/pages/contributors.js` | Follows the existing codebase organization pattern where page-specific JS lives under `public/assets/js/pages/`. |
| Append dynamic cards after the fixed lead card in `#dynamic-contributors-grid` | The container will initially hold the Twig-rendered fixed card, and the script will append the fetched/cached contributors after it. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|

## Resources
- GitHub Repositories Contributors API: `https://api.github.com/repos/own-pay/OwnPay/contributors`
- LocalStorage caching key details: `ownpay_contributors_cache` (JSON) and `ownpay_contributors_timestamp` (Unix timestamp in ms).
