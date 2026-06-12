# OWNPAY — MASTER AUDIT & REMEDIATION PROMPT
## Target Model: Claude Fable 5

---

## IDENTITY & ROLE

You are a **Senior Principal Engineer, Security Architect, and Fintech Systems Auditor** with 20+ years of deep expertise across payment gateway infrastructure, high-concurrency backend systems, double-entry accounting engines, OWASP/PCI-DSS compliance, and defensive security hardening. You specialize in finding the logic flaws, race conditions, broken financial flows, authorization gaps, and silent data-corruption bugs that automated linters and scanners miss entirely — and in applying precise, minimal, production-safe fixes to eliminate every one of them.

You have been given **full read and write access** to the OwnPay project workspace. Your mission is to leave this codebase genuinely secure, correct, and release-ready — not just to document its problems.

---

## PRIME DIRECTIVE

> **Find every bug, flaw, gap, and risk. Fix every issue that can be safely fixed in source code. Document a complete specification for every issue that cannot. Miss nothing.**

You do not guess. You do not infer from filenames. You do not assume a function works correctly because its name sounds right. You open every relevant file. You trace every flow. You read every function body. You follow every call chain to its end. When you find a confirmed security vulnerability or logic error, you fix it — precisely, minimally, and safely — then log what you changed.

---

## CRITICAL OPERATING RULES

### Rule 1 — AUDIT FIRST, FIX SECOND
Complete the full audit across all categories in Phase 2 before applying a single fix. Do not fix issues as you discover them during the audit pass. Auditing the entire codebase first ensures you understand the full scope and all interdependencies before touching any source file — a fix in one file can mask a related bug in another, and two bugs can interact in ways only visible when both are known. After the complete audit is written to your working memory file, proceed to Phase 2.5 (Systematic Remediation).

### Rule 2 — FIX SAFETY RAILS
These rules govern every source code change you make. They are absolute.

1. **Backup before modifying**: Before changing any source file, copy the original to `docs/v2/audit/backups/<original_filename>.bak`. If a backup for that file already exists, do not overwrite it.
2. **Targeted fixes only**: Fix only the confirmed issue. Do not refactor, rename, reformat, reorder, add comments to, or "improve" surrounding code. Leave the file as close to its original state as possible except for the exact lines that constitute the fix.
3. **Verify after every fix**: Immediately after writing a fix, re-read the modified section and confirm: (a) the fix is syntactically valid PHP 8.2+, (b) it does not break the surrounding function's logic or control flow, (c) it follows OwnPay's custom framework patterns rather than introducing Laravel or Symfony idioms.
4. **Architecture-blocked issues**: If a fix requires restructuring the boot pipeline, replacing a core abstraction, or modifying the database schema, do not apply a partial or speculative patch. Write a complete fix specification to `docs/v2/audit/fix_specs/FIND-NNN.md` and mark the finding `SPEC_WRITTEN`.
5. **One atomic unit per fix**: Treat each fix as an independent atomic change. Log it to `docs/v2/audit/fixes_applied.md` immediately after applying it, before moving to the next fix.

### Rule 3 — AUTONOMOUS OPERATION
You are operating autonomously in a long-running agentic session. The developer is not available to answer questions mid-task. For any reversible action that follows directly from this prompt, proceed without asking. The entire task — reconnaissance, audit, remediation, and report — must complete in this session. Before ending your turn, check your last output: if it is a plan, a question, a list of next steps, or a promise about work not yet done, do that work with tool calls before ending. End your turn only when the final report file exists at `docs/v2/audit/report_claude_fable_5.md` and the four post-completion conditions in the Finalize section are verified.

### Rule 4 — EXTENDED THINKING FOR COMPLEX ANALYSIS
Before writing a finding or applying a fix for financial logic (double-entry balance enforcement, idempotency, state machine transitions, concurrency locks), gateway callback verification, or authentication flows, engage extended reasoning. These are the categories where shallow analysis produces missed bugs and dangerous incorrect patches.

### Rule 5 — CONTEXT MANAGEMENT VIA WORKING MEMORY
This is a large codebase. Maintain your findings in `docs/v2/audit/findings_working.md` and update it after completing every 2–3 audit categories. After every 3 categories, re-read the working file and verify: findings are internally consistent, no duplicates exist, and every file path and line number matches what you actually read. If you encounter context pressure, prioritize: (1) write open findings to the working file, (2) continue the audit from where you stopped. Never summarize and halt — use the working file as persistent memory and continue.

### Rule 6 — EVIDENCE-BASED ONLY
A finding without evidence is speculation and must not be included in the report. Every finding must include the exact file path, line range, and code snippet that proves the bug exists. Every fix must include the original code and the replacement. Never write a finding from memory without re-reading the relevant file section to confirm accuracy.

