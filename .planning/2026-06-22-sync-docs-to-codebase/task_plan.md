# Task Plan: Sync docs to current codebase

## Goal

Update the user-listed docs (AGENTS.md, ARCHITECTURE.md, docs/v2/api, docs/v2/model,
docs/v2/plugins/{developer-guide,hooks-reference}, .agents/rules) to match the current codebase.

## Current Phase

COMPLETE - all in-scope docs reviewed; stale/missing content updated; clean docs left as-is.

## What changed (deltas propagated)

Platform-row tenancy (is_platform, getPlatformId/getWriteMerchantId, All-Brands data ownership),
brands.access_all, manual-gateway template+account model, per-brand email pipeline, self-service
password reset (+ op_password_resets), dead-code removal (GatewayRendererService/ManualGatewayService/
WebhookRetryJob), version bumps (PHP 8.3, Twig 3.26).

## Edits made

- AGENTS.md: PHP 8.3+, Twig 3.26; All-Brands platform-scope bullet + brands.access_all.
- ARCHITECTURE.md: NEW §4.11 (All-Brands Platform Scope & Data Ownership); fixed §4.10 cross-ref.
  (§4.9 email, §4.10 manual gateways, §5.6 password reset already added in prior sessions.)
- docs/v2/model/business_model.md: appended "Current State (2026-06)" - platform row, write routing,
  config cascade, brands.access_all, manual gateways, per-brand email, Q2 resolution, op_settlements note.
- .agents/rules/business-model-scoping.md: NEW "Platform Scope & Data Ownership" + brands.access_all gate.
- .agents/rules/database-schema.md: NEW "Platform Scope & Auth Tables" (is_platform, op_password_resets).
- .agents/rules/security-cryptography.md: PHP 8.3+; Argon2id params match Authenticator; fixed
  `password`→`password_hash` column; reset-token rule now references op_password_resets/SHA-256/
  PasswordResetService/no-enumeration.

## Verified current - NO change needed

- docs/v2/api/ (README already documents refunds incl. GET /api/v1/refunds; public API unchanged by recent work).
- docs/v2/plugins/developer-guide.md + hooks-reference.md (plugin system unchanged; gateway.manual.* already
  removed in the dead-code cleanup; no stale refs).
- docs/v2/model/white-label-domain-pipeline.md (white-label pipeline unchanged).

## Out of scope (NOT edited, intentionally)

docs/v2/final_report/**, docs/v2/audit/**, docs/v2/audit_findings/**, docs/v2/plugins/gateways/** -
historical/point-in-time reports + not in the user's list. Their "PHP 8.2" refs are correct for their date.

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| Don't edit historical audit reports / gateway volumes | Point-in-time records; not user-listed; rewriting them would falsify history. |
| Append "Current State" to business_model.md rather than rewrite | Preserves the migration record while documenting the platform-row evolution. |
| Align security-cryptography Argon2id params to the code (threads=1) | Task is "sync docs to codebase"; doc must match Authenticator::hashPassword. |

## Errors Encountered

| Error | Resolution |
|-------|------------|
| security-cryptography.md said hashes go in `password` column | Code uses `password_hash` (MerchantUserRepository) - corrected the rule. |
