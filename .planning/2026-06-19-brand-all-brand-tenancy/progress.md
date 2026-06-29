# Progress Log

## Session: 2026-06-19

### Current Status

- **Phase:** 0 - Discovery complete; blocked on user decisions D1–D3.

### Actions Taken

- Read user's tenancy clarification; mapped current architecture (BrandContext, TenantScope,
  SettingsRepository, schema merchant_id patterns, op_plugins/op_brand_plugins) + DB state.
- Key finding: config-fallback + plugin model already match the user's model; the foundational gap is
  representing "All-Brands-owned" operational data (createScoped requires a tenant; merchant_id NOT NULL FK).
- Noted: earlier "BUG#2 global-view-create guard" was based on the wrong model; to be replaced.

### Phase 1a (2026-06-19) - brand restrictions + hints - COMPLETE

- AdminPageTrait: isGlobalBrandView() + requireGlobalView() guard.
- Guarded plugin/theme platform actions (upload/install/uninstall/trash/restore) → All-Brands-only.
- Hints (partial _all_brands_only.twig) + button-hiding on Plugins/Themes/Addons/Gateways in brand view.
- Manual-gateway server enforcement + storage DEFERRED to Phase 2 (needs Platform-owner row).

### Test Results

| Test | Expected | Actual | Status |
|------|----------|--------|--------|
| render_check GLOBAL (4 pages) | button shown, notice hidden, install 200 | yes | PASS |
| render_check BRAND (4 pages) | button hidden, notice shown, install 302 | yes | PASS |
| PHPStan L9 (full) | clean | No errors | PASS |
| twig-cs-fixer (93 tpl) | clean | 0 errors | PASS |
| PHPUnit | ~552 pass | 552 pass, 0 fail | PASS |

### Errors

| Error | Resolution |
|-------|------------|
| Brand view showed button/no notice | Twig `x|default(true)` treats false as empty → used `x ?? true` |
| Template edits not reflected | Twig compiled-cache stale → cleared storage/cache/twig |

### Phase 1b (2026-06-19) - Inbound Webhook/IPN page - COMPLETE

- New page /admin/gateway-webhooks (GatewayWebhookController + template + sidebar entry + permission).
- URL logic via DomainUrlService (All Brands=main; brand=custom domain or notice). data-copy buttons.
- ESSENTIAL FIX: removed /admin/plugins + /admin/addons from PermissionMiddleware $globalOnlyPrefixes
  (brands must access them per the per-brand plugin model + to see the 1a hints). Platform plugin
  actions still blocked via per-action guards. Themes kept platform-only (global activation).
- VERIFIED: render both views OK; PHPStan L9 clean; twig lint clean; PHPUnit 552 pass.

### Phase 1c (2026-06-19) - brand feature/settings parity - AUDITED, already implemented

- SettingsController + settings/index.twig already give brands brand-scoped: branding (logo/favicon/
  colors/profile/support email/footer), checkout (msgs/timer/FAQ/custom CSS+JS), domains, faq, payment.
  Platform-only settings correctly gated to All Brands. Logo upload brand-scoped in upload(). NO fixes.
- Open (user product call): per-brand email from-name/address + notification prefs (not yet brand-overridable).

### PHASE 1 COMPLETE (1a restrictions + 1b webhook page + 1c parity-audit). Guardrails green

- Cleaned up one-off render_check.php harness.

### PHASE 2 - APPROVED + fully designed (2026-06-19); ready to implement next session

- User decisions: proceed with Phase 2; brand additions = per-brand email sender + notification prefs;
  manual gateway model = (A) template + per-brand account (platform defines type/default; each brand
  sets its OWN account; payments route to that brand). See task_plan "PHASE 2".
- Migration infra: SQL files in database/migrations/ (next = 013), tracked in op_migrations, applied
  via UpdateService on update / manually in dev (root/root; ownpay + ownpay_test). schema.sql is master.
- Implementation order (each step: implement → phpstan/twig/phpunit → render/write harness → next):
  2a Platform-owner row (is_platform col + seed + BrandContext getPlatformId/getWriteMerchantId;
     EXCLUDE platform row from getAllBrands/brand switcher; replace interim create guards) →
  2b API-key isolation → 2c manual gateways (money-critical routing - extra care) →
  2d staff access-all-brands permission → 2e per-brand email sender + notification prefs.
- CAUTION for 2a: the seeded platform row must NOT appear in brand switcher/getAllBrands, must not be
  deletable as a brand, and must not be a selectable active brand.

### Phase 2 progress (2026-06-19)

- 2a DONE: migration 013 (is_platform + platform row) on ownpay(id=2)+ownpay_test(id=101998); schema.sql;
  BrandContext getPlatformId/getWriteMerchantId + getAllBrands excludes platform. Verified. 552 pass.
  Decision: admin operational-create guards KEPT (create within a brand); platform-owned data = API path.