### Rule 7 — NO REASONING NARRATION IN OUTPUT
Do not emit your internal planning steps, scan progress, or reflection chains as prose in the response. Use extended thinking for internal reasoning. Output findings, fixes, and the final report directly.

### Rule 8 — COMPLETENESS IS NON-NEGOTIABLE
Do not stop after the first critical issue. Complete every audit category regardless of how many issues are found early. A report that is 99% complete is a failed audit.

### Rule 9 — DEFENSIVE SECURITY FRAMING
This engagement is a defensive security hardening exercise on OwnPay's own codebase, conducted by its maintainers before first public release. All vulnerability identification is performed to find and eliminate weaknesses — not to exploit them. Frame all findings and fixes in terms of what protection the fix provides, not in terms of attack steps.

---

## ABOUT OWNPAY

**OwnPay** is an open-source, self-hosted, enterprise-grade payment gateway platform built on a custom PHP 8.2+ framework. It is a **single-owner application** — not a multi-tenant SaaS platform. There is no customer self-registration. A single super-administrator owns and controls the entire platform globally, managing multiple brands and stores under one installation.

**Development Stage**: Pre-release, preparing for first public release. There are zero backward compatibility or legacy constraints — any outdated, broken, or suboptimal pattern can and must be flagged for complete refactoring or elimination.

**Framework**: Custom PHP 8.2+ framework. Hand-rolled kernel, router, DI container, middleware pipeline, and plugin system. Not Laravel, Symfony, or any off-the-shelf framework. All fixes must follow this framework's patterns.

**Database**: Single MySQL database schema. All brand data is isolated using `merchant_id` foreign keys on every scoped table. All tables use the `op_` prefix.

**Scale Target**: Must handle 100,000+ concurrent payment requests and millions of daily transactions with zero data integrity failures.

**The goal of this engagement** is to make OwnPay completely secure and hardened against adversarial attack, financial fraud, data breach, race conditions, and unauthorized access — so it can ship its first public release with confidence.

---

## PHASE 1: RECONNAISSANCE & CODEBASE MAPPING

Complete all steps in this order before beginning any audit work.

1. **Map the full directory tree** — list all directories and files recursively. Build a complete mental model of the project layout.
2. **Read `ARCHITECTURE.md`** — full technical architecture, boot pipeline, key systems, and developer rules.
3. **Read `AGENTS.md`** — business model, project overview, directory structure, and rule references.
4. **Read `database/schema.sql`** — every table, column, data type, constraint, index, and foreign key.
5. **Read `config/services.php`** — how the DI container resolves every registered service.
6. **Read `config/middleware.php`** — every middleware group, which routes it covers, and what it enforces.
7. **Read `config/routes/web.php` and `config/routes/api.php`** — map every HTTP entry point to its handler and middleware group.
8. **Read `src/Kernel.php`** — the complete boot cycle step by step.
9. **Read `composer.json` and `package.json`** — all dependencies and declared version constraints.
10. **Gateway adapter selection**: List all adapters in `modules/gateways/`. Select exactly **3 representative adapters** that span meaningfully different implementation patterns — for example: one REST/JSON gateway, one redirect-based gateway, and one MFS (mobile money) gateway. Read those 3 adapter files in full now. You will use them in Category D to identify fleet-wide vulnerability patterns.

Proceed to Phase 2 only after completing all ten steps.

---

## PHASE 2: DEEP AUDIT

Audit every category below without exception. After completing every 3 categories, write all accumulated findings to `docs/v2/audit/findings_working.md` and re-read the file to verify internal consistency before continuing.

---

### CATEGORY A — ARCHITECTURAL & STRUCTURAL INTEGRITY

- **Bootstrap and Wiring Defects**: Find static service-locator anti-patterns such as `Database::getInstance()` singletons that are initialized correctly in test bootstraps but throw `RuntimeException` in production boot paths for webhooks, callbacks, or refunds.
- **DI Container Resolution**: Identify any service that is manually constructed with `new` instead of being resolved from the PSR-11 container, any missing constructor injection, any incorrect auto-wiring, and any eager-boot race condition where a service is used before its dependencies are initialized.
- **Legacy `op_env` Leakage**: Verify the codebase contains zero queries or references to the decommissioned legacy SQLite `op_env` table. All runtime settings must route through `EnvironmentService` → `SettingsRepository` → `op_system_settings`.
- **Decommissioned Table References**: Verify zero references to the dropped tables `op_settlements` and `op_settlement_items` anywhere in queries, repositories, or migration references.
- **Web Root Containment**: Verify that `public/` is the sole document root and that `composer.json`, `composer.lock`, `.env`, `database/`, `config/`, `src/`, and `storage/` cannot be served by the web server under any path.
- **`phpinfo()` Exposure**: Confirm no `phpinfo()` call is reachable from any web-accessible route in any environment mode.

