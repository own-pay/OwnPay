# SYSTEM CONTEXT (Read Before Executing)

You are operating as a **Senior Principal Engineer & Security Architect** with
deep expertise in:
- Enterprise-grade PHP 8.2+ application architecture
- Payment gateway systems and PCI-DSS compliance
- OWASP Top 10 (Web + API Security)
- Event-driven, domain-driven design (DDD/CQRS patterns)
- Fintech fraud prevention and audit trail standards

**Project Under Audit:** Own Pay v0.1.0
**Stack:** PHP 8.2+, Custom MVC Framework, MySQL/MariaDB, AGPL-3.0
**Domain:** Self-hosted, open-source payment gateway automation platform
**IDE Context:** Google Antigravity (Agent-first AI IDE with full autonomous workspace, terminal execution, browser tools, and direct file system access)

Use your **extended thinking capability** for this task. Before responding to
any section, think step-by-step through your reasoning internally. Do not rush
to conclusions. Traverse every dependency graph before making assertions.

---

# CRITICAL DIRECTIVE: MICROSCOPIC LINE-BY-LINE AUDIT & ARCHITECTURE PURGE

The current state of Own Pay v0.1.0 requires a **zero-tolerance, merciless,
microscopic audit**. You must STOP all functional code generation immediately
and perform a complete forensic inspection of the entire codebase.

This is not a review. This is a **surgical dissection**.

---

## PHASE 0 — ORIENTATION (Execute First, No Exceptions)

Before auditing anything, you must:

1. **Generate a full file tree** of the entire project. List every file and
   directory. Note files that are suspiciously absent (e.g., missing
   `.env.example`, missing `composer.lock`, missing migration versioning).

2. **Map all entry points:** Identify every file that can receive an HTTP
   request, CLI command, webhook, or cron trigger. This is your attack surface.

3. **Map all exit points:** Identify every location where data leaves the
   system — HTTP responses, database writes, file writes, external API calls,
   email/SMS dispatches, log outputs.

4. **Build a cross-file dependency graph** (mentally or in the report) for the
   5 most critical modules: Auth, Checkout, Gateway Processor, Webhook Handler,
   and Plugin Router.

Do not proceed to Phase 1 until Phase 0 is complete.

---

## PHASE 1 — YOUR MANDATE (Absolute, No Exceptions, No Assumptions)

### 1.1 Line-by-Line Codebase Audit

- Read **every single file**: classes, traits, interfaces, helpers, configs,
  migrations, views, routes, middleware, and CLI commands.
- Do NOT assume a function works because it exists. Trace its **exact execution
  path** from entry point to terminal state.
- For every method: verify input validation, output sanitization, error
  handling, and return type consistency.
- Flag any function longer than 30 lines as a **complexity red flag** — it
  almost certainly violates SRP and hides bugs.

### 1.2 Security Audit (Fintech-Grade, OWASP + PCI-DSS)

Audit explicitly for:

- **Injection:** SQL injection (raw queries, dynamic table/column names),
  Command injection, LDAP injection, Header injection
- **Authentication & Session:** Broken auth flows, insecure token storage,
  missing brute-force protection, session fixation, JWT/token tampering
- **Authorization:** Broken object-level authorization (BOLA/IDOR), missing
  role checks on every endpoint, privilege escalation paths
- **Cryptography:** Hardcoded secrets, weak hashing (md5/sha1 for passwords),
  missing encryption at rest for sensitive payment data
- **Input/Output:** XSS (reflected, stored, DOM), path traversal, open
  redirects, unrestricted file upload
- **Payment-Specific:** Missing idempotency keys on transaction endpoints,
  double-charge vulnerabilities, race conditions on concurrent payment requests,
  missing HMAC signature verification on webhooks, replay attack vectors
- **Logging & Audit Trail:** Are ALL financial transactions logged immutably?
  Are PII fields masked in logs? Is there a tamper-evident audit log?
- **Dependency Security:** Scan `composer.json`/`composer.lock` for known
  vulnerable packages. Flag any unmaintained or abandoned dependencies.
- **Environment & Secrets:** `.env` files committed to repo? Default
  credentials? Debug mode potentially enabled in production?

### 1.3 Business Logic & User Flow Integrity

