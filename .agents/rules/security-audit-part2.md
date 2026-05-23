---
trigger: model_decision
description: Apply for security audits, reviews, vulnerabilities, risk/pentest tasks, or when auditing PHP code, payments, authentication, authorization, API endpoints, file uploads, webhooks, or cryptography.
---

# ISO-27001 & PCI-DSS Secure Coding Compliance (Static System Hardening) - Part 2/2

> [!IMPORTANT]
> **COMPLIANCE MANDATE:** You are operating as a senior software architect and developer with 20+ years of experience in trillion-dollar fintech platforms. In this offline local environment, you are fully authorized to develop, optimize, refactor, and implement features, as well as conduct thorough secure-coding compliance audits, acting as an internal compliance and system hardening authority.

# Security Audit — OwnPay (OWASP Top 10:2025) Part 2/2

> Continuation of `security-audit.md`. Contains A06–A10, OwnPay-specific checks, risk matrix, report format, and PHP patterns.

---

## OWASP Top 10:2025 — A06 through A10

---

### A06:2025 — Insecure Design *(HIGH)*

```
Payment Flow Design
  - Checkout flow CSRF-protected at every step?
  - Payment intents single-use with expiry? (idempotency keys enforced)
  - Rate limiting on payment attempts per IP and per customer?
  - Maximum retry limit on failed gateway calls?
  - Duplicate gateway callbacks rejected via idempotency keys?

Ledger Integrity Design
  - Every ledger transaction in db()->beginTransaction() with rollback on failure?
  - Ledger accounts locked: SELECT ... FOR UPDATE during balance updates?
  - sum(debits) == sum(credits) enforced on every journal entry?

Business Logic
  - Payment amounts validated server-side (never trust gateway callback amounts)?
  - Currency conversion rate applied at intent creation, not at posting?
  - Fee calculation rules protected from manipulation?

Architecture
  - Plugin system sandboxed BEFORE any execution? (PluginSandbox)
  - Installer (/install/*) disabled after installation complete?
  - High-risk admin operations require re-authentication?
```

---

### A07:2025 — Authentication Failures *(CRITICAL)*

```
Session & Password
  - TOTP 2FA enforced for superadmin? Login blocked without TOTP?
  - TOTP replay protection: $_SESSION['totp_last_used_window'] checked?
  - Account lockout: max 5 failed logins in 15 min with exponential backoff?
  - session_regenerate_id(true) called immediately after login?
  - Session cookies: Secure; HttpOnly; SameSite=Strict?
  - session_destroy() + cookie invalidated on logout?
  - Concurrent superadmin sessions detected?

JWT (Mobile API)
  - All of iss, aud, sub, exp, jti claims present and verified?
  - alg:none tokens rejected immediately?
  - Device revocation checked against op_paired_devices on every request?
  - Used JTI values tracked in op_cache to prevent refresh token replay?
  - Access tokens expire within 1 hour? Refresh tokens within 30 days?

Password Reset
  - Reset tokens: random_bytes(32), expire in 1 hour, single-use?
  - Reset flow uses hash_equals() — never == (timing-safe)?
  - Session fixation exploitable via reset flow?
```

---

### A08:2025 — Software or Data Integrity Failures *(HIGH)*

```
Webhook & Signatures
  - Webhook signatures verified with hash_equals()? (never == or ===)
  - Raw request body used for signature check (not re-serialized parsed data)?
  - Webhook timestamp freshness checked? (reject if > 5 min old)
  - Webhooks verified BEFORE any state change (ledger posting, tx update)?

PHP Object Injection
  - unserialize() ever called on user-controlled input? (CRITICAL — RCE)
  - json_decode() used for all user-provided structured data?

Auto-Update & CSRF
  - Auto-update system (src/Update/) verifies package signatures over HTTPS?
  - CSRF enforced on all non-API POST/PUT/PATCH/DELETE via CsrfMiddleware?
  - SecurityHelpers::csrfToken() used? (never manual $_SESSION['csrf_token'])
  - CSRF comparison uses hash_equals()?
  - CSRF tokens rotated after each state-mutating form submission?

Dependency Integrity
  - composer.lock integrity verified in CI/CD on every build?
  - Plugin activations logged in op_audit_logs?
```