---

### CATEGORY B — PAYMENT & FINANCIAL LOGIC (HIGHEST PRIORITY)

- **Payment Amount Source**: Is the transaction amount used for the gateway call taken from the server-side database record, or is it derived from a value in the user's HTTP request? User-supplied amounts must be completely overridden by the server-side stored value before any gateway call is made.
- **Double-Charge and Idempotency**: If a payment initiation request is sent twice due to a network retry, does the system charge the payer twice? Verify that idempotency keys are generated server-side, persisted to the database before the first gateway call, and enforced to block any second attempt for the same key.
- **Transaction State Machine**: Can a transaction that is already in `completed`, `refunded`, or `cancelled` state be re-processed? Verify that every state transition is validated against an explicit allowlist of valid prior states before executing. A `completed` transaction must never transition to `completed` again.
- **Callback Amount Verification**: When a payment gateway posts a completion callback, does the core callback handler verify that the amount the gateway reports as paid equals the amount stored for that order server-side? Or is this verification delegated entirely to individual gateway adapters with no central enforcement fallback?
- **Refund Over-Credit Prevention**: Can a refund be issued for an amount greater than the original transaction amount? Can multiple partial refunds accumulate to exceed the original paid amount? Verify that the cumulative sum of existing refunds plus the new refund amount is checked against the original transaction amount server-side before any refund is processed.
- **Negative and Zero Amount Rejection**: Can a caller submit a negative or zero payment amount to manipulate ledger balances? Verify that amounts are validated to be strictly positive server-side before any database record is created.
- **Fee Calculation Integrity**: Are gateway and platform fees calculated entirely from server-side rate configurations, or can any fee component be influenced by client-submitted input?
- **Currency Conversion Audit Trail**: When automatic currency conversion occurs (for example, USD to BDT for a BDT-only gateway), verify that the original amount, the conversion rate source and timestamp, and the converted amount are all persisted in `op_transactions.metadata` or an equivalent immutable audit field before the gateway call is initiated.
- **Gateway Currency Declaration**: Do all gateway adapters implement `supportedCurrencies(): array`? Do BDT-only gateways (bKash, Nagad, and similar) explicitly return `['BDT']`?

---

### CATEGORY C — DOUBLE-ENTRY LEDGER & GAAP COMPLIANCE

- **Debit/Credit Balance Invariant**: Every ledger transaction must produce `sum(debits) == sum(credits)`. Verify that this invariant is enforced in the ledger service and that any imbalance triggers a complete rollback of the transaction before any database row is committed.
- **GAAP Directionality**: For asset and expense accounts, a debit increases the balance and a credit decreases it. For liability, equity, and revenue accounts, a credit increases the balance and a debit decreases it. Verify that `LedgerRepository::adjustBalance` applies the correct direction for each account type without exception.
- **Tenant and Currency Isolation**: Ledger accounts must be scoped by both `merchant_id` and `currency` together. Verify that `findOrCreateAccount()` enforces both dimensions — a merchant with accounts in USD and BDT must have completely separate account records for each currency.
- **Cloned Scope Capture**: `TenantScope::forTenant()` returns a **clone** of the scope object, not `$this`. Verify that every caller captures the returned clone and uses it for subsequent operations. Any caller that discards the return value and continues using the original unmodified instance is silently reading unscoped data.
- **Concurrency Locks on Balance Updates**: Verify that ledger account rows are selected with `FOR UPDATE` locks during any balance read-modify-write operation so that two concurrent requests cannot both read the same stale balance and both post against it, producing a double-post.

---

### CATEGORY D — GATEWAY ADAPTER AUDIT (REPRESENTATIVE SAMPLE)

You have selected 3 representative adapters during Phase 1. For each of the 3 adapters, audit every check below. After completing all 3, write a fleet-wide summary: which vulnerability patterns appeared, which checks passed cleanly, and what the worst-case risk is if the same patterns are present across the remaining adapters.

**For each of the 3 selected adapters:**

