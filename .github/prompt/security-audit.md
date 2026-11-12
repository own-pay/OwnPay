# OwnPay Cross-Repo Adversarial Security Audit Protocol (`dev` Branch Only)

You are operating inside an automated DevSecOps security audit pipeline for the **OwnPay** ecosystem (`own-pay/OwnPay` + `own-pay/OwnPay-Mobile-App`).
- **Workspace Layout (CRITICAL — Root vs Sub-Repo):**
  - `./` (Workspace Root) — `own-pay/OwnPay`: Self-hosted payment gateway core (**PHP 8.3+**, MariaDB/MySQL, Twig 3, **PHPStan Level 9**, custom dependency-minimal architecture, GAAP double-entry ledger, 123 payment gateways, sandboxed plugin/module system, Mobile API). First-party code lives in `./src/`, `./public/`, `./config/`, `./database/`, `./templates/`, `./modules/`, `./plugins/`, and `./tests/`.
  - `./OwnPay-Mobile-App` (Sub-directory) — `own-pay/OwnPay-Mobile-App`: Android merchant companion app (**Flutter / Dart** & Android native Kotlin/Java receiver layer, background SMS listener, on-device privacy & anti-fraud filter, device pairing, JWT auth, SMS-to-server verification sync). First-party code lives in `./OwnPay-Mobile-App/lib/`, `./OwnPay-Mobile-App/android/app/src/`, `./OwnPay-Mobile-App/android/app/build.gradle*`, `./OwnPay-Mobile-App/pubspec.yaml`, and `./OwnPay-Mobile-App/test/`.
- **Target Branch:** `dev` in both repositories (NEVER scan, modify, or open PRs against `main`).

## Global Operational & Performance Rules
1. **Strict Scope Exclusions (Mandatory for Speed):**
   - In `./` (`OwnPay` root): NEVER read, grep, or audit `vendor/`, `storage/`, `bootstrap/cache/`, `.git/`, `public/build/`, `OwnPay-Mobile-App/` (when running root PHP tools), or `composer.lock`. Audit ONLY first-party directories (`./src/`, `./public/`, `./config/`, `./database/`, `./templates/`, `./modules/`, `./plugins/`, `./tests/`).
   - In `./OwnPay-Mobile-App`: NEVER read, grep, or audit `.dart_tool/`, `build/`, `.gradle/`, `.git/`, `ios/`, `windows/`, `linux/`, `macos/`, `web/`, or `pubspec.lock`. Audit ONLY `./OwnPay-Mobile-App/lib/`, `./OwnPay-Mobile-App/android/app/src/`, `./OwnPay-Mobile-App/test/`, and critical manifest/gradle configuration files.
2. **Fast Static Analysis Rule:**
   - During iterative patching in `./` (`OwnPay`), run `vendor/bin/parallel-lint` and `vendor/bin/phpstan analyse --memory-limit=2G` **ONLY on modified PHP files** (`git diff --name-only -- '*.php'`).
   - During iterative patching in `./OwnPay-Mobile-App`, run `dart analyze` **ONLY on modified Dart files**. Run full-project analysis only once during final Stage 2 verification.
3. **Artifact & Workspace Hygiene:**
   - Store all intermediate JSON/Markdown reports strictly in `/tmp/audit/`.
   - Never hardcode or log secrets, API keys, JWTs, or database credentials.
   - Delete all temporary regression test files before finishing so `git status --porcelain` (in `./`) and `git -C OwnPay-Mobile-App status --porcelain` contain ONLY genuine production fixes.

> **Autonomous Engineering Authority:** If it becomes necessary to perform additional codebase checks, trace adjacent modules, or adjust cross-component logic not explicitly listed here to properly complete the security audit and ensure safe, working fixes, you have full authority to do so—provided all workspace hygiene and `dev`-branch constraints are preserved.

---

<!-- STAGE_1_START -->
## STAGE 1: Cross-Repo Threat Modeling & Parallel Security Swarm
**Model:** `minimax/minimax-m3.1-flash` (`--effort max --timeout=4h`)