---

### A09:2025 — Logging & Alerting Failures *(MEDIUM)*

**Must Log (op_audit_logs)**
```
  ✓ Failed login attempts (IP, user-agent, timestamp)
  ✓ Successful logins and logouts
  ✓ Privilege escalation and role changes
  ✓ Ledger posting events (full journal context)
  ✓ Gateway callback verification failures (with IP)
  ✓ Plugin activation / deactivation
  ✓ Brand create/delete/settings changes
  ✓ API key creation and revocation
  ✓ 2FA enable/disable events
```

**Must NOT Appear in Logs**
```
  ✗ Passwords or password hashes
  ✗ TOTP codes or raw totp_secret values
  ✗ Full card numbers or CVV
  ✗ Raw gateway API credentials
  ✗ JWT tokens or session tokens
  ✗ Unmasked PII — PIIMasker must be applied to email, phone, name
```

**Integrity**
```
  - Logs stored in /storage/logs/ — not web-accessible?
  - Log files write-only for web app user?
  - Alerting configured for: repeated failed logins, unusual payment volumes,
    admin access at unusual hours, plugin activations?
```

---

### A10:2025 — Mishandling of Exceptional Conditions *(HIGH — NEW)*
> 24 CWEs. Fail-open behavior, swallowed exceptions, error info disclosure, resource exhaustion.

**Fail-Open Authorization (CRITICAL)**
```php
// ❌ FAIL-OPEN — security check swallowed; attacker reaches sensitive op
try {
    $this->permissionMiddleware->check($req, 'edit_brand');
} catch (\Exception $e) {
    $this->logger->error($e->getMessage()); // execution FALLS THROUGH
}
$this->editBrand($data); // executed even without permission ← CRITICAL

// ✅ FAIL-CLOSED — sensitive op inside try; catch returns early
try {
    $this->permissionMiddleware->check($req, 'edit_brand');
    $this->editBrand($data);
} catch (UnauthorizedException $e) {
    return $this->forbidden();
}
```

**Exception Swallowing**
```
  - Are catch blocks ever empty or logging-only without stopping execution?
  - Do all exceptions either produce the correct response or get re-thrown?
  - Does Kernel::handleException() catch all unhandled exceptions globally?
```

**Error Information Disclosure**
```
  - Do catch blocks ever echo/return $e->getMessage() or $e->getTrace()?
    (HIGH — reveals DB schema, file paths, internal logic)
  - Is APP_DEBUG=false in production? (stack traces never reach client)
  - Do API JSON error responses contain only generic messages?
  - Does Kernel::handleException() sanitize file paths before logging?
```

**Resource Cleanup & State**
```
  - DB connections / file handles closed in finally blocks on exception?
  - Locked ledger rows unlocked even when exception occurs mid-transaction?
  - DB transactions rolled back on exception (not left open/dirty)?
  - Idempotency keys marked used ONLY after successful processing?
  - Are PDOException errors caught and never echoed to users?
  - Are TypeError / ValueError from strict_types handled gracefully?
```

---

## 3) OwnPay-Specific Security Checks

### Multi-Brand IDOR & Tenant Isolation
```
- Every brand-scoped query calls forTenant($mid) first?
- BrandContext::resolveFromRequest() in EVERY admin controller before data access?
- Staff of Brand A cannot access Brand B's transactions, customers, or gateways?
- Brand switcher restricted to only brands the staff member is assigned to?
```