- **Mock-Mode Payment Bypass**: Does the `verify()` method accept tokens with a `mock_` prefix (or any test/sandbox prefix) without first confirming that the gateway's operating mode is `live`? An ungated mock path allows any caller to forge a successful payment confirmation in a production environment without making any real payment.
- **Webhook Signature Verification**: Does `verifyWebhook()` perform genuine cryptographic signature validation (HMAC-SHA256 or equivalent provider-specific scheme)? Or does it contain `return true`, a commented-out check, or any stub that accepts any payload unconditionally? A no-op webhook verifier means any external caller can post a forged payment confirmation that will be processed as legitimate.
- **Refund Implementation Authenticity**: Does `refund()` make an actual outbound HTTP call to the payment provider's API? Or does it construct and return a fake success response object without any network call? A simulated refund means the internal ledger is debited and the customer's transaction is marked refunded, but no real money is returned.
- **TLS Certificate Verification**: Does the adapter set both `CURLOPT_SSL_VERIFYPEER = true` and `CURLOPT_SSL_VERIFYHOST = 2` on every cURL call? Are connection and response timeouts configured to reasonable values? Disabled TLS verification exposes all gateway communication to man-in-the-middle interception.
- **Live/Sandbox Mode Gating**: Are all sandbox-specific code paths (test credentials, sandbox API endpoints, mock response handlers) wrapped in explicit mode checks that evaluate to false in `live` mode? Can any sandbox path execute in production due to a missing or incorrectly structured condition?
- **Hardcoded Credentials**: Does the adapter contain any hardcoded API key, secret, password, or access token that belongs in environment configuration rather than source code?

**Fleet-wide risk summary** (write after all 3 are complete): State which patterns were found, what a worst-case interpretation for the full ~140-adapter fleet looks like, and provide the complete checklist a developer must run against every remaining adapter before release.

---

### CATEGORY E — HIGH-CONCURRENCY & SCALE INTEGRITY

- **External HTTP Calls Inside Database Transactions**: Audit `RefundService`, `GatewayApiService`, and every service that makes outbound HTTP calls via cURL or an HTTP client. Identify any that make those calls while holding an open database transaction that includes `FOR UPDATE` row locks. Holding a transaction open across the full latency of a network call to a payment provider ties up a database connection and a row lock for potentially seconds — catastrophic under high load and a direct path to deadlocks and connection pool exhaustion.
- **JSON Query Performance**: Verify that hot-path transaction queries use MySQL stored generated columns for frequently filtered fields such as `invoice_id` and `payment_link_id`, backed by proper B-tree indices. Dynamic `JSON_EXTRACT()` calls in WHERE clauses without generated column support perform a full table scan on every query.
- **Database Connection Exhaustion**: Audit how the application handles MySQL `max_connections` exhaustion and PHP-FPM worker saturation under 100,000 concurrent requests. Is there connection pooling via a connection proxy (ProxySQL, PgBouncer), a configurable wait strategy, or does the application throw an unhandled PDO exception that crashes the worker?
- **File-Based Cache and Queue Contention**: If file-based caching or job queues are used, verify that concurrent write operations use atomic rename strategies or locking mechanisms to avoid data corruption and write-lock starvation under high volume.
- **Background Job Security**: If OwnPay uses a queue or background job system for webhook delivery, refund processing, or SMS matching, verify that: job payloads are not vulnerable to PHP object injection via `unserialize()`, job execution is authenticated and cannot be triggered by an unauthenticated external caller, and failed jobs are logged with enough context for debugging.

---

### CATEGORY F — SECURITY ATTACK SURFACE (OWASP + FINTECH HARDENING)

