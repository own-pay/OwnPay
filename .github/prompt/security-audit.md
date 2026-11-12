# Adversarial Multi-Agent Security Audit Prompt (`dev` Branch Only)

You are operating inside an automated GitHub Actions DevSecOps pipeline powered by Pullfrog and AnyRouter.
Target Branch: `dev` (Never scan or open PRs against `main`).

## Global Operational Rules
- Never expose or hardcode secrets, tokens, or credentials in code, logs, or markdown reports.
- Store all intermediate audit artifacts, JSON sheets, and markdown reports strictly inside `/tmp/audit/`.
- Never leave temporary test or verification scripts inside the repository workspace when your stage finishes.

---

<!-- STAGE_1_START -->
## STAGE 1: Main Orchestrator & Parallel Security Swarm
**Model:** `minimax/minimax-m3.1-flash` (`--effort=max`)

### Step 1 — Threat Modeling & Scope
1. Ensure `/tmp/audit` exists (`mkdir -p /tmp/audit`).
2. Identify critical architectural entry points within the target scope:
   - API routes, controllers, GraphQL resolvers, and RPC handlers
   - Database queries, raw SQL/NoSQL statements, and ORM lifecycle hooks
   - Webhook handlers, payment/callback endpoints, and queue workers
   - Authentication middleware, session/JWT validators, and RBAC/ABAC guards
3. Write `/tmp/audit/threat-model.md` listing the entry points and the **Top 3 High-Impact Vulnerabilities** most likely to affect this codebase.

### Step 2 — Parallel Security Swarm
Deploy three specialized parallel checker subagents simultaneously using your subagent/Task tool:
- **Subagent A (Injection & Deserialization):** Audit for SQL/NoSQL injection, OS command injection, SSRF, path traversal, prototype pollution, and unsafe input deserialization.
- **Subagent B (Auth Gaps, BOLA & Privilege Escalation):** Audit for missing authentication, Broken Object-Level Authorization (BOLA/IDOR), tenant isolation leaks, JWT/signature flaws, and privilege escalation.
- **Subagent C (Concurrency, Logic & Secret Exposure):** Audit for TOCTOU race conditions, non-atomic database read-modify-write flows, unhandled promise rejections, memory leaks, and secret/token exposure in logs or error responses.

Merge all subagent findings into `/tmp/audit/findings.json` using this exact JSON structure:
```json
[
  {
    "id": "SEC-001",
    "subagent": "Subagent-B",
    "severity": "Critical | High | Medium | Low",
    "category": "BOLA / Injection / Auth Gap / Concurrency",
    "file": "path/to/file",
    "lines": "42-58",
    "description": "Detailed root-cause analysis",
    "data_flow": "Source-to-sink trace showing the unvalidated input or missing guard",
    "proposed_fix": "Explanation of surgical defensive patch"
  }
]
```

After generating `/tmp/audit/findings.json`, apply minimal, surgical patches directly to the codebase to fix every confirmed vulnerability without breaking existing API contracts. Do NOT run `git commit` or `git push`.
<!-- STAGE_1_END -->

---

<!-- STAGE_2_START -->
## STAGE 2: Adversarial Verification, Regression Testing & Final Report
**Model:** `dots-studio/dots-3-note-preview` (`--effort=high`)

### Step 3 — Adversarial Critique & Boundary Verification
Act as an independent, skeptical Adversarial Security Critic. Do NOT blindly trust Stage 1:
1. Read `/tmp/audit/threat-model.md`, `/tmp/audit/findings.json`, and inspect `git diff`.
2. **False-Positive Elimination:** Verify every finding against the actual codebase. If a finding is already mitigated by upstream middleware, ORM parameterization, or schema validation, revert its patch (`git checkout -- <file>`) and mark it as `REJECTED (False Positive)`.
3. **Boundary Enforcement & Surgical Patch Verification:**
   - For every confirmed **Medium, High, or Critical** finding, write a focused temporary defensive unit/regression test verifying that unauthorized, malformed, or boundary-violating inputs are strictly rejected while legitimate workflows continue to succeed.
   - Run the test to confirm the applied surgical patch enforces the security boundary properly.
   - If the Stage 1 patch has any edge-case bypasses (e.g., alternate HTTP methods, missing nested ownership check, race window), harden the patch until the regression test passes safely.
4. **Workspace Cleanup & Full Test Suite:**
   - Delete all temporary regression test files so `git status` contains ONLY genuine production fixes.
   - Run the repository's full test suite to verify zero regressions.

### Step 4 — Final Dashboard Reports
Write two clean Markdown files in `/tmp/audit/` (do NOT commit them to git):
1. `/tmp/audit/ISSUE_REPORT.md`:
   - Complete Security Audit Dashboard grouped by severity (`Critical`, `High`, `Medium`, `Low`).
   - Include the Threat Model summary, file paths, line numbers, root cause, and adversarial verification status (`VERIFIED & PATCHED` vs `FALSE POSITIVE DROPPED`).
2. `/tmp/audit/PR_REPORT.md`:
   - Concise summary table mapping each applied surgical fix to its finding ID (`SEC-XXX`), modified files, and test verification result.
<!-- STAGE_2_END -->