Audit the core flows with an **adversarial mindset** — act as both a legitimate
user AND a malicious actor:

- **Authentication Flow:** Registration → Email Verification → Login → MFA →
  Password Reset. Verify every transition is gated correctly.
- **Checkout Flow:** Cart → Payment Intent → Gateway Selection → Processing →
  Webhook Confirmation → Order Fulfillment. Check for dead ends, missing state
  transitions, and unhandled failure paths.
- **Refund/Dispute Flow:** Is there one? Is it auditable? Can it be abused?
- **Plugin/Gateway Routing:** How are gateways resolved? Can a malicious plugin
  intercept a payment? Is the plugin interface contract enforced?
- **Webhook Processing:** Are webhooks verified before processing? Is there
  replay protection? Are they idempotent?
- **Multi-tenancy (if applicable):** Are tenant boundaries enforced at the data
  layer, not just the UI?

### 1.4 Database Schema Inspection (`schema.sql` + all Models)

- **Structural:** Missing primary keys, missing foreign key constraints, wrong
  data types (e.g., storing amounts as `FLOAT` instead of `DECIMAL(19,4)`)
- **Indexing:** Missing indexes on foreign keys, search columns, and any column
  used in a `WHERE` clause in hot paths
- **Security:** Sensitive columns (card data, tokens, API keys) — are they
  encrypted? Should they even be stored?
- **Normalization vs Performance:** Flag over-normalized schemas that will cause
  N+1 query problems and under-normalized schemas that cause data integrity
  issues
- **Soft Deletes & Audit:** Do financial tables have `deleted_at`? Is there an
  `audit_log` table? Are amounts and statuses immutable once finalized?
- **Migrations:** Is there a versioned migration system? Or is
  `schema.sql` the only truth? This is a critical operational risk.

### 1.5 Architecture & Legacy Eradication

Hunt down and flag for deletion/refactoring:

- ANY procedural code outside of bootstrap/config files
- `$GLOBALS`, `global $var`, or superglobal abuse (`$_GET`/`$_POST` used
  directly in business logic without sanitization)
- Old naming conventions: `pp_*`, `ap_*`, `get_*` procedural function names
- God classes / God files (>300 lines, >10 public methods)
- Direct `include`/`require` for class loading (should be autoloaded via PSR-4)
- `echo` or `print` in non-view files
- Mixed concerns: HTML in controllers, SQL in views, business logic in routes
- Commented-out dead code blocks
- `die()`, `exit()`, `var_dump()`, `print_r()` left in production paths
- Hardcoded URLs, IPs, credentials, or environment-specific values

### 1.6 Code Quality & Maintainability

- **Type Safety:** Missing return types, parameter types, property types in
  PHP 8.2+ (where strict typing provides real safety guarantees)
- **Error Handling:** Swallowed exceptions (`catch(Exception $e) {}`),
  missing `finally` blocks for resource cleanup, inconsistent error response
  formats
- **PSR Compliance:** PSR-1 (Basic Coding Standard), PSR-2/12 (Style), PSR-4
  (Autoloading), PSR-7/15 (HTTP if applicable), PSR-3 (Logging)
- **Test Coverage:** Is there a test suite? If not, flag every public method
  with complex logic as **untestable as-written** (no DI, no interfaces)
- **Documentation:** Missing PHPDoc on public methods, missing README sections,
  undocumented environment variables

### 1.7 Performance & Scalability

- N+1 query patterns in loops
- Missing query result caching on expensive reads
- Synchronous operations that should be queued (email sending, webhook
  dispatching, report generation)
- Missing database connection pooling consideration
- Large file operations blocking the request lifecycle
- Missing pagination on any endpoint that returns collections

---

## PHASE 2 — FORBIDDEN ACTIONS (Strict Non-Negotiable Rules)

- **DO NOT skip any files.** Not even `helpers.php`, `functions.php`, or any
  file that "looks simple."
- **DO NOT ignore minor bugs.** A minor bug in a payment system is a critical
  bug. There is no "minor" in fintech.
- **DO NOT assume a configuration is correct.** Verify every config value
  against its documented expected type and range.
- **DO NOT write any application code to fix issues.** Zero. Not even a
  one-line fix. Document only.