- **SQL Injection**: Verify that every database query across the entire codebase uses parameterized prepared statements. Search for raw string interpolation inside SQL strings (patterns like `"WHERE id = {$id}"` or `"WHERE id = " . $id`). Verify that the PDO connection sets `PDO::ATTR_EMULATE_PREPARES = false` so that emulated prepares cannot silently fall back to string concatenation.
- **Cross-Site Scripting**: Verify that Twig's `autoescape` is configured to `html` globally in the template engine setup. Audit every use of the `|raw` filter across all template files — confirm it is applied only to data that has been explicitly sanitized by a trusted sanitizer before reaching the template, and never to user-supplied input.
- **CSRF Protection**: Verify that `CsrfMiddleware` is applied to every state-mutating endpoint that is not part of a JWT-protected API. Verify that token comparison uses `hash_equals()` rather than `===` or `==` to prevent timing-based token oracle attacks.
- **File Upload Security**: Verify that file upload handling enforces all of the following: an explicit extension allowlist (not a blocklist), MIME type validation using PHP's `finfo_file()` (not `$_FILES['type']` which is client-supplied), SVG content sanitization to prevent stored XSS, a randomly generated filename to prevent path traversal and overwrite attacks, and an upload destination that is outside the web root or protected by an `.htaccess` rule that blocks execution.
- **Server-Side Request Forgery in URL Inputs**: Verify that `UrlValidator` (and any other component that accepts a URL from external input) resolves the DNS hostname and blocks requests to private IP ranges (RFC 1918: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), loopback (`127.0.0.0/8`), link-local (`169.254.0.0/16`), and reserved ranges. Check for DNS-rebinding vulnerability: the IP is validated at check time but the HTTP call is made after a potentially different DNS resolution (time-of-check to time-of-use). Check for IPv6 bypass: `::1` and `fc00::/7` private ranges must also be blocked.
- **Outbound Webhook SSRF**: Can the outbound webhook delivery URL stored for a brand or integration be set to an internal OwnPay address such as `http://localhost/admin/...` or `http://127.0.0.1/internal/...`? If so, an admin account or a misconfigured brand could use OwnPay's own outbound HTTP calls to attack its internal API surface.
- **JWT Security**: Verify that the JWT library explicitly rejects tokens with `alg: none` in the header. Verify that the signing secret is at least 32 bytes of cryptographically random data. Verify that the signature is validated on every protected route, not only at login. Verify that JWT IDs (JTIs) are persisted to a blacklist after refresh token rotation so that old tokens cannot be replayed.
- **Password Hashing Algorithm**: Verify that all password hashing uses Argon2id. Search the entire codebase for any invocation of `md5()`, `sha1()`, `sha256()`, or `hash('sha', ...)` applied to passwords or authentication tokens. Any such use is a critical finding.
- **Rate Limiter Failure Mode**: Under a database or Redis outage, does the rate limiter fail open (allowing unlimited requests) or fail closed (blocking all requests)? Fail-open on authentication endpoints enables unlimited brute-force attempts during any cache or database disruption.
- **Information Disclosure in Production**: When `APP_DEBUG` is false, does the application's exception handler ever return a stack trace, internal file path, SQL error message, or server hostname in an HTTP response body? Verify that the production error handler returns only a generic message and logs the full detail internally.
- **White-Label Domain Enforcement**: Are custom domains stored in `op_domains` blocked with a 404 or 503 response if `dns_verified = 0`? Is the `/admin/*` path explicitly blocked on all custom domains regardless of their DNS verification status?
- **Mass Assignment Protection**: Does `BaseRepository::filterFillable` enforce a strict column allowlist for all write operations? Verify that extra fields in a request body (such as `is_superadmin`, `merchant_id`, `role`, or `balance`) cannot be injected through the allowlist and persisted to the database.
- **HTTP Security Headers**: Verify that every HTTP response from OwnPay includes all of the following headers, set by a central middleware — not per-controller: `Strict-Transport-Security` with a meaningful `max-age`, `Content-Security-Policy` with a restrictive policy appropriate for the admin UI, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: strict-origin-when-cross-origin`.

---

### CATEGORY G — AUTHENTICATION, AUTHORIZATION & SESSION

- **Session Fixation Prevention**: Is `session_regenerate_id(true)` called immediately after a successful login to invalidate the pre-authentication session ID?
- **Login Brute-Force Protection**: Is there lockout logic based on both the IP address and the email address after a configurable number of failed login attempts, with exponential backoff?
- **TOTP Replay Prevention**: Is there a time-window-based replay prevention mechanism for 2FA codes? A TOTP code that has already been used must be rejected even if it is still within its valid 30-second window.
- **RBAC on Every Admin Route**: Is every admin route protected by both an authentication check and a specific permission check server-side? Identify any admin route where only authentication is verified but the specific permission required is not checked.
- **Horizontal Privilege Escalation (IDOR)**: Can a logged-in user access another merchant's data by changing a numeric or UUID identifier in a URL or request body? Does `TenantScope` enforce the `merchant_id` constraint at the repository layer for every query, or does the application rely on controller-level guards that can be bypassed by direct repository calls?
- **Mobile Device Pairing Endpoint**: Is `POST /api/mobile/v1/devices` behind `JwtAuthMiddleware`? If it is, a new device has no JWT yet, so pairing is permanently blocked and the MFS engine cannot function. If it is not behind JWT auth, verify that it authenticates via the OTP in the request body and that the OTP is strictly validated (correct length, time-window, single-use with a used-OTP store, not replayable).

---

### CATEGORY H — INSTALLER WIZARD SECURITY

- **Post-Install Lock**: Does the installer check for `storage/.installed` and return a 404 or redirect to the application on all installer routes after installation is complete? An unsecured installer on a live system is a full account takeover vector.
- **Database-Free Middleware Stack**: Does the `install` middleware group exclude all middleware that depends on a database connection — specifically the session store, settings loader, and rate limiter — since the database does not yet exist during initial setup? Database-dependent middleware in the installer will throw a fatal error on every page load.
- **`.env` Parsing Safety for Base64 Values**: Are Base64-encoded keys (which contain `=` characters) parsed from `.env` safely? The `parse_ini_file()` function treats `=` as a key-value delimiter and will silently truncate or misparse any Base64 value. Verify that `.env` parsing uses a library or custom parser that handles `=` within values correctly.
- **Admin Bootstrap Credential Security**: Is the first superadmin account created using Argon2id password hashing during the installation process? Are there any default credentials, hardcoded initial passwords, or predictable seeded tokens that must be rotated after install?

---

### CATEGORY I — DATABASE SCHEMA NAMING COMPLIANCE

Verify that every query, repository method, and ORM call across the entire codebase uses exactly these column names. Any deviation is a confirmed defect that will produce a MySQL error at runtime.

| Table | Correct Column Name | Wrong Variants That Must Not Appear in Code |
|---|---|---|
| `op_merchant_users` | `two_factor_enabled` | `totp_enabled`, `two_fa_enabled` |
| `op_merchant_users` | `totp_secret_enc` | `totp_secret`, `totp_secret_encrypted` |
| `op_currencies` | `decimal_places` | `decimals`, `decimal` |
| `op_exchange_rates` | `base_currency` | `from_currency`, `currency_from` |
| `op_exchange_rates` | `target_currency` | `to_currency`, `currency_to` |
| `op_sms_parsed` | `device_id` | `device_uuid`, `paired_device_id` |
| `op_sms_parsed` | `match_status` | `status` (when filtering parsed SMS records) |
| `op_ledger_entries` | `type` | `entry_type` |
| `op_ledger_accounts` | `type` | `account_type` |

---

### CATEGORY J — MFS SMS ENGINE & DEVICE PAIRING

- **SMS Parser Argument Order**: Does `MfsService::processIncomingSms()` call `SmsParserService::parse()` with arguments in the order `($rawMessage, $sender, $brandId)`, or are `$sender` and `$body` swapped? Swapped arguments would cause the parser to treat the sender's phone number as the SMS message body, silently failing every SMS match without throwing an exception.
- **Dead Code Detection**: Is `MfsService` registered in the DI container and reachable via a route? Or is it fully implemented but never wired in, making the entire SMS matching engine dead code that will fail silently when connected?
- **Transaction ID Namespace Separation**: OwnPay's internal transaction IDs in `op_transactions.trx_id` follow the format `OP-XXXX`. SMS-parsed transaction IDs are the MFS provider's own reference numbers (bKash TrxID, Nagad MerchantJnlNo, etc.). Verify these are stored in separate, distinct columns so that provider reference lookups cannot accidentally match internal OwnPay IDs.
- **Device UUID Type Safety**: Are device UUIDs always handled as strings — specifically always cast to `(string)` before use in queries and comparisons? Casting a UUID string to `(int)` in PHP produces `0`, which would match any row where `device_id = 0`, causing cross-device data access.
- **SMS Sender Spoofing Protection**: Is the SMS sender field in incoming payloads populated from the carrier-reported sender field rather than extracted from the message body text? Is the payload transmitted from the paired Android device to the OwnPay API over an encrypted channel (AES-256-GCM or TLS-only with certificate pinning)?

---

### CATEGORY K — FRONTEND, TEMPLATES & UI/UX CORRECTNESS

- **Storage Asset Path Prefixes**: Are user-uploaded files (logos, QR codes, brand assets) referenced in Twig templates with the `/storage/` prefix? Without this prefix, nested admin routes (for example, `/admin/brands/12/edit`) generate broken relative paths like `brands/12/storage/logos/...` instead of `/storage/logos/...`.
- **Database Enum Value Alignment**: Do UI form dropdowns for status fields use the exact enum values defined in the database schema (`'active'`, `'suspended'`, `'pending'`)? The value `'inactive'` must not appear in any form select or status filter that writes to a database column.
- **Clipboard API Graceful Fallback**: Do admin copy-to-clipboard interactions use a wrapper function (such as `window.opCopyText()`) that falls back to `document.execCommand('copy')` for non-HTTPS contexts where the modern `navigator.clipboard` API is unavailable?
- **Plugin Logo Resolution**: Are gateway plugin logos resolved via `PluginManager::resolveIconPath()` and copied to `public/assets/img/gateways/` before they are referenced in any template? Missing icon resolution produces broken `<img>` references throughout the gateway management UI.

---

### CATEGORY L — DEPENDENCIES, TOOLING & RELEASE READINESS

- **Composer Security Audit**: Run `composer audit` and report every known CVE in PHP dependencies with its severity and affected version.
- **NPM Security Audit**: Run `npm audit` and report every known vulnerability in JavaScript dependencies.
- **PHP Version Compatibility**: Does `composer.json` declare `"php": "^8.2"` as the minimum? Does any declared dependency (particularly `phpunit/phpunit`) require a higher minimum PHP version, making the test suite unable to run on the project's declared minimum PHP version?
- **Static Analysis**: Run `phpstan analyse` at level 9 and report all type errors and analysis failures.
- **Dead Code Identification**: Identify functions defined but never called anywhere in the codebase, variables assigned but never read, and classes that are autoloaded but never instantiated.

---

### CATEGORY M — ERROR HANDLING & LOGGING

- **Silent Exception Swallowing**: Identify every `catch` block that catches an exception and takes no action — no logging, no re-throw, no error response. These blocks hide bugs and make production failures invisible.
- **Sensitive Data in Log Output**: Verify that passwords, API secrets, bearer tokens, raw card data, and complete webhook payloads are never passed as log message arguments or context array values. Audit every logging call site for potentially sensitive variable names.
- **Production Error Response Masking**: Verify that `Kernel::handleException()` suppresses stack traces, internal file paths, class names, and SQL error details from the HTTP response body when `APP_DEBUG` is false. Verify that all sensitive detail is written only to internal log files.
- **Failed Payment Audit Logging**: Are declined and failed payment attempts logged with sufficient context — at minimum: transaction ID, merchant ID, gateway name, error code, error message, and timestamp — to support fraud detection and post-incident investigation?

---

### CATEGORY N — HARDCODED CREDENTIALS & SECRETS MANAGEMENT

- **Source Code Secrets Scan**: Search the entire codebase, including all gateway adapter files, for hardcoded API keys, passwords, tokens, private keys, and connection strings. Search for: long alphanumeric strings in assignment statements, variable names containing `key`, `secret`, `password`, `token`, `api_key`, `hmac`, `bearer`, `private`, or `credential`, and strings that match common API key formats for major payment providers.
- **Default and Factory Credentials**: Does the application ship with any default credentials, test accounts, factory-generated secrets, or installation-time passwords that a deployer might forget to change before going live?
- **Cryptographic Secret Entropy**: Are cryptographic secrets — the JWT signing key, webhook HMAC keys, and the AES encryption key for stored TOTP secrets — generated with a cryptographically secure random number generator at deployment time? Or are any of them derived from the domain name, a hardcoded string, or a predictable seed?
- **`.env` and Secrets in Version Control**: Is `.env` or any file containing real secrets absent from version control? Verify the `.gitignore` configuration excludes all secret-bearing files. Verify the web server configuration (`.htaccess` or server block) prevents direct access to any `.env`, `.json`, or config file outside `public/`.

---

## PHASE 2.5: SYSTEMATIC REMEDIATION

After completing all audit categories and writing every finding to `docs/v2/audit/findings_working.md`, execute fixes in strict severity order: CRITICAL first, then HIGH, then MEDIUM, then LOW. Do not fix INFORMATIONAL findings.

### Fix Execution Protocol

For each finding to be fixed:

1. **Re-read the finding** in `findings_working.md` to confirm the exact file path, line numbers, and the nature of the bug before touching any file.
2. **Backup the file**: Copy it to `docs/v2/audit/backups/<filename>.bak` if no backup already exists for that file.
3. **Apply the fix**: Make the minimal, targeted change required to eliminate the confirmed issue.
4. **Verify the fix**: Re-read the modified section. Confirm: syntactically valid PHP 8.2+, logically correct, consistent with OwnPay's framework patterns.
5. **Log the fix immediately** in `docs/v2/audit/fixes_applied.md` using this exact format:

```
### FIX-NNN — [FIND-NNN] — [Short Title]
File: path/to/file.php:L10–25
Status: APPLIED

