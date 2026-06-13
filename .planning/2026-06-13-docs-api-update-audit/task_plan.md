# Task Plan: Docs, API & System-Update Audit

## Goal
1. Verify/fix ARCHITECTURE.md against current codebase (remove misleading claims).
2. Create public docs/ARCHITECTURE.md for third-party developers.
3. Create docs/LOCAL_SETUP.md (Windows/macOS/Linux, ~2-min setup, Mermaid + official links).
4. Audit API endpoints + related code; fix what's wrong.
5. Audit system update pipeline (update/ server config, GitHub release zip as update +
   first-install artifact, cli/build-update.php builder); fix what's wrong.

## Current Phase
Phase 2

## Phases

### Phase 1: Scout & decisions
- [x] New plan dir created
- [x] git remote: own-pay/own-pay-dev (dev); manifest uses github.com/own-pay/OwnPay releases
- [x] update/ contents listed; private key NOT git-tracked (only public key + manifest)
- [x] API routes enumerated from config/routes/api.php (15 merchant, 11 mobile, 7 admin, 1 csp)
- [x] User decisions: public repo = own-pay/OwnPay; visuals = Mermaid + official links
- **Status:** complete

### Phase 2: Multi-agent audit (ultracode workflow)
- [x] First attempt (22 agents + verify) KILLED by 429 session limit at 07:16 — zero findings journaled
- [x] Update pipeline audited INLINE by me (UpdateService, build-update.php, manifest, installer, keys)
- [ ] Re-run LEANER workflow (~10 broad agents, no separate verify phase) for arch + API
- **Status:** in_progress

### Phase 3: Fixes
- [ ] Fix confirmed ARCHITECTURE.md inaccuracies
- [ ] Fix confirmed API bugs
- [ ] Fix confirmed update-pipeline bugs
- **Status:** pending

### Phase 4: New docs
- [ ] docs/ARCHITECTURE.md (public, third-party developer oriented, Mermaid)
- [ ] docs/LOCAL_SETUP.md (Win/macOS/Linux quick setup, Mermaid + official links)
- **Status:** pending

### Phase 5: Verification & delivery
- [ ] phpunit + phpstan + lint green
- [ ] Smoke test affected endpoints
- [ ] Update planning files, summary
- **Status:** pending

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Public repo URL: github.com/own-pay/OwnPay | User confirmed; matches manifest download_url |
| Visuals: Mermaid + official links | User confirmed; no link-rot/copyright risk |
| Audits via Workflow fan-out | Ultracode on; many independent claims/endpoints to verify |

## Errors Encountered
| Error | Resolution |
|-------|------------|
