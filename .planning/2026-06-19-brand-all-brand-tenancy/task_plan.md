# Task Plan: All-Brands / Brand Tenancy Model (super-admin parent + brand children)

## Goal

Make OwnPay's multi-tenancy match the user's model:

- All Brands = super-admin parent: full read/write across brands, configures whole app,
  its config is the FALLBACK for all brands; All-Brands-owned data readable only by All Brands.
- Brand = child: own data + brand-level config only; inherits All-Brands config unless overridden;
  cannot upload plugins or create manual gateways (All-Brands-only).
- Dedicated Inbound Webhook/IPN page (plain-English). Staff All-Brands-access permission.
- Brand feature/settings parity. Read actual code; no guessing. Plan-first; ask on ambiguity.

## Constraints

- Windows/PowerShell. PHP 8.3, strict_types. Twig strict_variables. Keep guardrails green
  (PHPStan L9, ~552 PHPUnit, twig lint). Match existing architecture (TenantScope, SettingsRepository
  NULL=global pattern, BrandContext, AdminPageTrait). Server-side enforcement + CSRF + perms.
- This supersedes the earlier "BUG#2 global-view-create guard" (built on the wrong model).

## Current Phase

COMPLETE - Phase 1 (1a/1b/1c) + Phase 2 (2a/2b/2c/2d/2e) all done & verified (PHPStan L9, twig, 570 PHPUnit).
Post-completion audit (2026-06-22): schema.sql verified IN SYNC with the live DB (fresh-build column-level diff
empty; tables match); no TODO/FIXME/stub markers in src/; the tenancy work introduced no dead code.
DEAD CODE REMOVED (2026-06-22, user approved): deleted src/Service/Payment/GatewayRendererService.php +
ManualGatewayService.php (no callers/DI/tests) and templates/checkout/partials/manual-gateway.twig (orphan;
live = _manual-popup.twig). Also retired the now-dead plugin hooks gateway.manual.render/verify from
config/hooks.php + docs/v2/plugins/hooks-reference.md (renumbered §7-11→§7-10) and fixed the stale template
name in .agents/rules/developer-workflows.md. Verified: PHPStan L9 clean, twig lint clean, 570 PHPUnit pass.

## DECISIONS (answered by user 2026-06-19)

- **D1 = Dedicated Platform row.** All-Brands-owned operational data will be owned by ONE reserved
  "Platform / All Brands" record in op_merchants. Brands (scoped to own id) never see it; All-Brands
  view (unfiltered) sees everything. id-0 "All Brands" view maps to this row for writes. (Phase 2.)
- **D3 = Lower-risk first.** Phase 1 (this) = brand restrictions + Inbound Webhook page + brand parity.
  Phase 2 = data-ownership (Platform row) + API isolation.
- **Context from user:** Brand custom domain already works for checkout/API/admin (existing flow, keep).
  "Current API flow is suitable now" → do NOT change API flow in Phase 1; API isolation is Phase 2.
- **Interim:** the earlier global-view-create guard stays as a 500-preventer until Phase 2 replaces it
  with real All-Brands-owned create via the Platform row.

## PHASE 1 (active)

### 1a: Brand restrictions (G2) - global-only actions - COMPLETE

- [x] Added AdminPageTrait::isGlobalBrandView() + requireGlobalView() guard.
- [x] Server-side enforced (redirect+flash in brand view) on PluginController installForm/upload/
      cancelUpload/uninstall/trash/restore + ThemeController installForm/upload/uninstall. (op_plugins
      is global → clean, no ownership dependency.)
- [x] Passed is_global_view to plugins/themes/addons/gateways index templates.
- [x] UI hints via new partial templates/admin/partials/_all_brands_only.twig: brand view hides the
      add/upload button + shows "switch to All Brands" notice on Plugins/Themes/Addons/Gateways.
- [x] VERIFIED (render_check both views): GLOBAL=button shown/notice hidden/install 200;
      BRAND=button hidden/notice shown/install 302. PHPStan L9 clean, twig lint clean, PHPUnit 552 pass.
- **GOTCHA fixed:** Twig `x|default(true)` treats false as empty → always true; used `x ?? true` instead.
- **GOTCHA:** Twig caches compiled templates (storage/cache/twig); cleared after edits (prod deploy must clear).
- **DEFERRED to Phase 2:** manual-gateway *server enforcement* + All-Brands platform storage
  (op_manual_gateways.merchant_id is NOT NULL → an All-Brands gateway needs the Platform-owner row).
  Phase 1 shows the brand-view hint + hides the button; createManual still has the interim
  requireActiveBrand guard (direct-URL create in brand view remains until Phase 2 closes it).
