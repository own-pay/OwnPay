# Findings & Decisions

## Requirements
- Final report must be saved at `docs/v2/audit_findings/codex_audit.md`.
- Report must include audit methodology, architecture summary before findings, executive summary, findings, incomplete functionality register, and PHPStan output summary.
- Audit scope covers architecture, plugin system, security, database/data layer, business logic, UX flows, code quality, frontend, open-source readiness, and cross-cutting concerns.
- Every finding must be grounded in actual code reads; no filename-only inference.
- Project policy requires a timestamped artifact log in `output/change-log/`; snapshots go in `output/snapshots/` before major/high-impact edits or replacing an existing report.

## Research Findings
- `docs/v2/audit_findings/` does not currently exist; `docs/v2/audit_find/` exists separately and must not be treated as the requested destination.
- `composer.json` defines validation commands for Composer audit, PHPUnit, PHPStan, Twig lint, JS lint, CSS lint, and JSON lint.
- `phpstan.neon` is configured at level 9 for `cli`, `config`, `modules`, and `src`.
- Existing active planning work was for `2026-05-29-business-logic-gaps-audit`; this audit uses a fresh active plan `2026-05-30-final-pre-release-audit`.
- Code inventory includes 1,087 files under `src`, `config`, `database`, `modules`, `public`, `templates`, `tests`, and `docs`.
- Highest-density core areas are repositories, admin controllers, payment services, middleware, cron jobs, and system services.
- Web routes expose public landing/auth, checkout, admin dashboard/actions, cron, unified webhooks, CSP reporting, and install wizard routes.
- API routes expose merchant REST API, mobile JWT API, and admin API endpoints. The admin API group uses the same `api` middleware label as merchant API routes in `config/routes/api.php`.
- `config/services.php` wires the PSR-style container, PDO, database wrapper, Twig, repositories, auth/payment/domain/theme services, plugin loader, queues, cache, webhook processor, and update system.
- Automated validation results: Composer audit clean; PHPStan level 9 clean; PHPUnit passes 454 tests / 1459 assertions but emits 3 PHPUnit notices in `tests/Integration/FinancialLeakageAuditTest.php`; Twig lint clean; JS lint clean; CSS lint clean; JSON lint fails because ESLint tries to open missing `.antigravitycli/df5facbb-5d32-4966-9612-544bd9cb39c2.json`.
- Admin API routes in `config/routes/api.php` are registered under the merchant `api` middleware group. `config/middleware.php` maps `api` to CORS, rate limiting, bearer auth, and idempotency only; `BearerAuthMiddleware` validates merchant API-key scope and sets `merchant_id`, but does not enforce admin session state, 2FA, or RBAC permissions.
- `SmsVerificationJob` still intentionally falls back from tenant-scoped SMS matching to `forAllTenants()` for both transaction ID and amount/gateway matching, then rewrites `op_sms_parsed.merchant_id` to the matched transaction's merchant.
- `TwigExtensions::hook()` returns HTML-safe hook output but strips high-risk tags and inline event handlers first, so raw hook rendering is an extension-boundary risk rather than a currently unsanitized raw-output issue.
- `PluginSandbox` validates paths and SQL and lists dangerous PHP functions, but enforcement depends on loader/static scanning paths; the sandbox class alone does not isolate arbitrary runtime PHP once plugin code is included.
- `public/api-tester.php` is a standalone browser API playground in the public web root, loads third-party CDN assets, and asks users to paste bearer tokens.
- `op-fetch.js` provides a reusable fragment loader that writes fetched text directly to `innerHTML`; this is safe only if every fragment endpoint is same-origin, authenticated, and server-escaped.
- `BackupService::restore()` extracts `code.zip` directly to the application root without enumerating ZIP entries first. `UpdateService::extractPackage()` does enumerate entries and rejects `..` and absolute paths before extraction, so the restore path is the unsafe one.
- Gateway webhook verification is fail-open by default in `GatewayDefaults`, and examples such as Stripe/Razorpay return true when `webhook_secret` is empty.
- `RequestSignatureMiddleware` is applied before unified gateway webhooks and only accepts OwnPay-style `X-Signature`, `X-Hub-Signature-256`, or query `signature`, which can block native gateway webhook headers before adapter-specific verification.
- `AuditLogRepository` falls back to the hardcoded HMAC secret `default_audit_hmac_secret_key_2026` when `AUDIT_HMAC_SECRET` is absent.
- Plugin route manifests are not automatically registered. `Router::loadRoutes()` fires `system.routes.register`, but the bundled Telegram Bot only declares a route in `manifest.json` and does not subscribe to that hook.
- The public web root includes tracked `public/api-tester.php`; `public/index.php` still states it is the only PHP file in `public/`, and `.htaccess` serves existing files directly.
- Release packaging includes tracked scratch/temp/storage/upload artifacts, and an untracked `update_private_key.pem` exists in the repository root.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| No runtime code edits | The approved plan is audit/report only. |
| Preserve prior planning artifacts | Prior plans are historical evidence and should not be overwritten. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Initial generated plan template was generic | Replaced with the concrete audit phases from the approved plan. |
| First broad file inventory output was too large for direct review | Use targeted follow-up reads and searches for evidence-grade findings. |
| `npm run lint:json` failed on a missing `.antigravitycli` JSON file | Record as release-readiness/tooling finding unless later inspection proves it is local-only and safely ignored. |
| A raw Twig hook concern was initially broader than the current code proves | Downgraded because `TwigExtensions::hook()` performs script/tag/event sanitization before returning hook output. |
| Plugin sandbox concern needed refinement | The loader and database wrapper do enforce token scanning and plugin-owned SQL validation, so plugin findings should target concrete broken contracts instead of generic sandbox weakness. |

## Resources
- `docs/audit_task.txt`
- `AGENTS.md`
- `ARCHITECTURE.md`
- `.agents/rules/security-audit.md`
- `.agents/rules/security-audit-part2.md`
- `.agents/rules/security-cryptography.md`
- `.agents/rules/planning-with-files.md`
- `graphify-out/graph.json`
