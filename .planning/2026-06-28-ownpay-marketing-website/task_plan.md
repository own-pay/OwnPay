# Task Plan: OwnPay.org Marketing Website Implementation

## Goal
Create a standalone, zero-framework PHP 8.3+ and MariaDB marketing website for the open-source, self-hosted payment gateway platform OwnPay (licensed under AGPL) at ownpay.org. It must be highly secure, LCP optimized (<1.2s), mobile responsive, and feature a fully dynamic admin CMS, a custom donor system using the EPS gateway, and a GitHub contributor sync process.

## Current Phase
Phase 1: Foundation

## Phases

### Phase 1: Foundation
- [ ] Set up local dev environment (using dedicated web server for `ownpay-site.test` or similar)
- [ ] Initialize directory layout under `landing_page/` (public/, src/, templates/, config/, storage/)
- [ ] Create core backend framework: Router, Database, Request, Response, Session, Csrf, RateLimiter
- [ ] Add SecurityHeadersMiddleware
- [ ] Implement base layout templates (header.php, footer.php, nav.php)
- [ ] Implement global styling system in `public/assets/css/main.css` (custom properties, 8px grid, typography, colors from logo SVG)
- [ ] Implement light/dark mode switch (persisted in localStorage)
- [ ] Create core documentation files: AGENTS.md, ARCHITECTURE.md, DESIGN.md, CONTRIBUTING.md
- **Status:** complete

- **Status:** complete

- **Status:** complete

- **Status:** complete

- **Status:** complete

- **Status:** complete

### Phase 7: Content and Polish
- [ ] Seed database tables with official gateway entries, default settings, and initial stats
- [ ] Review all page copy, check all links, and verify mobile responsiveness
- [ ] Update deta.md to be the definitive project reference
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Zero Framework (Raw PHP) | Pure PHP matches the user requirement, keeps LCP extremely low, and minimizes dependencies. |
| MariaDB 10.6+ | Meets user preference, provides JSON column types, and is industry-standard for professional db design. |
| Standalone Structure | Decoupled from core OwnPay app codebase for clean separation and ease of deployment. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
