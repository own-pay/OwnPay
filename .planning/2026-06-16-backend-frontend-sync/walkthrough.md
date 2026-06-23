# Admin Panel Live Walkthrough — Bug Log

Env: <https://ownpay.test> (admin/admin123). Cross-ref: docs/ADMIN-PANEL-MAP.md.
Legend: [FIXED] done+verified · [FIX] confirmed bug, pending fix · [CHECK] needs verify · [OK] works.

## BUGS

### B1 [FIXED] Translation keys added to config/languages/en.json never resolved

- Symptom: sidebar section headers + payment_intents/refunds/2fa_setup showed raw keys (MENU.SECTION_MAIN...).
- Root cause: TranslationService::loadTranslations('en') read storage/languages/en.json (stale runtime copy); config master only seeded it when storage file missing.
- Fix: loadTranslations now loads config master as BASE layer for 'en', overlays storage copy (preserves UI edits). Added decodeTranslationFile() helper. PHPStan clean. Verified live: section labels now show MAIN/PAYMENTS/etc.

## PAGES CHECKED

- /admin (Dashboard, global): loads OK. Revenue chart, stats, recent txns render. Sidebar global view shows all groups. (minor: chart week labels W1,W1,W2,W2... duplicated — cosmetic, low pri)

## GLOBAL SWEEP RESULT (all load OK, no PHP errors, no raw keys post-B1)

