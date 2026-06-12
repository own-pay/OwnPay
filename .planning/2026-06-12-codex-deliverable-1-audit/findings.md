# Findings & Decisions

## Requirements
- Deliverable scope is restricted to `ownpay_master_audit_report.md` only.
- Output folder is `docs/v2/audit_fundings_codex/` exactly.
- Do not create deliverables 2, 3, or 4.
- Do not modify application source code, schema, migrations, templates, configs, or runtime logic.
- Every finding must include exact file path, line range, call chain, and code excerpt.
- Every quest sub-question must be accounted for in Detailed Findings or Pass Log.

## Research Findings
- `public/index.php` is the single front controller and boots `OwnPay\Kernel`.
- Main route files are `config/routes/web.php` and `config/routes/api.php`.
- Main service registry is `config/services.php`.
- Database source of truth includes `database/schema.sql` plus migrations/seeds.
- CLI files discovered: `cli/build-update.php` and `cli/create-module.php`.
- Plugin manifests exist under `modules/addons/`, `modules/gateways/`, and `modules/themes/`.
- Existing `docs/v2/` contains prior audit folders, but this task writes to a new confirmed folder.
- Current route scan found public checkout, admin, cron, webhook, install, merchant API, mobile API, and admin API route groups.
- Current schema scan found 51 `op_` tables in `database/schema.sql`.
- Current module scan found 123 gateway modules, 3 addon modules, and 1 theme module.
- Candidate finding: webhook route delegates signature checks to gateway adapters while the middleware group only applies IP allowlisting.
- Candidate finding: several gateway adapters contain a simulated webhook signature validation path that returns `true` when a signature header is present.
- Candidate finding: SMS parser inserts successfully parsed rows as `accepted`, while verification jobs only process rows marked `pending`.

## Final Report Findings
| ID | Severity | Summary |
|----|----------|---------|
| F-001 | CRITICAL | 27 gateway adapters contain simulated webhook signature validation that can be treated as provider-verified webhook ingress. |
| F-002 | HIGH | Parsed SMS rows are inserted as `accepted`, while SMS verification jobs process only `pending`. |
| F-003 | HIGH | Ledger account lookup is currency-aware, but schema uniqueness is only `(merchant_id, name)`, causing multi-currency account conflicts. |
| F-004 | MEDIUM | Rate-limit allow/deny decision happens before atomic increment, allowing concurrent bursts over the limit. |

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Treat graphify as navigation only | Report evidence must come from direct file/line citations. |
| Snapshot report only if it exists | Target report did not exist at initialization. |
| Keep PHPUnit as manual gap | Configured suite targets local MySQL `ownpay_test` and can mutate test data; static read-only checks were preferred. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Target output folder spelling differs from prompt | User confirmed `audit_fundings_codex` should be used. |

## Resources
- `docs/v2/Claude_audit/ownpay_master_audit_prompt.txt`
- `ARCHITECTURE.md`
- `.agents/rules/*.md`