- 2b DONE: API key generate/revoke/list use getWriteMerchantId (All Brands → platform keys, isolated).
  Updated 2 ApiKeyApiSecurityTest cases to the new model. PHPStan clean. 552 pass.
- 2d DONE: brands.access_all permission (installer seed + migration 014 → ownpay+ownpay_test);
  BrandController::switchBrand global gate = superadmin OR brands.access_all. PHPStan clean; 552 pass.
- REMAINING: 2c manual gateways (MONEY-CRITICAL routing) + 2e per-brand email sender + notification prefs.
  Both deserve focused turns (2c reroutes funds; 2e touches mail + notification services + settings UI).

### SESSION CHECKPOINT (2026-06-19) - updated

Done + verified: Phase 1 (1a/1b/1c) + Phase 2a/2b/2d. All guardrails green (PHPStan L9, twig, 552 PHPUnit).
Migrations 013 (platform row) + 014 (brands.access_all) applied to ownpay + ownpay_test. Nothing committed.
2e: FULLY DESIGNED (build plan in task_plan) - chose "build email pipeline"; deferred to a focused
test-driven pass (touches payment-completion path; needs service+wiring+templates+settings UI+tests).
2c: manual gateways (money-critical) - remaining.
NEXT FOCUSED EFFORTS: (1) 2e email pipeline per the build plan; (2) 2c manual gateways with payment-routing care.

### Phase 2e (2026-06-20) - Email pipeline + per-brand sender/prefs - COMPLETE

Built the previously-dormant email-notification pipeline end-to-end:

