# Task Plan: Diagnose and Fix Custom Domain 404 Error & UI Redesign

## Goal
Resolve the 404 Not Found error on custom domains, implement strict route isolation, and completely overhaul the Custom Domains admin panel UI to support editing, status toggles, SSL checking, and a professional DNS guide layout.

## Current Phase
Phase 2 (Planning for UI Overhaul)

## Phases

### Phase 1: Requirements & Discovery
- [x] Understand user intent
- [x] Identify constraints
- [x] Document in findings.md
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Define approach for DNS guide redesign
- [x] Define controller updates (edit, toggle status, check-ssl)
- [x] Create implementation_plan.md artifact
- **Status:** complete

### Phase 3: Implementation (Subdomain Fix & Route Isolation)
- [x] Comment out `RewriteBase /` in `public/.htaccess`
- [x] Add warning logs to `DomainMiddleware.php`
- [x] Enforce strict route matching by domain type (`checkout`, `api`) in `DomainMiddleware.php`
- [x] Add root `/` redirect logic using domain's `redirect_url` in `DomainMiddleware.php`
- [x] Verify unit/integration tests pass
- **Status:** complete

### Phase 4: Implementation (Admin Domains UI Overhaul)
- [x] Add update and check-ssl routes to `config/routes/web.php`
- [x] Add `update` and `checkSsl` methods to `DomainController.php`
- [x] Rearrange and redesign DNS guide at the bottom of `domains/index.twig`
- [x] Put Domain List Table and Add Domain at the top of `domains/index.twig`
- [x] Create Edit Settings modal in `domains/index.twig`
- [x] Update `domains.js` to populate the Edit Modal
- [x] Add style overrides in `domains.css`
- **Status:** complete

### Phase 5: Testing & Verification
- [x] Verify requirements met
- [x] Document test results in progress.md
- **Status:** complete

### Phase 6: Delivery
- [x] Review outputs
- [x] Deliver to user
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Comment out `RewriteBase /` | Fixes subdirectory rewrite loop when subdomain points to the project root folder instead of the `public/` subdirectory. |
| Log domain matching warnings in `DomainMiddleware` | Simplifies diagnosis if custom domain Host header is mangled by reverse proxies. |
| Enforce domain type path checks | Provides pure white-labeling security by blocking admin, login, and api paths on checkout domains. |
| Redirect `/` to `redirect_url` | Hides the default OwnPay landing page on custom domains and redirects visitors to the merchant's store website. |
| Single Edit Modal | A single modal populated dynamically via JS data attributes keeps the DOM clean and light. |
| Native PHP SSL Handshake Probe | Probing port 443 with OpenSSL stream context allows verifying SSL without executing unsafe shell commands. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
