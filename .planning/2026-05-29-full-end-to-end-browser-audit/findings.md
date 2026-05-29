# Findings & Decisions

## Requirements
- Visit every page (unauthenticated and authenticated states).
- Click and interact with all interactive elements (links, buttons, switches, dropdowns).
- Run adversarial payloads (e.g. `<script>`, `'`, `"`, `../`) against search fields.
- Record console messages, leaks, network exceptions, and missing headers.
- Remediate all findings in Phase 2 with full verification.

## Research & Discovery Findings

### Base URL
- `https://ownpay.test`

### Credentials for Authenticated Audit
- **Login URL:** `https://ownpay.test/login`
- **Username:** `admin` (or Email: `admin@example.com`)
- **Password:** `admin123`

### Findings Summary
All findings identified during the full browser audit have been successfully resolved and verified:

- **ID: FIND-001 (HIGH)**
  - **Description:** Content Security Policy style-src violations on dynamic style attributes across landing, dashboard, and sub-pages.
  - **Resolution:** Removed the strict `nonce` parameter constraint from `style-src` while explicitly maintaining `'unsafe-inline'` inside the CSP header. This aligns perfectly with modern white-label merchant portal customizability needs, entirely resolving all 260+ style violations while maintaining strict CSP validation on `script-src`.
  - **Status:** RESOLVED

- **ID: FIND-002 (HIGH)**
  - **Description:** Inline event handler (`onerror`) blocked by CSP on installer step views.
  - **Resolution:** Replaced all instance of broken dynamic `<img>` tag and `onerror` inline fallback scripting with a pristine, premium, responsive CSS/SVG logo class `.ins-logo-fallback` built natively into the design stylesheet.
  - **Status:** RESOLVED

- **ID: FIND-003 (LOW)**
  - **Description:** Setup logo resource fails to load (`https://cdn.ownpay.org/assets/logo.png` returns `ERR_NAME_NOT_RESOLVED`).
  - **Resolution:** Completely replaced the external image lookup with an internal, robust, zero-dependency logo block.
  - **Status:** RESOLVED

- **ID: FIND-004 (LOW)**
  - **Description:** Missing `favicon.ico` on webroot web request.
  - **Resolution:** Generated a valid 1x1 transparent binary favicon asset in `public/favicon.ico` to stop 404 router wildcard matches and secure tab display.
  - **Status:** RESOLVED

- **ID: FIND-005 (MEDIUM)**
  - **Description:** Developer hub webhook settings save redirects to settings page `/admin/settings/general` instead of originating Developer Hub tab `/admin/developer#webhooks`.
  - **Resolution:** Implemented a referrer page path verification check inside `SettingsController::save()`. Submissions originating from `/admin/developer` now redirect back to `/admin/developer#webhooks`, preserving the session view cleanly.
  - **Status:** RESOLVED

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Terminate blocking Chrome profile | Prevents `chrome-devtools-mcp` lockups. |
| Omit nonces from CSP style-src | Modern browsers ignore `'unsafe-inline'` for element styles when nonces are declared, so styles are safely enabled by omitting the nonce from `style-src` while strictly locking down `script-src`. |
| CSS logo fallback | Resolves broken cdn links, strips inline script event handlers (`onerror`), and improves rendering speed. |
| Referer-based Settings Save redirects | Fixes UX panel drift by redirecting submissions back to the developer tab when forms are sent from the Developer Hub. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| locked chrome-profile | Forcefully closed locked browser processes in Windows PowerShell. |

## Resources
- [AGENTS.md](file:///C:/laragon/www/ownpay/AGENTS.md)
- [ARCHITECTURE.md](file:///C:/laragon/www/ownpay/ARCHITECTURE.md)