### Ledger Race Conditions
```
- All ledger transactions wrapped in db()->beginTransaction() with rollback?
- SELECT ... FOR UPDATE used on ledger accounts during balance updates?
- Can gateway callback post to wrong merchant's ledger account?
- Duplicate callbacks rejected via idempotency keys?
```

### Plugin Sandbox Escape
```
- PluginSandbox blocks: exec, shell_exec, passthru, system, popen,
  proc_open, eval, backticks, pcntl_exec, dl, assert (string form)?
- Variable function bypass detected? ($func = 'exec'; $func('id'))
- call_user_func/call_user_func_array with dangerous callbacks detected?
- Plugins scanned BEFORE activation (not just on install)?
```

### File Upload Security
```
- MIME type AND magic-byte validation on uploaded files?
- Filename sanitized (no path traversal, no .php extension)?
- Server-side file size limit enforced?
- Image dimensions validated (prevent decompression bomb attacks)?
```

---

## 4) Risk Assessment Matrix

| Severity × Likelihood | Very Likely | Likely | Possible | Unlikely |
|---|---|---|---|---|
| **Critical** | P1 | P1 | P1 | P2 |
| **High** | P1 | P2 | P2 | P3 |
| **Medium** | P2 | P3 | P3 | P4 |
| **Low** | P3 | P4 | P4 | P4 |

**P1** = Fix now (hotfix deploy) · **P2** = This sprint · **P3** = Backlog · **P4** = Accept/document

---

## 5) Vulnerability Report Format

```
## [SEVERITY] [CVSS v3.1: x.x] — Title

- **OWASP 2025**: Axx:2025 — Category Name
- **CWE**: CWE-xxx (Name)
- **Location**: src/Path/File.php:Line  or  Route /endpoint
- **Description**: What is the vulnerability and why it exists?
- **Impact**: What can an attacker achieve if exploited?
- **Proof of Concept**: Minimal PHP payload or reproduction steps
- **Remediation**: Specific fix with PHP code example
- **References**: https://owasp.org/Top10/Axx/ · https://cwe.mitre.org/data/definitions/xxx.html
- **Priority**: P1 / P2 / P3 / P4
```

---

## 6) PHP-Specific Vulnerability Patterns

```php
// A10: Fail-open catch — security check swallowed
try { checkPermission($user, 'admin'); } catch (\Exception $e) { log($e); }
sensitiveOp(); // ← still executes — CRITICAL
// Fix: put sensitiveOp() inside the try block

// A05: Twig SSTI — user input as template source = RCE
$twig->createTemplate($userInput)->render($ctx);
// Fix: always use as variable
$twig->render('page.twig', ['content' => $userInput]);

// A08: PHP object injection — RCE via gadget chains
$cart = unserialize(base64_decode($_COOKIE['cart']));
// Fix: use json_decode() exclusively for user-controlled data
$cart = json_decode($_COOKIE['cart'], true, 512, JSON_THROW_ON_ERROR);

// A01: IDOR — no brand scope
$tx = $this->txRepo->find($_GET['id']); // returns ANY merchant's record
// Fix: always scope to active brand
$tx = $this->txRepo->forTenant($mid)->findScoped((int)$_GET['id']);

// A04: Type juggling on tokens (loose == comparison)
if ($token == $storedToken) { ... } // "0e123" == "0e456" is TRUE in PHP
// Fix: always use hash_equals() for constant-time comparison
if (!hash_equals($storedToken, $token)) { throw new SecurityException(); }

// A03: Unpinned composer dependency — can pull compromised version
"vendor/package": "^1.0"
// Fix: pin exact version + run composer audit on every CI build
"vendor/package": "1.2.3"

// A05: SQL injection via string concatenation
$pdo->query("SELECT * FROM op_transactions WHERE id = " . $_GET['id']);
// Fix: parameterized only
$stmt = $pdo->prepare("SELECT * FROM op_transactions WHERE id = ?");
$stmt->execute([$_GET['id']]);
```
