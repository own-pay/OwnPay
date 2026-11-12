# OwnPay Cross-Repo Adversarial Security Audit Protocol (`dev` Branch Only)

You are operating inside an automated DevSecOps security audit pipeline for the **OwnPay** ecosystem (`own-pay/OwnPay` + `own-pay/OwnPay-Mobile-App`).
- **Workspace Layout:**
  - `./OwnPay` — Self-hosted payment gateway core (**PHP 8.3+**, MariaDB/MySQL, Twig 3, **PHPStan Level 9**, custom dependency-minimal architecture, double-entry ledger, 123 payment gateways, sandboxed plugin system, Mobile API).
  - `./OwnPay-Mobile-App` — Android merchant companion app (**Flutter / Dart**, background SMS listener, on-device privacy filter, device pairing, JWT auth, SMS-to-server verification sync).
- **Target Branch:** `dev` in both repositories (NEVER scan, modify, or open PRs against `main`).

## Global Operational & Performance Rules
1. **Strict Scope Exclusions (Mandatory for Speed):**
   - In `./OwnPay`: NEVER read, grep, or audit `vendor/`, `storage/`, `bootstrap/cache/`, `.git/`, `public/build/`, or `composer.lock`. Audit ONLY first-party code (`src/`, `public/`, `database/`, `config`, `database`, `templates`, `modules`, `tests/`).
   - In `./OwnPay-Mobile-App`: NEVER read, grep, or audit `.dart_tool/`, `build/`, `.gradle/`, `.git/`, `ios/`, `windows/`, `linux/`, `macos/`, `web/`, or `pubspec.lock`. Audit ONLY `lib/`, `android/app/src/`, `test/` and important location.
2. **Fast Static Analysis Rule:**
   - During iterative patching, run `vendor/bin/parallel-lint` and `vendor/bin/phpstan analyse --memory-limit=2G` **ONLY on modified PHP files** (`git diff --name-only -- '*.php'`), and `dart analyze` **ONLY on modified Dart files**. Run full-project analysis only once during final verification.
3. **Artifact & Workspace Hygiene:**
   - Store all intermediate JSON/Markdown reports strictly in `/tmp/audit/`.
   - Never hardcode or log secrets, API keys, JWTs, or database credentials.
   - Delete all temporary regression test files before finishing so `git status` in both `./OwnPay` and `./OwnPay-Mobile-App` contains ONLY genuine production fixes.
  
> If it becomes necessary to do something that is not mentioned here but is important for completing the task, you may go ahead and do it.

---

<!-- STAGE_1_START -->
## STAGE 1: Cross-Repo Threat Modeling & Parallel Security Swarm
**Model:** `minimax/minimax-m3.1-flash` (`--effort max --timeout=4h`)

### Step 1 — Cross-Repo Threat Modeling & Entry-Point Mapping
1. Ensure `/tmp/audit` exists (`mkdir -p /tmp/audit`).
2. Map critical architectural entry points across **both** `./OwnPay` and `./OwnPay-Mobile-App`:
   - **Backend (`./OwnPay`):**
     - HTTP API routes, checkout endpoints, admin controllers, and `DomainMiddleware` / `TenantScope` multi-brand isolation.
     - Double-entry ledger engine (`op_ledger_accounts`, `op_ledger_transactions`, `op_ledger_entries`), mutex locks, refunds, and multi-currency math (`bcmath`).
     - **Mobile API & SMS Verification Engine:** Device pairing (`single-use Mobile API key`), JWT auth & key rotation/revocation, `Submit Received SMS` endpoint, and regex-based bank/MFS transaction matching.
     - Incoming gateway webhook handlers (signature/HMAC verification, replay/idempotency) and Plugin Sandbox (`eval`/`exec`/`system` filters) + self-update signature verification.
   - **Mobile Companion App (`./OwnPay-Mobile-App`):**
     - Android `AndroidManifest.xml` & SMS `BroadcastReceiver` permissions (preventing spoofed local broadcasts from rogue apps on the device).
     - On-device privacy filter (ensuring non-payment/OTP/personal SMS are never leaked to the server).
     - Robust SMS identification and anti-fraud measures—including strict verification of SMS sources—that prevent attempts to authenticate transactions through SMS sender identity spoofing. Protection against attempts to verify transactions using fraudulent SMS messages.
     - Ensuring that SMS messages are correctly identified across various mobile states, verifying proper functionality on different Android versions, and confirming compatibility with mobile devices from various brands.
     - Secure storage of Mobile API keys and JWTs (`flutter_secure_storage` vs insecure `SharedPreferences`), TLS/certificate handling, and idempotent offline retry queue.
   - **Cross-Repo Contract (`OwnPay` <-> `OwnPay-Mobile-App`):**
     - Verify request/response schemas, authentication headers, payload integrity/signing, and replay protection between the Flutter app and the PHP Mobile API.
3. Write `/tmp/audit/threat-model.md` detailing the cross-repo attack surface and the **Top 3 High-Impact Vulnerabilities**.

### Step 2 — Parallel Security Swarm (Both Repositories)
Deploy three specialized parallel checker subagents simultaneously using your subagent/Task tool (covering both `./OwnPay` and `./OwnPay-Mobile-App`):
- **Subagent A (Injection, Deserialization & SMS Parsing):**
  - Audit `./OwnPay` for SQL injection (any raw query bypassing PDO parameterization), command injection, SSRF in gateway/webhook callbacks, path traversal (e.g., language/plugin/upload paths), Twig SSTI, and unsafe deserialization.
  - Audit `./OwnPay-Mobile-App` & `./OwnPay` SMS regex parser for ReDoS, malformed SMS injection, and amount/currency/TrxID parsing ambiguity.