Before:
[exact original code block]

After:
[exact replacement code block]

Reason: [one sentence describing what was wrong and what the fix does]
```

6. **Update the finding** in `findings_working.md` to `Status: FIXED`.

### Architecture-Blocked Issues

If a fix requires changes to the boot pipeline, core container wiring, database schema, or produces ripple effects that cannot be safely traced to a single-file diff:

1. Do not apply a partial or speculative patch.
2. Write a complete fix specification to `docs/v2/audit/fix_specs/FIND-NNN.md`. The specification must include: the exact problem described technically, the complete recommended solution with full PHP code, every file that must be changed, the test steps to verify correctness after implementation, and the reason a partial single-file patch would be unsafe.
3. Mark the finding `Status: SPEC_WRITTEN` in the report.

---

## PHASE 3: FLOW SIMULATION PROTOCOL

For each of the following flows, simulate all five scenarios listed below and state the result of each in the final report:

**Flows to simulate:**
- Payment initiation → gateway redirect → callback receipt → ledger posting → status update
- Refund request → refund authorization → gateway refund call → ledger debit → status update
- Admin login → 2FA → session establishment
- Mobile device pairing → OTP validation → device registration → first SMS receipt
- Invoice creation → payment link generation → payment completion
- Outbound webhook delivery → retry on failure → deduplication

**Scenarios for each flow:**
1. **Happy Path** — Does the flow complete correctly end to end?
2. **Concurrent Execution** — What happens if two identical requests arrive at the same millisecond?
3. **Partial Failure** — What if the database write succeeds but the gateway API call fails (or vice versa)?
4. **Malicious Actor** — What if a caller deliberately crafts the input to manipulate the financial outcome?
5. **Replay Attack** — What if a valid webhook, callback, or OTP is captured and submitted again?

---

## PHASE 4: FINAL REPORT

Write the complete audit and remediation report to `docs/v2/audit/report_claude_fable_5.md`.

### Severity Classification

| Severity | Definition |
|---|---|
| **CRITICAL** | Exploitable remotely to steal funds, forge payment confirmations, bypass authentication, or cause total data breach. Release-blocking. |
| **HIGH** | Exploitable by authenticated users to gain unauthorized access, manipulate financial amounts, or cause significant data loss. Release-blocking. |
| **MEDIUM** | Logic gaps, missing validations, or design flaws that cause incorrect behavior under specific conditions. Should be fixed before release. |
| **LOW** | Missing best practices, incomplete hardening, or patterns that increase risk surface without a direct exploitation path. Fix before release where feasible. |
| **INFORMATIONAL** | Dead code, architectural observations, or maintainability concerns with no direct security or correctness impact. |

### Finding Status Values

Every finding must carry exactly one status:

- `FIXED` — A targeted fix was applied to the source code in Phase 2.5. The fix is logged in `fixes_applied.md`.
- `SPEC_WRITTEN` — The fix requires architectural changes that cannot be safely applied in a single-file diff. A complete fix specification is written to `docs/v2/audit/fix_specs/FIND-NNN.md`.
- `NO_FIX_NEEDED` — The finding is informational, or the observed behavior is confirmed intentional after reviewing the architecture documentation.
- `NEEDS_HUMAN_REVIEW` — The correct fix requires business logic context, external provider API documentation, or a decision by the maintainer that was not determinable from the codebase alone.

### Report Structure

```
# OwnPay — Master Audit & Remediation Report (Claude Fable 5)
Generated: [ISO 8601 timestamp]
Auditor: Claude Fable 5
Scope: Full codebase — pre-release security hardening audit
Gateway Sample: [names of the 3 adapters audited in Category D]

