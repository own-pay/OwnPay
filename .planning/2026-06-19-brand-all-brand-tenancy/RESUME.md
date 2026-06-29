# RESUME - starting 2e and 2c as fresh focused sessions

Phase 1 + Phase 2a/2b/2d are DONE and verified. Two pieces remain, each fully designed in
`task_plan.md`: **2e** (email pipeline + per-brand sender/prefs) and **2c** (manual gateways:
template + per-brand account, money-critical). Run them as SEPARATE fresh sessions.

## How to start a fresh session

1. In your current Claude Code terminal run **`/clear`** (resets the context window) - OR open a new
   terminal and run **`claude`**. Either gives a clean context.
2. On start, the `planning-with-files` skill auto-restores the active plan. The active plan pointer
   (`.planning/.active_plan`) is already set to **`2026-06-19-brand-all-brand-tenancy`**, so the new
   session reads this task_plan/findings/progress automatically. (To confirm/switch:
   `sh .agents/skills/planning-with-files/scripts/set-active-plan.sh 2026-06-19-brand-all-brand-tenancy`.)
3. Paste the matching kickoff prompt below. Do 2e first, then 2c in another fresh session (or vice
   versa). Keep them in separate sessions so each stays focused.

> Both live in the SAME plan, so do them one at a time. If you'd rather fully isolate them, create a
> dedicated plan first: `sh .agents/skills/planning-with-files/scripts/init-session.sh "email pipeline"`
> then copy the 2e design from this task_plan into the new plan.

## Kickoff prompt - 2e (email pipeline)

```
Resume plan 2026-06-19-brand-all-brand-tenancy. Implement Phase 2e (email pipeline + per-brand
sender/prefs) exactly per the "2e" build plan in task_plan.md. Test-driven. Hard rule: wrap all email
sending so a failure can NEVER disrupt payment completion (try/catch + log). Per-brand 'from' and the
email_on_payment/email_on_refund prefs resolve via SettingsRepository::getScoped (brand → global →
default). Add the brand-view + global settings UI for from name/email + notification email + toggles.
Keep PHPStan level 9 clean and the full PHPUnit suite green (552+). Clear storage/cache/twig after any
template edit. Verify before claiming done.
```

## Kickoff prompt - 2c (manual gateways, money-critical)

```
Resume plan 2026-06-19-brand-all-brand-tenancy. Implement Phase 2c (manual gateways: template +
per-brand account) per the decision in task_plan.md: All Brands defines the gateway TYPE/default; each
BRAND sets its OWN account; customer payments to a brand route to THAT brand's account; brands cannot
create gateway types. FIRST trace the checkout/payment path end-to-end (Checkout controllers →
GatewayRendererService → where the manual account/instructions are read) and write down exactly where
funds are routed. Change behind verification; confirm a brand's payment uses the brand's account both
before and after. Enforce createManual = All-Brands-only (requireGlobalView) + close the brand
direct-URL. Keep PHPStan L9 + full PHPUnit green. This is money-critical - do not guess.
```

## Shared environment / verify notes

- App: <https://ownpay.test> (Laragon). Root: C:\laragon\www\ownpay. Branch: `fixing`. PHP 8.3.28.
- DB (dev): mysql root/root, db `ownpay`; tests use `ownpay_test`. Migrations: SQL files in
  database/migrations/ (next = 015), tracked in op_migrations; apply manually in dev to BOTH dbs.
- Verify: `php vendor/bin/phpstan analyse --no-progress` (level 9, clean) ·
  `php vendor/bin/phpunit --no-progress` (552+ pass) · `php vendor/bin/twig-cs-fixer lint templates`.
- Twig caches compiled templates → after template edits run `rm -rf storage/cache/twig/*` (PowerShell
  Remove-Item is sandbox-blocked on storage; use the Bash tool).
- After editing task_plan.md, re-run
  `powershell -ExecutionPolicy Bypass -File .agents\skills\planning-with-files\scripts\attest-plan.ps1`.
- Platform-owner row: resolve via `BrandContext::getPlatformId()` (is_platform=1) - id differs per DB
  (ownpay=2, ownpay_test varies); never hard-code it.
- Nothing is committed yet (branch `fixing`); the working tree also carries large pre-existing WIP from
  prior sessions - your edits are a small subset, so never `git add .` blindly.

## Design references (already written)

- task_plan.md → "PHASE 2" → the 2e build plan + the 2c decision/sketch.
- findings.md → "2e DISCOVERY" (email dormant; events/wiring points) + tenancy architecture map.
