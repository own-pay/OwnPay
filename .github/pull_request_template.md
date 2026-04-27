## Summary
<!-- What does this PR change and why? Keep it concise. -->

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Refactor
- [ ] Security hardening
- [ ] Performance improvement
- [ ] Documentation
- [ ] Chore/maintenance

## Scope
- [ ] Core (`src/`)
- [ ] Gateway module (`app/modules/gateways`)
- [ ] Addon module (`app/modules/addons`)
- [ ] Theme module (`app/modules/themes`)
- [ ] Other (describe below)

### Scope Details
<!-- List primary files/directories changed -->

## Security Checklist (Required)
- [ ] No dynamic file operations (`include`, `require`, `unlink`, `rmdir`, etc.) using untrusted paths.
- [ ] Any path-based file operation uses `realpath()` and enforces allowed-directory boundaries.
- [ ] All untrusted/user-supplied output rendered in HTML is escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- [ ] No unsafe command execution (`shell_exec`, `exec`, `system`, backticks) with user-controlled input.
- [ ] Dynamic class/module loading uses strict allowlist validation for identifiers/slugs.
- [ ] No secrets/tokens/credentials introduced in code, configs, logs, or screenshots.

## OwnPay Architecture Checklist (Required)
- [ ] No legacy `pp-` or `pp_` naming introduced.
- [ ] Feature logic is placed in the correct module/core location.
- [ ] No module-specific leakage into core unless explicitly justified.
- [ ] New dependencies (if any) are added via Composer (not raw vendored code/submodules).

## Database & Standards Checklist (Required)
- [ ] No hardcoded legacy table prefixes; DB prefixing remains dynamic (default `op_`).
- [ ] PHP 8.2+ compatible code with strict typing where applicable.
- [ ] No inline JS/CSS unless explicitly justified and securely handled.

## Backward Compatibility / Migration
- [ ] No breaking changes
- [ ] Breaking change (describe below)
- [ ] Migration required (describe steps below)
- [ ] Config/env changes required (describe below)

### Migration / Upgrade Notes
<!-- Required if schema/contracts/config changed -->

## Testing & Verification
### Automated Tests
- [ ] Existing tests pass
- [ ] New tests added/updated
- [ ] Not applicable (explain why)

### Manual Verification
<!-- Provide exact steps to validate behavior -->
1.
2.
3.

### Security Verification
<!-- If security-sensitive code changed, explain attack paths considered and mitigations -->
- Threats considered:
- Mitigations implemented:

## Screenshots / Logs (if applicable)
<!-- UI/API evidence, sanitized logs only -->

## Risk Assessment
- **Risk level:** Low / Medium / High
- **Primary risk areas:** <!-- e.g., payments flow, auth, module loader -->
- **Rollback plan:** <!-- how to safely revert -->

## Reviewer Focus Areas
<!-- Point reviewers to highest-risk files/lines -->

## Linked Issues
<!-- e.g., Closes #123 -->

## Author Declaration (Required)
- [ ] I confirmed this PR does **not** introduce legacy `pp-` / `pp_` patterns.
- [ ] I validated security-critical paths (input validation, output encoding, path boundaries, auth checks).
- [ ] I did not include secrets or sensitive payment/PII data.
- [ ] I verified the change follows `.github/copilot-instructions.md` and `.github/instructions/*.instructions.md`.