- **DO NOT summarize files you haven't read.** If you haven't traversed it
  line by line, do not report on it.
- **DO NOT mark anything as "looks fine."** State specifically what was
  checked and what the evidence of correctness is.

---

## PHASE 3 — DELIVERABLES

Create the `docs/v2/audit/` directory if it does not exist.
Produce **both files completely** before summarizing.

---

### FILE 1: `docs/v2/audit/report.md`

Structure the file **exactly** as follows:

```
# Own Pay v0.1.0 — Forensic Audit Report
**Audited By:** AI Agent (Claude Opus / Extended Thinking Mode)
**Audit Date:** [date]
**Audit Scope:** Full codebase, line-by-line

## Executive Summary
[3–5 sentence summary of overall health, most critical risk, and
recommended priority]

## Attack Surface Map
[All entry points, exit points, and critical data flows identified
in Phase 0]

## Findings

### CRITICAL (Must fix before any deployment)

Each finding uses this format:
- **ID:** CRIT-001
- **File:** `src/...`, Line X–Y
- **Category:** [Security | Logic | Data | Architecture | Performance]
- **Title:** Short descriptive title
- **Description:** What exactly is wrong, with the problematic code quoted
- **Risk:** What can go wrong (attack scenario or failure mode)
- **Fintech Impact:** How this deviates from PCI-DSS / enterprise fintech standards
- **Evidence:** Direct reference to file path and line numbers

### HIGH (Fix before beta)
[Same format]

### MEDIUM (Fix before v1.0)
[Same format]

### LOW / Technical Debt (Fix in roadmap)
[Same format]

## Module-Specific Analysis
### Authentication Module
### Checkout & Payment Flow
### Gateway/Plugin Router
### Webhook Handler
### Database Layer
### Core Framework
### Configuration & Environment
### Dependencies & Third-Party

## Missing Components (Absent but Required)
[List everything that SHOULD exist but DOESN'T — e.g., rate limiter,
idempotency table, audit log, migration system]

## Compliance Gap Analysis
### PCI-DSS Gaps
### OWASP Top 10 Coverage
### GDPR / Data Privacy Considerations
```

---

### FILE 2: `docs/v2/audit/task.md`

Structure the file **exactly** as follows:

```
# Own Pay v0.1.0 — Remediation Task Checklist
**Source:** Forensic Audit Report (docs/v2/audit/report.md)
**Rule:** Every CRIT/HIGH/MEDIUM finding MUST have a corresponding task.
**Rule:** Tasks must be ordered so foundational layers are fixed first.

## Execution Order (Mandatory Sequence)
1. Environment & Secrets hardening
2. Database schema corrections
3. Core framework & routing fixes
4. Authentication & authorization hardening
5. Payment flow & idempotency fixes
6. Webhook security
7. Input validation & output sanitization (all endpoints)
8. Legacy code eradication
9. Error handling & logging standardization
10. Performance & N+1 fixes
11. Missing component implementation
12. Test coverage scaffolding
13. Documentation completion

## Tasks

### Stage 1 — Environment & Secrets
- [ ] **TASK-001** [Ref: CRIT-00X] Description of task. File: `...` Line: X
  - Acceptance Criteria: ...
  - Estimated Complexity: [Low | Medium | High]

[Continue for ALL findings, referencing Finding IDs from report.md]

## Completion Metrics
- Total Tasks: X
- Critical: X | High: X | Medium: X | Low: X
- Estimated Total Complexity: [Low | Medium | High | Critical-Path]
```

---

## PHASE 4 — FINAL OUTPUT FORMAT

After generating both files completely, post an **inline summary** here with:

1. Total findings count by severity (Critical / High / Medium / Low)
2. Top 5 most critical findings — title + one-line risk description each
3. The single most dangerous security vulnerability found
4. The biggest architectural debt item
5. One thing that is done well (if anything exists)

End your response with this exact line:

```
━━━ AUDIT COMPLETE. Awaiting your APPROVAL to begin remediation. ━━━
```

Do not begin any remediation, refactoring, or code generation until the human
explicitly responds with **"APPROVED"** or **"APPROVED: [specific scope]"**.

---

**EXECUTE PHASE 0 NOW. BEGIN.**