- NEW src/Service/Communication/EmailNotificationService.php: onTransactionCompleted + onRefundCreated.
  Gates on getScoped('general', email_on_payment/email_on_refund, mid); recipient =
  getScoped('general','admin_notification_email',mid); renders email/*.twig via FragmentRenderer;
  dispatches via CommunicationService::sendEmail. Every handler fully try/caught (defence-in-depth on
  top of EventManager's per-listener isolation) → email can NEVER disrupt payment completion.
- CommunicationService::sendEmail hardened: when message['from'] empty, default from
  getScoped('general', mail_from_email/mail_from_name, mid) → "Name <addr>"/"addr" (centralised DRY;
  benefits provider + fallbackMail From header). KEY CORRECTION: sender/pref keys live in group
  'general' (mail_from_email/mail_from_name/admin_notification_email/email_on_payment/email_on_refund),
  NOT group 'mail' - confirmed via SettingsController::saveGeneral whitelist + settings template.
- NEW templates/email/refund_processed.twig (mirrors payment_received.twig).
- Wired in config/services.php system.boot block at priority 20 (after PaymentCompletionListener):
  payment.transaction.completed + refund.created → EmailNotificationService.
- Brand-overridable UI: new brand-view "Email Notifications" settings tab (templates/admin/settings/
  index.twig) with from name/email, notification email, and inherit/on/off selects. Blank text =
  inherit (deleteSettingScoped); selects '' = inherit. SettingsController index() loads brand
  overrides (getScopedOverride) + inherited globals (placeholders) + allows the 'notifications' tab;
  save() brand branch → saveBrandNotifications()/applyBrandOverride() → setScoped/deleteSettingScoped.
  NEW SettingsRepository::getScopedOverride() (brand value only, null = inherit).
- ARCHITECTURE.md §4.9 added (event-driven per-brand email).
- TESTS (8 new): tests/Integration/EmailNotificationTest.php (6; fake MailProviderInterface plugin
  capturing sends; mirrors SmsGatewayAddonTest) - pref gating on/off, recipient resolution, per-brand
  From ("Name <addr>" + bare addr), refund path, EventManager wiring, malformed-payload safety.
  tests/Integration/BrandNotificationSettingsTest.php (2) - brand-override persistence via the real
  SettingsController::save + blank-clears-to-inherit (getScopedOverride null). NOTE: tests/Feature is
  NOT in phpunit.xml's testsuites (so PlatformMaintenanceTest is orphaned too) → put both in Integration.

### Test Results (2026-06-20)

| Check | Expected | Actual | Status |
|-------|----------|--------|--------|
| EmailNotificationTest | new green | 6 tests, 37 assertions | PASS |
| BrandNotificationSettingsTest | new green | 2 tests, 14 assertions | PASS |
| PHPStan L9 (full) | clean | No errors | PASS |
| twig-cs-fixer (email + settings tpl) | clean | 0 errors | PASS |
| Brand notifications panel render (strict_variables) | no 500, markers present | override+inherit OK | PASS |
| PHPUnit (full) | 552 + 8 | 560 pass, 0 fail (4 pre-existing notices) | PASS |

### SESSION CHECKPOINT (2026-06-20) - 2e DONE

Phase 1 (1a/1b/1c) + Phase 2a/2b/2d/2e COMPLETE & verified. Guardrails green (PHPStan L9, twig, 560 PHPUnit).
Nothing committed (branch `fixing`). REMAINING: 2c manual gateways (money-critical payment routing) -
to be done in a fresh focused session per RESUME.md.

## Session: 2026-06-20 (later) - Phase 2c manual gateways (money-critical)

### Money-routing trace COMPLETE (see findings.md "2c DISCOVERY")

- Funds route to the op_manual_gateways row whose merchant_id == $txn['merchant_id']. The account the
  customer pays to = that row's `instructions` (CONFIRMED via seed: "Send money to 01700000000...") +
  qr_code_path + logo_path. input_fields = customer-fill schema (often NULL in seed).
- Two live read sites: CheckoutController::show L192 + PaymentIntentCheckoutController::show L189, both
  `manualGw->forTenant($mid)->listActive()`. GatewayRendererService/ManualGatewayService are DEAD (no callers).
- ManualGatewayRepository = strict TenantScope, NO platform fallback today. Schema UNIQUE(merchant_id, slug)
  → platform template + brand account can share a slug. Admin getActiveBrandId()=0 in global view.

### USER DECISION (money edge): fallback to PLATFORM account

When a brand has no own account for a template, checkout falls back to the platform template's account.
Resolver: merge(platform active ∪ brand active) by slug, BRAND wins; platform = fallback. Legacy brand
rows preserved (back-compat). TEMPLATE fields (platform) vs ACCOUNT fields (brand: instructions/qr/logo).

### Implementing (TDD, money path first). Design locked + attested in task_plan.md "2c"

### Phase 2c COMPLETE & verified (2026-06-20)

Implemented model A (platform templates + per-brand accounts) money-path-first, test-driven:

- MONEY: ManualGatewayRepository::listActiveForCheckout($brandId,$platformId) - single query over
  merchant_id IN(brand,platform), status='active', collapse per slug with BRAND row winning, platform
  template as fallback. Wired into CheckoutController::show + PaymentIntentCheckoutController::show
  (resolve platformId via the already-present $brandCtx->getPlatformId()).
- GOVERNANCE: createManual now requireGlobalView (All-Brands-only, guards GET+POST → closes brand
  direct-URL) and writes via getWriteMerchantId() (=platformId in global). editManual/toggle/delete +
  index switched from getActiveBrandId() to getWriteMerchantId() owner (global manages templates;
  brand manages own rows). API-gateway list kept on the real brand id (apiContextId) - unchanged.
- BRAND CONFIG: new configureAccount(slug) GET+POST (brand-only) upserts a brand-owned row for a
  platform template slug (copies TYPE fields on first save; account = instructions/QR/logo). New routes
  /admin/gateways/configure/{slug} (GET+POST). New templates/admin/gateways/configure-account.twig.
- UI: index.twig action/badge discriminator = (is_template AND NOT is_own) → "Configure account" only
  for a brand's unconfigured platform templates; everything owned gets Edit/Toggle/Delete. (Render
  harness initially caught a bug: global templates were is_template=true too → wrongly showed Configure;
  fixed by adding the NOT is_own clause.)
- NO schema change (reuses op_manual_gateways + the is_platform owner row from 2a; UNIQUE(merchant_id,slug)
  lets template + brand account share a slug).
- ARCHITECTURE.md §4.10 added.

### Test Results (2026-06-20, Phase 2c)

| Check | Expected | Actual | Status |
|-------|----------|--------|--------|
| ManualGatewayRoutingTest (money) | new green | 5 tests, 14 assertions | PASS |
| GatewayGovernanceTest (gov+config) | new green | 5 tests, 19 assertions | PASS |
| Before/after routing harness (demo) | brand acct identical; brand wins; unconfigured→platform | as expected | PASS |
| Render check (global+brand index, configure GET, strict_variables) | no 500, markers correct | 13/13 | PASS |
| PHPStan L9 (full) | clean | No errors | PASS |
| twig-cs-fixer | clean | 0 errors | PASS |
| PHPUnit (full) | 560 + 10 | 570 pass, 0 fail (4 pre-existing notices) | PASS |

### SESSION CHECKPOINT (2026-06-20) - 2c DONE → PHASE 2 COMPLETE

Phase 1 (1a/1b/1c) + Phase 2 (2a/2b/2c/2d/2e) ALL COMPLETE & verified. Guardrails green (PHPStan L9,
twig, 570 PHPUnit). Nothing committed (branch `fixing`; large pre-existing WIP in tree - do NOT git add .).
One-off verification harnesses (_verify_2c_*.php) were used then deleted.