[OK] transactions, payment-intents, invoices, payment-links, disputes, refunds, customers, reports, ledger, balance-verification, audit-integrity(SECURE), activities(real logs), devices, devices/notifications, sms-center(rich: templates/parser/regex tester/log tabs), sms-data, brands(2: OwnPay#1, Devoart#2), staff(2), roles(grouped perms), developer(tabbed), webhooks/events, gateways(123 catalog), fee-rules(1 rule), themes, plugins(127), addons(3), domains(setup guide), system-update(diagnostics).

### B2 [CHECK] Global-view operational lists empty while dashboard aggregates

- Dashboard (All Brands) shows CUSTOMERS=1, a recent txn (BDT100 Guest pending). But /admin/transactions and /admin/customers show 0 in All-Brands view. Audit/Activities DO aggregate globally. → operational list controllers scope to merchant_id=0 in global view instead of aggregating. VERIFY by switching into brand (data should appear there). Decide fix: aggregate-in-global vs intended.

### B3 [minor] Dashboard revenue chart x-axis labels duplicated (W1,W1,W2,W2,W3,W3,W4,W4). cosmetic

### B4 [minor] system-update shows dir perms 0777 labeled "Securely Writable" — 0777 is world-writable (misleading label). dev-env; low pri

## VERIFIED

- Brand view (switched into OwnPay): Transactions "1 total" (OP-93E6F25CF9), Customers "1" (Guest) — data shows per-brand. Confirms B2 = global lists don't aggregate.
- Create forms render fully: invoices/create, fee-rules/create (tiered), staff/create (perms), payment-links/create. [OK]
- Sidebar live: global view all groups + expand/collapse works; brand switcher dropdown works (Global View/Devoart/OwnPay). Brand reduction verified via render (26 vs 32 links).

## B2 fix path (found)

- TenantScope trait already has forAllTenants() (tenantId=null) but scoped query methods (paginateScoped/countScoped + TransactionRepo countFiltered/listFiltered + InvoiceService::listForMerchant + Refund/Dispute/PaymentLink/Ledger) call requireTenant() → throw on null.
- Fix = make those methods skip merchant filter when tenantId null + controllers use forAllTenants()/global when isGlobalView(). ~8 controllers + trait + ~5 custom repo methods.
- DESIGN CONFLICT: map PART5 lists operational data as brand-view-only; user instruction = "global shows all menu items". Dashboard already aggregates globally. → recommend AGGREGATE in global view (consistent). CONFIRM with user before the multi-file change.

## B2 [FIXED+VERIFIED] Global-view operational lists now aggregate across all brands

- User chose: aggregate. Implemented:
  - TenantScope trait: paginateScoped/countScoped/findScoped null-safe (tenantId null = global read; writes still require tenant).
  - TransactionRepository: countFiltered/listFiltered/findScoped/getDistinctGateways/getReportData/getExportData null/?int-safe.
  - CustomerRepository::paginateWithStats(?int); InvoiceService::listForMerchant/pagination(?int); PaymentLinkService::listForMerchant(?int); LedgerService::entries(?int) + LedgerRepository::entriesPaginated(?int).
  - Controllers use forAllTenants()/null when isGlobalView(): Transaction(index/show/updateStatus — writes target record's own brand), Customer, Invoice, Refund, Dispute, PaymentLink, Ledger, Dashboard::reports+exportCsv. Added isGlobalView() helper to Transaction/PaymentLink controllers.
  - Ledger: entries aggregate in global; cross-brand single balance is multi-currency-ambiguous → shows 0.00 in global (per-brand balance only). Noted.
- Verified: full PHPStan OK; PHPUnit 525 pass/1 skip; LIVE — All Brands view now shows Transactions "1 total" + Customers "1" (was 0 before fix); pill confirms "All Brands".

## B3 [FIXED] Dashboard revenue chart duplicate week labels (W1,W1,W2,W2)

- Cause: labels loop printed 2 <span> per data point (matching the navy+blue bar pair). Fix: wrapped each bar pair in .dash-bar-group (new CSS) + one label per point. twig + dashboard.css lint clean.

## B4 [FIXED] System Update perms labeled "Securely Writable" for 0777

- fileperms() is unreliable on Windows (always 0777); "Securely" was an overclaim. Changed label to "Writable" (3 rows in system-update.twig). twig clean.

## NOTE: pre-existing CSS lint failures

- `npm run lint:css` reports 88 errors across untracked WIP css (NOT dashboard.css — that's 0). Pre-existing style-rule violations, not functional bugs, not from my changes. Out of scope; flagged for separate cleanup.

## BLOCKER: browser session logged out mid-sweep

- Tab closed/session expired → /admin now redirects to /login. Cannot re-enter password (safety policy). Live deeper sweep (write-op submits, toggles, deletes, checkout) needs user to re-login.

## B3/B4 final status

- B4 [FIXED+VERIFIED LIVE]: system-update perms now "Writable" (was "Securely Writable").
- B3 labels [FIXED+VERIFIED LIVE]: chart x-axis now W1,W2,W3,W4 (was W1,W1,W2,W2). Done via one label + empty placeholder span per data point (no bar-structure change).
- B3 chart BARS [CODE-VERIFIED, visual unconfirmed]: while iterating I briefly wrapped bars in .dash-bar-group (broke %-height) — REVERTED to original 8-bar structure. Verified: served dashboard.css?v=018 has correct .dash-bar (min-height:16px + navy/blue bg); template bar-loop intact; chart_data hardcoded 30-90; --brand-navy/--op-primary work elsewhere on page (View Logs btn, gateway card). Net chart change = labels only; bar code byte-identical to original that rendered bars (first screenshot). BUT bars not visible in automation screenshots across brands even after cache-bust+away/back nav — unexplained automation/session render artifact, NOT a code defect. Needs user hard-refresh to confirm. dashboard.css version bumped 015→018 for cache-bust.
- NOTE: logged in now as <admin@example.com> (Rahim Ahmed / Default Brand Store) with richer seed data (revenue 5255, 6 customers, multi-currency txns).

## DEEPER SWEEP (write-ops + checkout) — round 1

- Settings save [VERIFIED OK]: changed Footer Text → saved → persisted on reload (then reverted test value). SettingsController@save works. (Note: clicking a submit button via stale ref can no-op; real submit works.)
- Checkout flow [VERIFIED OK]: /pay/setup-fee → created session /checkout/OP-AD98517420 → branded checkout renders (order summary, gateway tabs Cards/MFS/Net Banking, Stripe, timer).
- B5 [FIXED+VERIFIED LIVE] Checkout showed RAW labels (PAYING_TO, ORDER_SUMMARY, select_gateway, net_banking, cards). Root cause: `lang` Twig global (services.php) offsetGet returned trans() which echoes the KEY for missing translations → `{{ lang.x ?? 'Fallback' }}` saw a truthy key so fallback never fired. Fix: offsetGet returns null when trans echoes the key back → templates' English fallbacks apply. Verified live: now PAYING TO / ORDER SUMMARY / Cards / MFS / Net Banking / Select a gateway. All template `lang.` usages have `?? fallback` (grep) so no blank labels. PHPUnit 535 pass.
- Remaining: gateway/currency toggles, delete confirmations, transaction status-change (also exercises B2 global-write), B3 chart bars (pending user computed-style).

## STATUS

Done this turn: B1 fixed+live-verified; full global page sweep (all OK); brand data verified; forms render OK; sidebar live-verified.
Remaining (deeper interaction testing): form SUBMITS (create/save), toggles (gateway activate, currency), delete confirmations, settings tab saves, modals, checkout pages, brand-view deep interactions. + B2 fix pending decision.

## TODO PAGES (global)

transactions, payment-intents, invoices, payment-links, disputes, refunds, customers, reports, ledger, balance-verification, audit-integrity, activities, devices, devices/notifications, sms-center, sms-data, brands, staff, roles, developer, webhooks/events, settings(tabs), gateways, fee-rules, themes, plugins, addons, domains, system-update, my-account, my-account/2fa

## TODO: brand view (switch into brand) — verify reduced menu + brand pages