---

## Executive Summary

Total Findings: [N]
  CRITICAL: [N]  |  HIGH: [N]  |  MEDIUM: [N]  |  LOW: [N]  |  INFORMATIONAL: [N]

Remediation Outcome:
  Fixed in source:        [N] findings
  Fix specs written:      [N] findings
  Needs human review:     [N] findings
  No fix needed:          [N] findings

Top 3 Most Dangerous Issues: [one line each]
Overall Risk Rating: CRITICAL / HIGH / MEDIUM / LOW
Release Recommendation: SHIP / HOLDBACK
Release Recommendation Reason: [one or two sentences]

---

## Findings Registry

### [FIND-001] — [Short Title]

| Field | Value |
|---|---|
| Severity | CRITICAL |
| Status | FIXED |
| Category | Payment Logic |
| File(s) | `src/Services/PaymentService.php:L45–62` |
| Function(s) | `initiatePayment()` |

**Description:** [Precise technical description of what is wrong and why it is dangerous.
What an attacker or a race condition can do through this issue, in plain language.]

**Evidence:**
```php
// src/Services/PaymentService.php:L45–62
[exact original code snippet]
```

**Fix Applied:**
```php
[exact replacement code that was written to the file]
```

---

[Repeat for every finding]

---

## Gateway Fleet Risk Assessment

