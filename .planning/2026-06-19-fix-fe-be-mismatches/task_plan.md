# Task Plan: Comprehensive Backend↔Frontend Sync — Find & Fix ALL Gaps

## Goal
Find and COMPLETELY fix every backend/frontend gap across ownpay:
- Backend features with no GUI/admin UI.
- Incomplete code: missing routes (api.php/web.php), missing controller methods, missing
  repository/service methods, missing templates, missing container/kernel wiring.
- Backend/frontend mismatches: template uses vars/fields the controller never provides;
  JS calls endpoints that don't exist; form fields the handler never reads; field-name drift.
- Frontend present without backend.

**Resolution rule (explicit user instruction): when FE and BE mismatch, update the FRONTEND
to match the BACKEND.** Skip nothing. No placeholders/TODOs/stubs. Production-ready.

## Constraints
- Windows + PowerShell. PHP 8.3, strict_types. Twig `strict_variables=true` → any missing var = 500.
- Match existing architecture (Twig SPA shell, BaseController/AdminPageTrait render helpers,
  `op-` CSS, TenantScope, Router string handlers `'Sub\\Foo@bar'`).
- No new heavy deps. Server-side validation, CSRF, permissions enforced.
- GUARDRAILS that MUST stay green at the end:
  - `php vendor/bin/phpstan analyse --no-progress` (level 9, clean)
  - `php vendor/bin/phpunit --no-progress` (~547 pass / small known skips)
  - `php vendor/bin/twig-cs-fixer lint templates`
  - `npm run lint:js`

## Context from prior plans (DO NOT blindly trust — verify against CURRENT code)
- Neoxa UI redesign introduced many FE/BE mismatches (`.planning/2026-06-17-ui-ux-mapping`).
  `2026-06-17-neoxa-ui-ux-data-sync` claims fixes BUT its progress only shows SmokeTest (8) —
  full verification NOT evidenced. TREAT AS UNVERIFIED; re-check every item.
- Admin panel restructured 2026-06-18 (v2): sidebar.twig, settings index.twig, brand settings,
  `is_brand_view` gating, subtitles on 48 templates → high risk of NEW mismatches.
- `2026-06-16-backend-frontend-sync` found wiring sound THEN; superseded by later changes.

## Current Phase
Exhaustive backend sweep COMPLETE (user chose "continue full sweep" then "keep digging backend").
Found + FIXED 2 bugs: (1) SmsParserService DI wiring [CRITICAL — broke mobile SMS ingestion],
(2) global-view create → FK 500 across 5 handlers [MEDIUM — robustness]. All guardrails green.
Remaining surfaces are visual/CSS/JS + deep business-logic → need browser (user login) or symptoms.
Cleanup pending: remove ownpay_test seed rows + delete audit scripts.

## Phases

### Phase 1: Setup & audit tooling
- [ ] Create plan dir + attest. Confirm verify commands run locally.
- [ ] Locate/rebuild reflection-based audit scripts (wiring, calls, inverse, template-vars, forms).
- **Status:** in_progress

### Phase 2: Wiring audit (routes ↔ controllers ↔ templates)
- [x] Every route handler `Class@method` resolves (web.php + api.php). CLEAN (228).
- [x] Every `render()`/template path used in PHP exists. CLEAN.
- [x] Every twig extends/include/embed exists. CLEAN.
- **Status:** complete

### Phase 3: Template-var audit (THE Neoxa-mismatch detector)
- [x] Static free-var pass (audit.php) → only false positives.
- [x] DEFINITIVE render pass (render_audit.php): boot full container+DB, fake superadmin,
      invoke every GET /admin controller; strict_variables=true. GLOBAL 66/0, BRAND 66/0.
      Harness proven sound (sanity.php). NO missing-var/attribute 500s.
- **Status:** complete

### Phase 4: Frontend-call + form-field + inverse audit
- [x] endpoint_audit.php: 154 JS+twig URLs → all resolve. CLEAN.
- [x] form_field_audit.php: 7 candidates, ALL verified FE-sent (false positives). CLEAN.
- [x] Guardrails: PHPUnit 550 pass, PHPStan L9 clean.
- **Status:** complete

### Phase 5: Triage & decisions
- [x] Admin/web result: clean. User chose "continue full sweep".
- **Status:** complete

### Phase 5b: Full sweep (user-authorized)
- [x] public_render.php: checkout/public GET pages → 12/0.
- [x] Incomplete-code marker grep → 0 real.
- [x] OpenAPI spec ↔ api.php routes → exact parity.
- [x] api_render.php (GET API invoke) → found BUG #1 (SmsParserService DI crash).
- [x] container_audit.php (build all controllers) → BUG #1 confirmed; 226/2 → 228/0 after fix.
- [x] find_untyped.php → SmsParserService was the only real container bug.
- **Status:** complete

### Phase 5c: Deep backend dig (user chose "keep digging backend")
- [x] headless_audit.php: cron runner + 9 jobs, queue, notifications, webhook svcs, plugin svcs → all resolve.
- [x] Verified documented FE/BE write paths (require_address, color/initials/description, ip_address).
- [x] write_handler_audit.php (execute writes vs ownpay_test, side-effect danger-list) → found BUG #2.
- **Status:** complete

### Phase 6: Implementation
- [x] FIX BUG #1: register SmsParserService::class factory in config/services.php.
- [x] FIX BUG #2: AdminPageTrait::requireActiveBrand guard on 5 create/store handlers
      (Invoice, PaymentLink, Customer, Roles, Gateway).
- **Status:** complete

### Phase 7: Verification
- [x] BUG#1: container_audit 228/0; api_render 18/0.
- [x] BUG#2: write_handler_audit GLOBAL 59/0 + BRAND 59/0.
- [x] PHPStan L9 clean; PHPUnit 552 pass (after both fixes).
- **Status:** complete

### Phase 6: Implementation
- [ ] Fix broken wiring (crashes) first → mismatches → missing routes/controllers → missing UI.
- **Status:** not_started

### Phase 7: Verification
- [ ] Re-run audit scripts → zero broken wiring / zero missing vars.
- [ ] phpstan L9 clean · phpunit green · twig lint clean · js lint clean.
- [ ] Live spot-check of highest-risk pages if app reachable.
- **Status:** not_started

### Phase 8: Delivery
- [ ] Remove one-off audit scripts. Summarize all fixes to user.
- **Status:** not_started

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Fresh reflection-based audit, ignore stale "fixed" claims | neoxa-sync verification gap + later restructure; only runtime/reflection proves current state. |
| Frontend follows backend on mismatch | Explicit user instruction. |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