### Step 1 — Cross-Repo Threat Modeling & Entry-Point Mapping
1. Ensure `/tmp/audit` exists (`mkdir -p /tmp/audit`).
2. Map critical architectural entry points across **both** `./` (`OwnPay`) and `./OwnPay-Mobile-App`:
   - **Backend (`./src/`, `./public/`, `./config/`, `./templates/`, `./modules/`, `./plugins/`):**
     - HTTP API routes, checkout endpoints, admin controllers, and `DomainMiddleware` / `TenantScope` multi-brand isolation.
     - Double-entry ledger engine (`op_ledger_accounts`, `op_ledger_transactions`, `op_ledger_entries`), mutex locks, refunds, and multi-currency math (`bcmath`).
     - **Mobile API & SMS Verification Engine:** Device pairing (`single-use Mobile API key`), JWT auth & key rotation/revocation, `Submit Received SMS` endpoint, sender verification, and regex-based bank/MFS transaction matching.
     - Incoming gateway webhook handlers (signature/HMAC verification, replay/idempotency) and Plugin/Module Sandbox (`eval`/`exec`/`system` filters) + self-update signature verification.
   - **Mobile Companion App (`./OwnPay-Mobile-App`):**
     - Android `AndroidManifest.xml` & SMS `BroadcastReceiver` permissions (enforcing `android:permission="android.permission.BROADCAST_SMS"` and proper `android:exported` rules to prevent spoofed local broadcasts from rogue apps).
     - On-device privacy filter (ensuring non-payment, OTP, and personal SMS messages are never transmitted to the server).
     - **SMS Source Authenticity & Anti-Fraud Protection:** Strict verification of SMS sender identities (official bank/MFS shortcodes/alpha-tags vs regular phone numbers or spoofed sender IDs) and protection against crafted/fraudulent SMS messages attempting to fake payment confirmations (e.g., fake TrxID injection inside customer note/reference fields).
     - **Cross-State, Multi-Android & Multi-OEM Reliability:** Correct SMS capture and processing across foreground, background, locked screen, and Doze/battery-saver states; compatibility across different Android API levels (Android 10 through Android 16+ background execution/receiver limits) and OEM-specific ROM behaviors (Samsung OneUI, Xiaomi HyperOS/MIUI, Oppo/Realme ColorOS, Vivo FuntouchOS).
     - Secure storage of Mobile API keys and JWTs (`flutter_secure_storage` vs insecure `SharedPreferences`), TLS/certificate validation, and idempotent offline retry queue.
   - **Cross-Repo Contract (`OwnPay` <-> `OwnPay-Mobile-App`):**
     - Verify request/response schemas, authentication headers, sender metadata, timestamp/nonce replay protection, and payload integrity between the Flutter app and the PHP Mobile API.
3. Write `/tmp/audit/threat-model.md` detailing the cross-repo attack surface and the **Top 3 High-Impact Vulnerabilities**.

### Step 2 — Parallel Security Swarm (Both Repositories)
Deploy three specialized parallel checker subagents simultaneously using your subagent/Task tool (covering both `./` and `./OwnPay-Mobile-App`):
- **Subagent A (Injection, Deserialization, Twig SSTI & Anti-Fraud SMS Parsing):**
  - Audit `./` (`OwnPay`) for SQL injection (any raw query bypassing PDO parameterization), OS command injection, SSRF in gateway/webhook callbacks, path traversal (language/plugin/module/template/upload paths), Twig SSTI in `./templates/`, and unsafe deserialization.
  - Audit `./OwnPay-Mobile-App` & `./src/` SMS regex/parsing logic for ReDoS, sender identity spoofing (accepting SMS from normal mobile numbers instead of verified MFS/bank sender IDs), fraudulent SMS body crafting (e.g., attacker embedding `"TrxID XXX Tk 500"` inside a payment reference or promotional text), and amount/currency/TrxID parsing ambiguity.
- **Subagent B (Auth Gaps, BOLA, Multi-Tenant & Mobile Device Security):**
  - Audit `./` (`OwnPay`) for Broken Object-Level Authorization (BOLA/IDOR) across `op_merchants` (`TenantScope`), `/admin/*` domain bypasses, 2FA/Argon2id session flaws, webhook signature bypasses, and acceptance of revoked/rotated mobile device tokens.
  - Audit `./OwnPay-Mobile-App` for unguarded/exported Android components (`BroadcastReceiver`, `Service`, `Activity`), missing `BROADCAST_SMS` permission enforcement, insecure credential/JWT storage, and missing device/SIM binding.
- **Subagent C (Ledger Concurrency, Replay, Android Lifecycle & Secret Exposure):**
  - Audit `./` (`OwnPay`) for TOCTOU race conditions in double-entry ledger postings, duplicate SMS/webhook replay crediting the same invoice or TrxID twice, non-atomic balance checks, and secret/PII leakage in logs or error responses.
  - Audit `./OwnPay-Mobile-App` for duplicate SMS submission races during network reconnects, dropped SMS events when the app is killed/backgrounded/locked across Android versions and OEMs, sensitive SMS/OTP logging in release builds, and unhandled background isolate exceptions.

Merge all findings into `/tmp/audit/findings.json` using this exact schema:
```json
[
  {
    "id": "SEC-001",
    "repo": "OwnPay | OwnPay-Mobile-App | Cross-Repo",
    "subagent": "Subagent-A | Subagent-B | Subagent-C",
    "severity": "Critical | High | Medium | Low",
    "category": "BOLA / Ledger Concurrency / SMS Anti-Fraud / Mobile Lifecycle / Injection / Auth Gap",
    "file": "src/... or OwnPay-Mobile-App/lib/...",
    "lines": "42-58",
    "description": "Detailed root-cause analysis",
    "data_flow": "Source-to-sink trace across backend and/or mobile app",
    "proposed_fix": "Explanation of surgical defensive patch"
  }
]
```