- **Status:** complete

### 1b: Inbound Webhook / IPN page (G3) - COMPLETE

- [x] New page GET /admin/gateway-webhooks → Admin\GatewayWebhookController@index.
- [x] Sidebar entry "Gateway Webhooks (IPN)" under Developers (active_page gateway_webhooks).
- [x] Plain-English template: what-is-it, your-URL (with copy buttons via data-copy), setup steps, notes.
- [x] URL logic via DomainUrlService: All Brands → main/APP_URL; brand w/ custom domain → brand domain;
      brand w/o domain → notice (add domain in /admin/domains) + main URL fallback. Endpoint unchanged
      (POST /webhook/{gateway} → UnifiedWebhookController).
- [x] PermissionMiddleware: mapped /admin/gateway-webhooks → api_keys.view.
- [x] **FIX (essential for 1a):** removed /admin/plugins + /admin/addons from $globalOnlyPrefixes so
      brands can view them + activate per-brand + see the upload hints (was fully blocked in brand view,
      contradicting the per-brand plugin model + the user's "addon page in brand shows hint" requirement).
      Platform-only plugin actions remain blocked via per-action requireGlobalView guards.
- [x] VERIFIED: render both views (GLOBAL=URLs/no-notice; BRAND no-domain=URLs+notice); PHPStan L9 clean;
      twig lint clean; PHPUnit 552 pass.
- **Status:** complete

### 1c: Brand feature/settings parity (G5) - AUDITED, already implemented

- [x] Audited SettingsController (index/save/upload) + settings/index.twig tab gating.
- FINDING: brand parity is ALREADY implemented (by the 2026-06-18 restructure). Brand view exposes
  brand-scoped: **branding** (logo+favicon upload, colors, support email, footer, brand profile
  name/email/phone/timezone/currency), **checkout** (success/pending/failed msgs, timer, FAQ toggle,
  custom CSS/JS for superadmin), **domains** (custom domain), **faq**, **payment** (currency; rates
  read-only). Saved to op_merchants.settings JSON + profile fields + brandSettings.favicon (upload()).
  Platform-only tabs (general/system, landing, email/SMTP, notification, security, cron, languages,
  queue, optimization) correctly gated to All Brands. NO fixes required.
- [ ] OPEN (user's product call): any ADDITIONAL brand-appropriate features wanted? Candidates not
  currently brand-overridable: per-brand email "from" name/address; per-brand notification-event prefs.
- **Status:** complete (audit) - pending user input on optional additions.

### Phase 1 verification - DONE

- [x] PHPStan L9 clean, twig lint clean, PHPUnit 552 pass (after 1a + 1b). 1c added no code (audit only).
- [x] render_check both views for plugins/themes/addons/gateways/gateway-webhooks.

## PHASE 2 - Data ownership + API isolation (user APPROVED 2026-06-19; plan-then-implement, verify each step)

### Design (D1 = Platform-owner row)

- Add `is_platform TINYINT(1) NOT NULL DEFAULT 0` to op_merchants. Seed ONE platform row
  (name 'All Brands (Platform)', slug '**platform**', is_platform=1). Migration 013 + schema.sql.
- BrandContext::getPlatformId() → id of is_platform=1 row (cached). getWriteMerchantId() → platformId
  in global view, else active brand id.
- TWO scoping behaviors:
  - ISOLATED (operational data: customers/invoices/transactions/payment_intents/payment_links/refunds/
    disputes): brand reads only own; All-Brands data (merchant_id=platformId) invisible to brands;
    All-Brands view (tenantId=null) sees everything. Existing TenantScope already does the reads;
    only All-Brands CREATE needs to use platformId (replaces interim requireActiveBrand guards).
  - FALLBACK-SHARED (config-like, already works for settings/gateway_configs via NULL=global).

### 2a: Platform-owner row foundation - COMPLETE

- [x] Migration 013 (is_platform col + seeded platform row) applied to ownpay (id=2) + ownpay_test
      (id=101998 - confirms id varies per DB, always resolve via is_platform flag). schema.sql updated.
- [x] BrandContext: getPlatformId() (lazy-safe; resolves is_platform=1; auto-seeds if missing),
      getWriteMerchantId() (global→platformId, brand→brand id), getAllBrands() excludes is_platform=1.
- [x] VERIFIED: platform id=2; excluded from brand switcher (getAllBrands ids=[1]); write-routing
      global→2 / brand→1; PHPStan L9 clean; PHPUnit 552 pass.
- DECISION: the interim "select a brand to create" guards on admin invoice/customer/payment-link/role
  create are KEPT as the correct final behavior (per-brand records are created within a brand context;
  you can't create an invoice for "all brands"). Platform-owned operational data is created via the
  All-Brands API key path (2b), where getWriteMerchantId()/platformId is applied. No guard removal.

### 2b: API-key data isolation - COMPLETE

- [x] ApiKeyController generate/revoke + DeveloperController index key-list now use getWriteMerchantId():
      All Brands view → platform-owned keys (merchant_id=platformId); brand view → brand keys. Data
      created via an All-Brands key is platform-owned → isolated to All Brands via existing TenantScope;
      brand-key data is brand-owned → readable by brand + All Brands (unfiltered All-Brands reads).
- [x] Updated 2 tests (ApiKeyApiSecurityTest) that asserted the OLD "block in global view" model to the
      new behavior (global view manages platform keys). PHPStan L9 clean; PHPUnit 552 pass.
- NOTE: an All-Brands API key reads/writes platform-owned data (scoped to platformId), not all brands'
  data - cross-brand read remains the admin All-Brands VIEW. (Flag if user wants All-Brands keys to read all.)

### 2c: Manual gateways - COMPLETE & verified (money-critical; 2026-06-20)

USER DECISION (2026-06-20, money-routing edge): when a brand has NOT configured its own account for a
platform template, checkout FALLS BACK TO THE PLATFORM ACCOUNT (literal reading). Resolver:
effective(brand $mid) = MERGE(platform active templates ∪ brand active rows) keyed by slug, BRAND row
WINS per slug; platform template (with its own account) is the fallback. Brand-only legacy slugs kept.

BUILD PLAN (test-driven; money path behind before/after verification) - ALL DONE:

- [x] (money) ManualGatewayRepository::listActiveForCheckout(int $brandId, int $platformId): merge query
      (status='active', merchant_id IN (brand,platform)); brand row overrides platform per slug; sort_order.
- [x] (money) CheckoutController::show + PaymentIntentCheckoutController::show → replaced
      manualGw->forTenant($mid)->listActive() with listActiveForCheckout($mid, getPlatformId()).
- [x] (gov) GatewayController::createManual → requireGlobalView (All-Brands-only) + write to
      getWriteMerchantId() (=platformId in global). Interim requireActiveBrand(0)→redirect removed.
- [x] (gov) Closed brand direct-URL: requireGlobalView guards BOTH GET create-manual + POST store-manual.
- [x] (admin) index/editManual/toggle/delete use getWriteMerchantId() owner (global→platform templates,
      brand→brand rows). Brand view lists platform templates (configurable) + own rows. API list keeps
      the real brand id (apiContextId) so per-brand plugin activation behaviour is unchanged.
- [x] (brand) configureAccount(slug) GET+POST (brand-only): upsert brand-owned row for a platform
      template slug (copies template TYPE fields on first save). New routes (configure/{slug} GET+POST).
- [x] (ui) gateways index.twig: discriminator = is_template AND NOT is_own → "Configure account" only for
      a brand's unconfigured platform templates; global templates + own rows get Edit/Toggle/Delete.
      New configure-account.twig (instructions + QR/logo). Reused _all_brands_only hint (Phase 1a).
- [x] (test) tests/Integration/ManualGatewayRoutingTest (5) + GatewayGovernanceTest (5) - brand-own wins,
      platform fallback, inactive hidden, legacy kept, brand-inactive→platform; createManual brand-blocked/
      global-creates-template; configureAccount upsert (no duplicate) + global-blocked.
- [x] (verify) before/after harness PASS (brand account byte-identical pre/post; brand wins over platform
      default; unconfigured→platform fallback). Render check 13/13 both views + configure. NO schema change.

RESULT: PHPStan L9 clean · twig-cs-fixer clean · PHPUnit 570 pass (was 560 +10 new), 0 fail. ARCHITECTURE.md §4.10.

TEMPLATE vs ACCOUNT field split: TYPE (platform, locked for brand) = slug, name, logo, colors, input
field SCHEMA, instructions, currency, min/max, sms config. ACCOUNT (brand-editable) = input-field VALUES
(payment_number), qr_code_path, optionally instructions. Brand row is self-contained (copied type + own
account) so the merge picks whole rows. Scope boundary: brand cannot disable a platform-active type
(platform controls availability); brand controls only its account. Empty-account = admin misconfig
(parity with current behavior; not hidden).

### 2d: Staff "access All Brands" permission (G4) - COMPLETE

- [x] Added permission `brands.access_all` (group 'people'): installer seed array (fresh installs) +
      migration 014 (existing installs, applied to ownpay + ownpay_test). Appears in roles UI (loaded
      from op_permissions). op_permissions.slug is unique → idempotent.
- [x] BrandController::switchBrand global gate = superadmin OR canAccessAllBrands($req) (checks the
      'brands.access_all' permission from request user_permissions). /admin/brands/switch already only
      needs brands.view (read-level), so the gate lives in switchBrand.
- [x] VERIFIED: PHPStan L9 clean; PHPUnit 552 pass.

### 2e: Email pipeline + per-brand sender/prefs (user chose "build the pipeline" 2026-06-19) - COMPLETE (2026-06-20)

STATUS: complete & verified (PHPStan L9, twig, 560 PHPUnit incl. 8 new: EmailNotificationTest + BrandNotificationSettingsTest). Details in
progress.md "Phase 2e (2026-06-20)". Key facts: EmailNotificationService (onTransactionCompleted/
onRefundCreated, each fully try/caught) wired in services.php system.boot @priority 20; `from` defaulting
CENTRALIZED in CommunicationService::sendEmail; reuse payment_received.twig + new refund_processed.twig;
brand "Email Notifications" settings tab (inherit/override) + new SettingsRepository::getScopedOverride;
ARCHITECTURE.md §4.9. CORRECTION captured: notif/sender keys are in settings group 'general' (not 'mail').
FINDINGS (fully traced): email is dormant (no sendEmail caller) BUT the plumbing exists:

- Events: payment.transaction.completed (TransactionService:113), refund.created (Api\RefundController:120),
  payment.transaction.failed. EventManager::addAction registers listeners.
- Wiring point: PaymentCompletionListener::onTransactionCompleted (services.php:595-597 registers it on
  payment.transaction.completed). Currently marks invoice paid + increments link use; NO email.
- Sending channel: `modules/addons/mail-gateway` listens to 'mail.send'; OR CommunicationService::sendEmail
  (resolveProvider('mail',$mid) → mail plugin, else fallbackMail/PHP mail).
- Settings: group 'general' has email_on_payment/email_on_refund/admin_notification_email (inert);
  group 'mail' has from_address/from_name (installer). getScoped gives brand→global→default cascade.
BUILD PLAN (test-driven) - ALL DONE:
- [x] EmailNotificationService: onTransactionCompleted + onRefundCreated → gate via getScoped('general',
      email_on_payment/email_on_refund,$mid) → to=getScoped('general','admin_notification_email',$mid) →
      body=rendered email template → CommunicationService::sendEmail($mid,msg). (from resolved in sendEmail.)
- [x] sendEmail(): when message['from'] empty, default from getScoped('general',mail_from_email/
      mail_from_name,$mid). fallbackMail already sets From when present (now populated).
- [x] Settings UI: brand "Email Notifications" tab (from name/email, notification email, inherit/on/off
      toggles) saved brand-scoped (setScoped/deleteSettingScoped 'general'); global tabs unchanged = platform fallback.
- [x] Email templates: reuse templates/email/payment_received.twig + new templates/email/refund_processed.twig.
- [x] Tests: tests/Integration/EmailNotificationTest.php (6) - pref gating, recipient + per-brand from
      resolution, refund path, EventManager wiring, malformed-payload safety. 558 green.
KEY CORRECTION vs original sketch: notif/sender keys live in settings group 'general' (mail_from_email/
mail_from_name/admin_notification_email/email_on_payment/email_on_refund), NOT group 'mail'.

### Manual gateway model - DECIDED (user 2026-06-19): (A) template + per-brand account

- All Brands defines the manual gateway TYPE + an optional default account (fallback).
- Each BRAND sets its OWN account details (its own bKash/Nagad number); brand cannot create new types.
- Customer payment to a brand routes to THAT brand's account. (Plugin-like: define once, configure per brand.)
- Implementation sketch: keep op_manual_gateways but distinguish platform-owned "templates"
  (merchant_id=platformId) from per-brand account overrides; brand gateway list = platform templates
  - brand's own account config; checkout uses the brand's account (fallback to platform default).
  Detailed design when starting 2c (verify against checkout/payment routing carefully - money-critical).

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| Plan-first + ask on D1–D3 | Foundational schema/semantics; wrong guess = large rework (user instruction). |

## Errors Encountered

| Error | Attempt | Resolution |
|-------|---------|------------|