- **Subagent B (Auth Gaps, BOLA, Multi-Tenant & Mobile Pairing):**
  - Audit `./OwnPay` for Broken Object-Level Authorization (BOLA/IDOR) across `op_merchants` (`TenantScope`), `/admin/*` domain bypasses, 2FA/Argon2id session flaws, webhook signature bypasses, and revoked mobile device token acceptance.
  - Audit `./OwnPay-Mobile-App` for exported Android components/BroadcastReceivers allowing fake SMS injection, insecure token storage, and missing device binding.
- **Subagent C (Ledger Concurrency, Replay & Secret Exposure):**
  - Audit `./OwnPay` for TOCTOU race conditions in double-entry ledger postings, duplicate SMS/webhook replay crediting the same invoice twice, non-atomic balance checks, and secret/PII leakage in logs or error responses.
  - Audit `./OwnPay-Mobile-App` for duplicate SMS submission races, sensitive SMS content logging in debug/release builds, and unhandled background isolate exceptions.

Merge all findings into `/tmp/audit/findings.json` using this exact schema:
```json
[
  {
    "id": "SEC-001",
    "repo": "OwnPay | OwnPay-Mobile-App | Cross-Repo",
    "subagent": "Subagent-B",
    "severity": "Critical | High | Medium | Low",
    "category": "BOLA / Ledger Concurrency / Mobile SMS Security / Injection / Auth Gap",
    "file": "OwnPay/src/... or OwnPay-Mobile-App/lib/...",
    "lines": "42-58",
    "description": "Detailed root-cause analysis",
    "data_flow": "Source-to-sink trace across backend and/or mobile app",
    "proposed_fix": "Explanation of surgical defensive patch"
  }
]
```

After writing `/tmp/audit/findings.json`:
1. Apply minimal, surgical patches directly inside `./OwnPay` and/or `./OwnPay-Mobile-App` to resolve all confirmed vulnerabilities (keeping cross-repo API contracts synchronized if both sides need updates).
2. Run targeted lint/static analysis ONLY on modified files (`parallel-lint` / `phpstan` on modified `.php` files in `./OwnPay`; `dart analyze` on modified `.dart` files in `./OwnPay-Mobile-App`).
3. Do NOT run `git commit` or `git push`.
<!-- STAGE_1_END -->

---

<!-- STAGE_2_START -->
## STAGE 2: Adversarial Verification, Regression Testing & Per-Repo Reports
**Model:** `dots-studio/dots-3-note-preview` (`--effort high --timeout=2h`)

### Step 3 — Adversarial Critique & Boundary Verification
Act as an independent, skeptical Adversarial Security Critic. Challenge Stage 1 rigorously across both `./OwnPay` and `./OwnPay-Mobile-App`:
1. Read `/tmp/audit/threat-model.md`, `/tmp/audit/findings.json`, and inspect `git -C OwnPay diff` and `git -C OwnPay-Mobile-App diff`.
2. **False-Positive Elimination:** Verify every finding against the actual code. If a finding is already mitigated by PDO parameterization, `TenantScope`, middleware, or existing guards, revert its patch (`git -C <repo> checkout -- <file>`) and mark it as `REJECTED (False Positive)`.
3. **Defensive Boundary Verification:**
   - For every confirmed **Medium, High, or Critical** finding in either repository, write a focused temporary defensive unit/regression test verifying that unauthorized, forged, replayed, or boundary-violating inputs are strictly rejected while valid workflows succeed.
   - Run the temporary test to confirm the surgical patch enforces the boundary properly. If an edge-case bypass exists, harden the patch.
4. **Cleanup & Full Verification:**
   - Delete all temporary regression test files in both `./OwnPay` and `./OwnPay-Mobile-App` so `git status` in each repo shows ONLY genuine production fixes.
   - In `./OwnPay` (if modified): run `vendor/bin/parallel-lint src` and `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (plus existing tests if present) to guarantee PHPStan Level 9 compliance.
   - In `./OwnPay-Mobile-App` (if modified): run `dart analyze` and `flutter test` to guarantee zero regressions.

### Step 4 — Generate Separate Issue & PR Reports per Repository
Write clean Markdown reports in `/tmp/audit/` (do NOT commit them to git):
1. `/tmp/audit/GLOBAL_SUMMARY.md` — High-level cross-repo audit summary for GitHub Actions Step Summary.
2. **If `./OwnPay` has verified changes (`git -C OwnPay status --porcelain` is non-empty):**
   - `/tmp/audit/OWNPAY_ISSUE.md`: Security dashboard of all verified findings in `own-pay/OwnPay` grouped by severity (`Critical`, `High`, `Medium`, `Low`), including threat model highlights, file paths, lines, root cause, and cross-repo notes if linked to `OwnPay-Mobile-App`.
   - `/tmp/audit/OWNPAY_PR.md`: Summary table mapping each fix in `OwnPay` to its finding ID (`SEC-XXX`), modified files, and PHPStan Level 9 / test verification status.
3. **If `./OwnPay-Mobile-App` has verified changes (`git -C OwnPay-Mobile-App status --porcelain` is non-empty):**
   - `/tmp/audit/MOBILE_ISSUE.md`: Security dashboard of all verified findings in `own-pay/OwnPay-Mobile-App` grouped by severity (`Critical`, `High`, `Medium`, `Low`), including file paths, lines, root cause, and cross-repo notes if linked to `OwnPay`.
   - `/tmp/audit/MOBILE_PR.md`: Summary table mapping each fix in `OwnPay-Mobile-App` to its finding ID (`SEC-XXX`), modified files, and `dart analyze` / `flutter test` verification status.
<!-- STAGE_2_END -->