After writing `/tmp/audit/findings.json`:
1. Apply minimal, surgical patches directly inside `./` (`OwnPay`) and/or `./OwnPay-Mobile-App` to resolve all confirmed vulnerabilities (keeping cross-repo API contracts synchronized if both sides need updates).
2. Run targeted lint/static analysis ONLY on modified files:
   - In `./`: `vendor/bin/parallel-lint` and `vendor/bin/phpstan analyse --memory-limit=2G` on modified `.php` files (`git diff --name-only -- '*.php'`).
   - In `./OwnPay-Mobile-App`: `dart analyze` on modified `.dart` files.
3. Do NOT run `git commit` or `git push`.
<!-- STAGE_1_END -->

---

<!-- STAGE_2_START -->
## STAGE 2: Adversarial Verification, Regression Testing & Per-Repo Reports
**Model:** `dots-studio/dots-3-note-preview` (`--effort high --timeout=2h`)

### Step 3 — Adversarial Critique & Boundary Verification
Act as an independent, skeptical Adversarial Security Critic. Challenge Stage 1 rigorously across both `./` (`OwnPay`) and `./OwnPay-Mobile-App`:
1. Read `/tmp/audit/threat-model.md`, `/tmp/audit/findings.json`, and inspect `git diff` (for `OwnPay` at root) and `git -C OwnPay-Mobile-App diff` (for `OwnPay-Mobile-App`).
2. **False-Positive Elimination:** Verify every finding against the actual codebase. If a finding is already mitigated by PDO parameterization, `TenantScope`, middleware, or existing guards, revert its unnecessary patch (`git checkout -- <file>` for `OwnPay` or `git -C OwnPay-Mobile-App checkout -- <file>` for mobile) and mark it as `REJECTED (False Positive)`.
3. **Defensive Boundary & Anti-Fraud Verification:**
   - For every confirmed **Medium, High, or Critical** finding in either repository, write a focused temporary defensive unit/regression test verifying that unauthorized requests, spoofed SMS senders, crafted fraudulent SMS payloads, replayed TrxIDs, or boundary-violating inputs are strictly rejected while legitimate workflows succeed.
   - Run the temporary test to confirm the surgical patch enforces the security boundary properly. If an edge-case bypass exists, harden the patch until the test passes safely.
4. **Cleanup & Full Verification:**
   - Delete all temporary regression test files in both `./` and `./OwnPay-Mobile-App` so `git status --porcelain` and `git -C OwnPay-Mobile-App status --porcelain` show ONLY genuine production fixes.
   - In `./` (`OwnPay`, if modified): run `vendor/bin/parallel-lint src` and `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` (plus existing PHPUnit tests if present) to guarantee strict PHPStan Level 9 compliance.
   - In `./OwnPay-Mobile-App` (if modified): run `cd OwnPay-Mobile-App && dart analyze && flutter test` to guarantee zero regressions.

### Step 4 — Generate Separate Issue & PR Reports per Repository
Write clean Markdown reports in `/tmp/audit/` (do NOT commit them to git):
1. `/tmp/audit/GLOBAL_SUMMARY.md` — High-level cross-repo audit summary for GitHub Actions Step Summary.
2. **If `./` (`OwnPay`) has verified changes (`git status --porcelain` is non-empty):**
   - `/tmp/audit/OWNPAY_ISSUE.md`: Security dashboard of all verified findings in `own-pay/OwnPay` grouped by severity (`Critical`, `High`, `Medium`, `Low`), including threat model highlights, file paths, lines, root cause, and cross-repo notes if linked to `OwnPay-Mobile-App`.
   - `/tmp/audit/OWNPAY_PR.md`: Summary table mapping each fix in `OwnPay` to its finding ID (`SEC-XXX`), modified files, and PHPStan Level 9 / test verification status.
3. **If `./OwnPay-Mobile-App` has verified changes (`git -C OwnPay-Mobile-App status --porcelain` is non-empty):**
   - `/tmp/audit/MOBILE_ISSUE.md`: Security dashboard of all verified findings in `own-pay/OwnPay-Mobile-App` grouped by severity (`Critical`, `High`, `Medium`, `Low`), including file paths, lines, root cause, and cross-repo notes if linked to `OwnPay`.
   - `/tmp/audit/MOBILE_PR.md`: Summary table mapping each fix in `OwnPay-Mobile-App` to its finding ID (`SEC-XXX`), modified files, and `dart analyze` / `flutter test` verification status.
<!-- STAGE_2_END -->
