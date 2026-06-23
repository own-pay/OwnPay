# Task Plan: Rate Limit Customization and Security

## Goal
Implement a robust, production-ready rate limiting customization and security recovery system in OwnPay, allowing super admins to configure limits, windows, and IP whitelists via the admin panel, viewing a beautiful HTML UI instead of raw JSON when rate-limited on web routes, and providing a highly secure CLI-based lockout reset and emergency signed URL mechanism.

## Current Phase
Phase 2: Design & Planning (Awaiting Approval)

## Phases

### Phase 1: Requirements & Discovery
- [x] Read and understand how rate limit works in the codebase
- [x] Analyze `RateLimiterMiddleware.php`, `RateLimitRepository.php`, `SettingsController.php`, and `DeveloperController.php`
- [x] Identify constraints, database structure, and routing patterns
- [x] Run existing test suite to ensure baseline is fully green
- **Status:** complete

### Phase 2: Design & Planning
- [x] Define settings schema for dynamic rate limit rules and IP whitelist
- [x] Design the secure emergency reset signature algorithm and CLI command
- [x] Design the branded 429 HTML fallback error page
- [x] Update `implementation_plan.md` in the artifacts folder and request feedback/approval
- **Status:** in_progress

### Phase 3: Database & Core Service Extensions
- [ ] Add `rateLimitPage()` method to `ErrorPageRenderer.php` for fallback HTML display
- [ ] Create `templates/error/429.twig` with glassmorphism layout and live countdown timer
- [ ] Modify `RateLimiterMiddleware.php`:
  - [ ] Implement IP whitelist matching using CIDR/bitmask logic
  - [ ] Implement custom rate limit rule matching by path wildcard and HTTP method
  - [ ] Detect `$request->expectsJson()` and return Twig or fallback HTML page on 429
- **Status:** pending

### Phase 4: Admin Panel UI & Settings Persistence
- [ ] Protect Developer Hub rate limit tab and action handlers with strict `$this->session->isSuperadmin()` check
- [ ] Create `saveSettings(Request $req)` action in `DeveloperController` to validate and save IP whitelist and custom rules JSON
- [ ] Modify `templates/admin/developer/index.twig` (Rate Limits Tab):
  - [ ] Wrap rule grid and whitelist inputs under `{% if is_superadmin %}`
  - [ ] Bind AJAX forms/submits to update rate-limit configurations
  - [ ] Conditionally show "Reset" buttons for active buckets only to Super Admins
- **Status:** pending

### Phase 5: CLI Tools & Emergency Reset Handler
- [ ] Implement `cli/rate-limit-reset.php` for CLI-based resets and HMAC-SHA256 signed URL generation
- [ ] Add GET route `/rate-limit/emergency-reset` under `web` middleware group
- [ ] Implement `emergencyReset(Request $req)` handler in `DeveloperController` with strict signature validation, expiration assertion, IP clearance, and audit logging
- **Status:** pending

### Phase 6: Testing & Verification
- [ ] Run PHPUnit tests and ensure zero regressions
- [ ] Run PHPStan analysis and twig/eslint/stylelint linters
- [ ] Verify HTML UI rendering on 429 throttling
- [ ] Verify signed URL expiration and cryptographic security
- **Status:** pending

## Key Questions
1. **How should dynamic rules be stored?** Storing them as a JSON array in settings (`rate_limit_rules`) under the `general` group is clean and avoids schema migration overhead.
2. **How should IP whitelisting support CIDR subnets?** Using PHP's IP binary/subnet masking checks (similar to trusted proxies checks in `Request.php`).
3. **How does the emergency reset URL remain secure?** Using `hash_hmac` with the server's unique `APP_KEY` combined with a short-lived `expires` timestamp.

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Store rules as JSON array | Clean, fast, uses existing SettingsRepository without table migration. |
| Use `APP_KEY` for URL signing | Leverages existing high-entropy secret; secure and server-only. |
| Check `expectsJson()` in Middleware | Separates API clients (who need clean JSON) from browser users (who should get HTML UI). |
| Enforce `$this->session->isSuperadmin()` | Complies with strict "this only can do super admin not any other staff" rule. |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| N/A | 1 | N/A |

## Notes
- Ensure strict types and PSR-4 formatting on all modifications.
- Super admin authentication must be checked explicitly for any admin-based resets.

