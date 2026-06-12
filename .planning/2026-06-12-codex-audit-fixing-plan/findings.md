# Findings & Decisions

## Requirements
- Create a detailed fixing plan for a developer.
- Save it in `docs/v2/audit_fundings_codex/`, the same directory as the audit report.
- Cover all confirmed findings from `ownpay_master_audit_report.md`.
- Include enough detail to guide fixes, tests, rollout, and stability hardening.
- Do not implement application fixes during this task.
- Create a required timestamped change log under `output/change-log/`.

## Research Findings
- Existing report path: `docs/v2/audit_fundings_codex/ownpay_master_audit_report.md`.
- Target fixing plan path selected: `docs/v2/audit_fundings_codex/ownpay_master_audit_fixing_plan.md`.
- Target fixing plan did not exist before creation, so no snapshot is required.
- Report finding F-001: simulated webhook validation in 27 gateway adapters can be treated as verified webhook ingress.
- Report finding F-002: parsed SMS rows are stored as `accepted`, while verification jobs process only `pending`.
- Report finding F-003: ledger account lookup is currency-aware, but schema uniqueness is only `(merchant_id, name)`.
- Report finding F-004: rate-limit decision happens before atomic increment.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Treat "zero bugs" as zero known release-blocking defects | No static plan can mathematically guarantee absence of all bugs; the developer plan will define measurable gates. |
| Include exact likely files and commands | The next developer needs actionable implementation paths, not a generic checklist. |
| Include rollback and deployment gates | Findings affect payments, ledger, webhooks, and auth-sensitive rate limiting. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| The create-plan skill is read-only by default | User explicitly requested a saved artifact, so the plan structure is used while writing the requested file. |

## Resources
- `docs/v2/audit_fundings_codex/ownpay_master_audit_report.md`
- `docs/v2/Claude_audit/ownpay_master_audit_prompt.txt`