[Summary of the 3 adapters audited. Which vulnerability patterns were found.
Which checks passed cleanly. Worst-case risk interpretation for the full adapter fleet.
Complete pre-release checklist for every remaining adapter.]

---

## Flow Simulation Results

[Results of all Phase 3 simulations, organized by flow then by scenario.]

---

## Residual Risks & Architecture Debt

[Issues that were not patchable in this engagement. Reference to each fix spec file.
Priority order for the next development sprint.]

---

## Post-Release Hardening Recommendations

[Non-release-blocking hardening steps, monitoring and alerting recommendations,
and longer-term architectural improvements for the first post-release sprint.]
```

---

## FINALIZE & CLEAN UP

After the final report is written, verify all four conditions below before ending the session:

1. `docs/v2/audit/report_claude_fable_5.md` exists and is complete.
2. `docs/v2/audit/fixes_applied.md` exists and contains an entry for every finding marked `FIXED` in the report.
3. `docs/v2/audit/fix_specs/` contains a specification file for every finding marked `SPEC_WRITTEN` in the report.
4. `docs/v2/audit/backups/` contains a `.bak` file for every source file that was modified.

After all four conditions are confirmed, delete `docs/v2/audit/findings_working.md`.

The session ends only after all four conditions are verified and the working file is deleted.

---

## BEGIN

Start immediately with Phase 1: Reconnaissance & Codebase Mapping. Read `ARCHITECTURE.md` first, then complete all ten reconnaissance steps in order. Proceed through Phase 2, Phase 2.5, Phase 3, and Phase 4 without stopping, checking in, or asking for permission at any point. Write the final report to `docs/v2/audit/report_claude_fable_5.md`.
