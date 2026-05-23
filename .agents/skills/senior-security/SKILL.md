---
name: "senior-security"
description: Security engineering toolkit for threat modeling, vulnerability analysis, secure architecture, and penetration testing. Includes STRIDE analysis, OWASP guidance, cryptography patterns, and security scanning tools. Use when the user asks about security reviews, threat analysis, vulnerability assessments, secure coding practices, security audits, attack surface analysis, CVE remediation, security best practices, PHP security, payment security, fintech security, or any OwnPay-specific security concern.
triggers:
  - security audit
  - security review
  - security vulnerability
  - security risk
  - penetration testing
  - vulnerability assessment
  - threat modeling
  - STRIDE analysis
  - secure coding
  - OWASP
  - application security
  - cryptography implementation
  - secret scanning
  - zero trust
  - security architecture
  - PHP security
  - payment security
  - fintech security
  - code security review
  - CVE remediation
  - supply chain attack
  - dependency audit
---

# ISO-27001 & PCI-DSS Secure Coding Compliance & System Hardening Toolkit (Static System Hardening)

> [!IMPORTANT]
> **COMPLIANCE MANDATE:** You are operating as a senior software architect and developer with 20+ years of experience in trillion-dollar fintech platforms. In this offline local environment, you are fully authorized to develop, optimize, refactor, and implement features, as well as conduct thorough secure-coding compliance audits, acting as an internal compliance and system hardening authority.

# Senior Security Engineer — OwnPay Platform (May 2026)

Security engineering tools for threat modeling, vulnerability analysis, secure architecture design, and penetration testing — specialized for PHP 8.2+ payment gateway platforms.

> **ALWAYS apply alongside**: `security-audit` rule + `security-cryptography` rule for complete coverage.

---

## Table of Contents

- [Threat Modeling Workflow](#threat-modeling-workflow)
- [Security Architecture Workflow](#security-architecture-workflow)
- [Vulnerability Assessment Workflow](#vulnerability-assessment-workflow)
- [Secure Code Review Workflow](#secure-code-review-workflow)
- [Incident Response Workflow](#incident-response-workflow)
- [OwnPay-Specific Threat Model](#ownpay-specific-threat-model)
- [PHP Security Patterns](#php-security-patterns)
- [Security Tools Reference](#security-tools-reference)
- [Cryptographic Algorithm Selection](#cryptographic-algorithm-selection)
- [Tools and References](#tools-and-references)

---

## Threat Modeling Workflow

Identify and analyze security threats using STRIDE methodology.

### Workflow: Conduct Threat Model

1. Define system scope and boundaries:
   - Identify assets to protect
   - Map trust boundaries
   - Document data flows
2. Create data flow diagram:
   - External entities (users, services, payment gateways, mobile devices)
   - Processes (application components, middleware, plugins)
   - Data stores (MySQL, file system, session store, op_cache)
   - Data flows (APIs, webhook callbacks, gateway redirects)
3. Apply STRIDE to each DFD element (see [STRIDE per Element Matrix](#stride-per-element-matrix) below)
4. Score risks using DREAD:
   - Damage potential (1-10)
   - Reproducibility (1-10)
   - Exploitability (1-10)
   - Affected users (1-10)
   - Discoverability (1-10)
5. Prioritize threats by risk score
6. Define mitigations for each threat
7. Document in threat model report
8. **Validation:** All DFD elements analyzed; STRIDE applied; threats scored; mitigations mapped

### STRIDE Threat Categories

| Category | Security Property | Mitigation Focus |
|----------|-------------------|------------------|
| Spoofing | Authentication | MFA, certificates, strong auth, JWT `alg:none` protection |
| Tampering | Integrity | Signing, checksums, validation, ledger atomicity |
| Repudiation | Non-repudiation | Audit logs, digital signatures, immutable ledger entries |
| Information Disclosure | Confidentiality | Encryption, access controls, PII masking, error sanitization |
| Denial of Service | Availability | Rate limiting, redundancy, payment idempotency |
| Elevation of Privilege | Authorization | RBAC, least privilege, TenantScope isolation, brand IDOR protection |

### STRIDE per Element Matrix

| DFD Element | S | T | R | I | D | E |
|-------------|---|---|---|---|---|---|
| External Entity | X | | X | | | |
| Process | X | X | X | X | X | X |
| Data Store | | X | X | X | X | |
| Data Flow | | X | | X | X | |

See: [references/threat-modeling-guide.md](references/threat-modeling-guide.md)

---

## Security Architecture Workflow

Design secure systems using defense-in-depth principles.

### Workflow: Design Secure Architecture

1. Define security requirements:
   - Compliance requirements (GDPR, PCI-DSS v4.0, AGPL-3.0)
   - Data classification (public, internal, confidential PII, restricted PCI)
   - Threat model inputs
2. Apply defense-in-depth layers:
   - Perimeter: WAF, DDoS protection, rate limiting
   - Network: Segmentation, IDS/IPS, mTLS
   - Host: Patching, EDR, hardening
   - Application: Input validation, authentication, secure coding
   - Data: Encryption at rest and in transit, key rotation
3. Implement Zero Trust principles:
   - Verify explicitly (every request, every device)
   - Least privilege access (JIT/JEA, brand-scoped roles)
   - Assume breach (segment, monitor, audit trail)
4. Configure authentication and authorization:
   - Argon2id password hashing
   - TOTP 2FA with replay protection
   - RBAC with superadmin bypass only where required
5. Design encryption strategy:
   - AES-256-GCM for data at rest
   - TLS 1.3 for transit
   - Key derivation with HKDF-SHA256
   - Key rotation procedures
6. Plan security monitoring:
   - Structured logging to op_audit_logs
   - Alerting on failed auth, privilege escalation, unusual payment volumes
   - SIEM integration capability
7. Document architecture decisions
8. **Validation:** Defense-in-depth layers defined; Zero Trust applied; encryption strategy documented; monitoring planned

### Defense-in-Depth Layers

```
Layer 1: PERIMETER
  WAF, DDoS mitigation, DNS filtering, rate limiting (RateLimiterMiddleware)

Layer 2: NETWORK
  HTTPS/TLS 1.3, CORS (CorsMiddleware), IP allowlisting (IpAllowlistMiddleware)

Layer 3: HOST
  OS hardening, PHP-FPM config hardening, file permissions, no PHP in upload dirs

Layer 4: APPLICATION
  Input validation (InputSanitizer), auth (Authenticator + 2FA), RBAC (PermissionMiddleware),
  CSRF (CsrfMiddleware), domain isolation (DomainMiddleware), plugin sandbox (PluginSandbox)

Layer 5: DATA
  Argon2id passwords, AES-256-GCM secrets, double-entry ledger integrity,
  parameterized SQL, TenantScope isolation, PII masking in logs
```

### Authentication Pattern Selection

| Use Case | Recommended Pattern |
|----------|---------------------|
| Admin web panel | Session + Argon2id + TOTP 2FA |
| REST API (public) | HMAC-signed request or API key |
| Mobile companion app | Device-pinned JWT (HS256) with JTI blacklisting |
| Service-to-service | mTLS with certificate rotation |
| Webhooks/IPN | HMAC-SHA256 signature + IP allowlist |
| High-value operations | Re-authentication required (not just session) |

See: [references/security-architecture-patterns.md](references/security-architecture-patterns.md)

---

## Vulnerability Assessment Workflow

Identify and remediate security vulnerabilities in PHP payment applications.

### Workflow: Conduct Vulnerability Assessment

1. Define assessment scope:
   - In-scope: admin panel, checkout flow, REST APIs, mobile API, webhooks, plugins
   - Testing methodology: white-box (full code access)
   - Rules of engagement: no destructive testing on live ledger data
2. Gather information:
   - Technology stack: PHP 8.2, MySQL 8.x, Twig 3.x, vlucas/phpdotenv
   - Architecture: single-entry `public/index.php`, PSR-11 DI, repository pattern
   - Previous vulnerability reports and ARCHITECTURE.md
3. Perform automated scanning:
   - **PHP SAST**: `./vendor/bin/phpstan analyse` (PHPStan level 8)
   - **PHP Security**: `composer audit` (advisory database check)
   - **Secrets**: `gitleaks detect --source . --verbose`
   - **Dependencies**: `composer outdated` + `local-php-security-checker`
4. Conduct manual testing:
   - Business logic: payment amount manipulation, currency bypass, ledger tampering
   - Multi-brand IDOR: cross-merchant data access via merchant_id manipulation
   - Authentication bypass: JWT `alg:none`, session fixation, TOTP replay
   - Injection: SQL, Twig SSTI, path traversal in file uploads
   - Plugin sandbox escape: variable function bypass patterns
5. Classify findings by severity:
   - Critical: Immediate exploitation risk (RCE, auth bypass, financial fraud)
   - High: Significant impact (data breach, privilege escalation, IDOR)
   - Medium: Moderate impact or difficulty (CSRF, information disclosure)
   - Low: Minor impact (verbose errors in non-critical contexts)
6. Develop remediation plan:
   - Prioritize by risk (P1 = fix now, P2 = this sprint, P3 = backlog)
   - Assign specific PHP/Twig code fixes
   - Set deadlines based on severity
7. Verify fixes and document
8. **Validation:** Scope defined; automated and manual testing complete; findings classified; remediation tracked

### Vulnerability Severity Matrix

| Impact \ Exploitability | Easy | Moderate | Difficult |
|-------------------------|------|----------|-----------|
| Critical | Critical (P1) | Critical (P1) | High (P2) |
| High | Critical (P1) | High (P2) | Medium (P3) |
| Medium | High (P2) | Medium (P3) | Low (P4) |
| Low | Medium (P3) | Low (P4) | Low (P4) |

---

## Secure Code Review Workflow

Review PHP code for security vulnerabilities before deployment.

### Workflow: Conduct Security Code Review

1. Establish review scope:
   - Changed controllers, repositories, middleware, and services
   - Security-sensitive areas: auth, crypto, input handling, payment flow
   - Third-party gateway plugins (all files)
2. Run automated analysis:
   - `composer audit` — PHP advisory database
   - `./vendor/bin/phpstan analyse --level=8` — static analysis
   - `gitleaks detect --staged` — secret detection
   - `grep -rn "eval\|exec\|shell_exec\|system\|passthru" src/` — dangerous function scan
3. Review authentication code:
   - Argon2id parameters correct (memory≥65536, time≥3, threads≥4)?
   - Session regenerated after login?
   - TOTP replay window tracked in session?
4. Review authorization code:
   - BrandContext::resolveFromRequest() called before every brand-scoped query?
   - forTenant($mid) called before every scoped repository operation?
   - PermissionMiddleware on all admin routes?
5. Review data handling:
   - InputSanitizer::array() with explicit field allowlist?
   - All SQL via PDO prepared statements?
   - File upload MIME + extension validation?
   - Twig |raw used only on pre-sanitized content?
6. Review cryptographic code:
   - AES-256-GCM for encryption (not CBC/ECB)?
   - HMAC-SHA256 for signatures?
   - random_bytes() for token generation?
7. Document findings with CWE, OWASP category, and CVSS score
8. **Validation:** Automated scans passed; auth/authz reviewed; data handling checked; crypto verified; findings documented

### Security Code Review Checklist

| Category | Check | Risk |
|----------|-------|------|
| Input Validation | InputSanitizer::array() with allowlist | Injection / mass assignment |
| Output Encoding | Twig auto-escape ON; \|raw only for trusted content | XSS / Twig SSTI |
| Authentication | Argon2id with correct parameters | Credential theft |
| 2FA | TOTP replay window tracked in session | Authentication bypass |
| Session | Secure;HttpOnly;SameSite=Strict; regenerated on login | Session hijacking |
| Authorization | BrandContext + forTenant() + PermissionMiddleware | IDOR / privilege escalation |
| SQL | PDO prepared statements — zero string interpolation | SQL injection |
| File Access | Path traversal sequences rejected; MIME validated | Path traversal / RCE |
| Secrets | No hardcoded keys; EnvironmentService::get() only | Information disclosure |
| Logging | PII masked via PIIMasker; no stack traces in prod | Information disclosure |
| Ledger | db()->beginTransaction() + SELECT FOR UPDATE | Race condition / financial fraud |
| Plugins | PluginSandbox scan before activation; blocklist enforced | RCE via plugin |
| JWT | iss+aud+exp claims required; alg:none rejected; device active check | Auth bypass |
| CSRF | SecurityHelpers::csrfToken(); hash_equals() validation | CSRF |
| Webhooks | hash_equals() signature check; IP allowlist; timestamp freshness | Replay attack |

### PHP Secure vs Insecure Patterns

| Pattern | Issue | Secure Alternative |
|---------|-------|-------------------|
| SQL string formatting | SQL injection | PDO prepared statements |
| `unserialize($_POST[...])` | PHP object injection RCE | `json_decode()` only |
| `include($_GET['page'])` | Path traversal / RCE | Allowlist-based routing |
| `eval($userInput)` | Code execution | Never use eval on user data |
| `$func = 'exec'; $func(...)` | Variable function bypass | Function allowlist + sandbox |
| `md5()` / `sha1()` for passwords | Weak hashing | `password_hash(pass, PASSWORD_ARGON2ID)` |
| `rand()` / `mt_rand()` for tokens | Predictable | `random_bytes(32)` |
| `==` comparison on tokens/hashes | Timing attack / type juggling | `hash_equals()` always |
| `$twig->createTemplate($userInput)` | Twig SSTI | `$twig->render('file.twig', ['var'=>$input])` |
| `header("Location: " . $_GET['url'])` | Open redirect | Validate against allowlist |
| Raw `$_POST` to repository create/update | Mass assignment | Explicit field extraction + InputSanitizer |

---

## Incident Response Workflow

Respond to and contain security incidents in OwnPay.

### Workflow: Handle Security Incident

1. Identify and triage:
   - Validate incident is genuine (not a false positive)
   - Assess initial scope: which brands, which payment data, which users affected?
   - Activate incident response team
2. Contain the threat:
   - Isolate affected system(s) or brand(s)
   - Block malicious IPs at WAF/server level
   - Disable compromised `op_merchant_users` accounts
   - Revoke all `op_paired_devices` for compromised mobile sessions
   - Rotate `JWT_SECRET` and `HMAC_KEY` if compromise is suspected
3. Eradicate root cause:
   - Remove malicious plugin files
   - Patch vulnerable code and deploy
   - Clear op_cache of any poisoned entries
   - Regenerate compromised API keys (`op_api_keys`)
4. Recover operations:
   - Restore from verified clean backups if needed
   - Verify ledger integrity (check double-entry balance)
   - Monitor for recurrence via op_audit_logs
5. Conduct post-mortem:
   - Full timeline reconstruction from op_audit_logs
   - Root cause analysis
   - Lessons learned — what detection failed?
6. Implement improvements:
   - Update detection rules and alerting
   - Enhance PluginSandbox blocklist if needed
   - Update rate limiting thresholds
7. Document and report (GDPR 72-hour breach notification if PII/payment data affected)
8. **Validation:** Threat contained; root cause eliminated; systems recovered; post-mortem complete; improvements implemented

### Incident Severity Levels

| Level | Response Time | Escalation |
|-------|---------------|------------|
| P1 - Critical (active breach, payment fraud, data exfiltration) | Immediate | CISO, Legal, Executive, affected brand owners |
| P2 - High (confirmed, contained, no ongoing exfiltration) | 1 hour | Security Lead, IT Director |
| P3 - Medium (potential, under investigation) | 4 hours | Security Team |
| P4 - Low (suspicious, low impact) | 24 hours | On-call engineer |

### Incident Response Checklist

| Phase | Actions |
|-------|---------|
| Identification | Validate alert, assess scope, determine severity, check op_audit_logs |
| Containment | Isolate systems, disable accounts, revoke tokens, preserve evidence |
| Eradication | Remove threat, patch code, rotate compromised keys, clear poisoned cache |
| Recovery | Restore services, verify ledger integrity, verify double-entry balance |
| Lessons Learned | Document timeline, identify gaps, update PluginSandbox + rate limits |

---

## OwnPay-Specific Threat Model

### Critical Assets

| Asset | Location | Threat |
|---|---|---|
| Payment transaction data | `op_transactions` | Data breach, tampering |
| Gateway API credentials | `op_gateway_configs` (encrypted) | Credential theft → fraudulent charges |
| Ledger entries | `op_ledger_entries` | Financial fraud via ledger manipulation |
| TOTP secrets | `totp_secret_enc` (encrypted) | 2FA bypass if key compromised |
| Admin sessions | `$_SESSION` | Session hijacking → full system access |
| Plugin files | `modules/gateways/`, `modules/addons/` | Malicious plugin → RCE |
| Custom domain configs | `op_domains` | Domain spoofing → phishing |

### OwnPay Attack Scenarios (STRIDE)

| Scenario | STRIDE | Mitigation |
|---|---|---|
| Attacker changes `merchant_id` in request to access another brand's data | Elevation of Privilege | BrandContext + forTenant() + PermissionMiddleware |
| Attacker replays TOTP code within same time window | Spoofing | `$_SESSION['totp_last_used_window']` check |
| Malicious plugin calls `exec()` to gain server shell | Elevation of Privilege | PluginSandbox blocklist + variable function check |
| Attacker injects Twig template via `custom_css` field | Tampering / EoP | Sanitize before save; never `createTemplate($input)` |
| Gateway callback with manipulated payment amount | Tampering | Validate amount server-side; compare against intent |
| Attacker registers custom domain matching another brand's | Spoofing | `dns_verified=1` required; `APP_DOMAIN` protection |
| Refresh token replay after device revocation | Spoofing | JTI blacklisting in `op_cache` + device status check |
| Race condition on ledger balance update | Tampering | `SELECT ... FOR UPDATE` + `beginTransaction()` |
| Webhook replay attack from compromised gateway | Tampering | Timestamp freshness check (max 5 min) + idempotency |
| Supply chain attack via compromised Composer package | Tampering | `composer audit` in CI/CD + `composer.lock` pinning |
| LLM prompt injection via user-controlled gateway metadata | Information Disclosure | Sanitize all gateway response data before processing/logging |

### Multi-Brand IDOR Attack Pattern

```php
// ❌ IDOR vulnerability — no brand scope check
public function getTransaction(Request $req): Response {
    $id = $req->getParam('id');
    $tx = $this->txRepo->find($id);  // Returns ANY merchant's transaction!
    return $this->json($tx);
}

// ✅ Correct — always scope to active brand
public function getTransaction(Request $req): Response {
    $brand = $this->c->get(BrandContext::class);
    $brand->resolveFromRequest($req);
    $mid = $brand->getActiveBrandId();

    $tx = $this->txRepo->forTenant($mid)->findScoped((int)$req->getParam('id'));
    if (!$tx) {
        return $this->notFound();
    }
    return $this->json($tx);
}
```

---

## PHP Security Patterns

### SQL Injection — PHP/PDO

```php
// ❌ Vulnerable
$stmt = $pdo->query("SELECT * FROM op_transactions WHERE merchant_id = " . $_GET['mid']);

// ✅ Secure — parameterized
$stmt = $pdo->prepare("SELECT * FROM op_transactions WHERE merchant_id = ?");
$stmt->execute([$merchantId]);
```

### Password Hashing — Argon2id

```php
// ❌ Insecure
$hash = md5($password);
$hash = sha1($password);
$hash = password_hash($password, PASSWORD_BCRYPT);  // Acceptable but not preferred

// ✅ Correct — Argon2id with OWASP 2025 parameters
$hash = password_hash($password, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,  // 64 MB
    'time_cost'   => 3,
    'threads'     => 4,
]);

// Verify
if (!password_verify($input, $hash)) {
    // Failed — use hash_equals indirectly via password_verify (already timing-safe)
    throw new AuthenticationException('Invalid credentials');
}
```

### Secure Token Generation

```php
// ❌ Predictable
$token = uniqid() . rand(1000, 9999);
$token = md5(time() . $userId);

// ✅ Cryptographically secure
$token = bin2hex(random_bytes(32));  // 64-char hex token
$token = base64_encode(random_bytes(32));  // For URL use: base64_encode(random_bytes(32))
```

### Timing-Safe Comparison

```php
// ❌ Vulnerable to timing attack
if ($userToken === $storedToken) { ... }
if ($webhookSig == $computedSig) { ... }

// ✅ Timing-safe
if (!hash_equals($storedToken, $userToken)) {
    throw new SecurityException('Invalid token');
}
```

### AES-256-GCM Encryption (for TOTP secrets, gateway credentials)

```php
// Encrypt
function encrypt(string $plaintext, string $key): string {
    $iv = random_bytes(12);  // 96-bit IV for GCM
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv . $tag . $ciphertext);
}

// Decrypt
function decrypt(string $encoded, string $key): string {
    $data = base64_decode($encoded);
    $iv         = substr($data, 0, 12);
    $tag        = substr($data, 12, 16);
    $ciphertext = substr($data, 28);
    $plaintext  = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        throw new \RuntimeException('Decryption failed — data may be tampered');
    }
    return $plaintext;
}
```

### PHP Object Injection Prevention

```php
// ❌ Dangerous — can lead to RCE via PHP gadget chains
$data = unserialize(base64_decode($_COOKIE['cart']));

// ✅ Safe — only accept JSON
$data = json_decode($_COOKIE['cart'], true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data)) {
    throw new \InvalidArgumentException('Invalid cart data');
}
```

### Secret Scanning — PHP-Specific Patterns

```python
# Secret patterns relevant to OwnPay / PHP projects
SECRET_PATTERNS = {
    "ownpay_app_key":     re.compile(r'APP_KEY\s*=\s*[A-Za-z0-9+/=]{40,}'),
    "ownpay_hmac_key":    re.compile(r'HMAC_KEY\s*=\s*[a-f0-9]{64}'),
    "ownpay_jwt_secret":  re.compile(r'JWT_SECRET\s*=\s*[a-f0-9]{64}'),
    "aws_access_key":     re.compile(r'AKIA[0-9A-Z]{16}'),
    "stripe_secret":      re.compile(r'sk_live_[A-Za-z0-9]{24,}'),
    "stripe_test":        re.compile(r'sk_test_[A-Za-z0-9]{24,}'),
    "private_key":        re.compile(r'-----BEGIN (RSA |EC )?PRIVATE KEY-----'),
    "generic_secret":     re.compile(r'(?i)(password|secret|api_key|apikey|access_key)\s*=\s*["\']?\S{8,}'),
    "db_password":        re.compile(r'(?i)DB_PASSWORD\s*=\s*.{4,}'),
    "php_hardcoded_hash": re.compile(r'\$2[aby]\$\d+\$[A-Za-z0-9./]{53}'),  # bcrypt hash in code
}
```

---

## Security Tools Reference

### PHP-Specific Security Tools (May 2026)

| Category | Tool | Command / Notes |
|----------|-------|-----------------|
| PHP SAST | PHPStan | `./vendor/bin/phpstan analyse --level=8 src/` |
| PHP SAST | Psalm | `./vendor/bin/psalm --taint-analysis` |
| PHP SAST | Semgrep | `semgrep --config=p/php src/` |
| Dependency Audit | Composer Audit | `composer audit` |
| Dependency Audit | Local PHP Security Checker | `local-php-security-checker` |
| Secret Detection | GitLeaks | `gitleaks detect --source . --verbose` |
| Secret Detection | TruffleHog | `trufflehog filesystem .` |
| DAST | OWASP ZAP | Active scan against `https://ownpay.test/` |
| DAST | Burp Suite | Intercept proxy for checkout flow |
| Dangerous Functions | grep | `grep -rn "eval\|exec\|shell_exec\|passthru\|system\|popen\|proc_open" src/ modules/` |
| PHP Object Injection | grep | `grep -rn "unserialize" src/ modules/` |
| SQL Injection | grep | `grep -rn "query\|execute" src/ \| grep -v "prepare"` |
| Container Security | Trivy | `trivy fs .` |
| Infrastructure | Checkov | `checkov -d .` |

### General Security Tools

| Category | Tools |
|----------|-------|
| SAST | Semgrep, CodeQL, PHPStan (taint), Psalm |
| DAST | OWASP ZAP, Burp Suite, Nikto |
| Dependency Scanning | `composer audit`, Snyk, Dependabot |
| Secret Detection | GitLeaks, TruffleHog, detect-secrets |
| Container Security | Trivy, Clair, Anchore |
| Infrastructure | Checkov, tfsec, ScoutSuite |
| Network | Wireshark, Nmap |
| Penetration | Metasploit, sqlmap, Burp Suite Pro |

---

## Cryptographic Algorithm Selection (May 2026)

| Use Case | Required Algorithm | Key Size | Forbidden |
|----------|-----------|----------|-----------|
| Symmetric encryption | AES-256-GCM | 256-bit | AES-ECB, AES-CBC without HMAC, DES, 3DES |
| Password hashing | Argon2id | N/A (use OWASP 2025 params) | MD5, SHA1, SHA256, bcrypt-only |
| Message authentication | HMAC-SHA256 or HMAC-SHA512 | 256/512-bit | MD5-HMAC, SHA1-HMAC |
| Digital signatures | Ed25519 or RSA-PSS | 256-bit / 2048+ bit | RSA PKCS#1 v1.5, DSA |
| Key exchange | X25519 | 256-bit | DH < 2048-bit |
| Key derivation | HKDF-SHA256 | — | PBKDF2 with < 600,000 iterations |
| JWT signing | HS256 (shared) or RS256 (asymmetric) | 256-bit | `alg: none`, HS1 |
| Secure random | `random_bytes()` (PHP) | 32 bytes min | `rand()`, `mt_rand()`, `uniqid()` |
| TLS | TLS 1.3 preferred, 1.2 minimum | — | TLS 1.0, TLS 1.1, SSL 3.0 |
| Token encoding | hex or base64url | — | Raw binary in URLs |

---

## Supply Chain & LLM Security (2025-2026 Emerging Threats)

### Supply Chain Attack Vectors

```
1. Compromised Composer package (typosquatting or legitimate package takeover)
   Mitigation:
   - Always pin exact versions in composer.json
   - Verify composer.lock integrity in CI/CD
   - Run: composer audit on every build
   - Monitor: https://github.com/advisories for PHP package CVEs

2. Malicious gateway plugin submitted by third party
   Mitigation:
   - Code review ALL plugin PHP files before activation
   - Run PluginSandbox scan programmatically before install
   - Verify plugin manifest.json is not overriding system services

3. Compromised CI/CD pipeline (GitHub Actions, build scripts)
   Mitigation:
   - Pin GitHub Actions to commit SHA, not tag
   - Use OIDC for cloud credentials, not long-lived secrets
   - Audit workflow files for exfiltration patterns

4. JavaScript CDN asset integrity
   Mitigation:
   - Use Subresource Integrity (SRI) for all third-party JS/CSS
   - Example: <script src="..." integrity="sha384-..." crossorigin="anonymous">
```

### LLM / AI Security Considerations (2025-2026)

```
1. Prompt Injection via Gateway Responses
   - If OwnPay processes gateway response metadata through any LLM-assisted component,
     ensure gateway response data is never passed as raw prompt input
   - Sanitize all gateway metadata before any AI-assisted processing

2. AI-Generated Code Review Bypasses
   - AI-generated code may contain subtle logical flaws
   - Always run PHPStan level 8 + Psalm taint analysis on AI-generated code
   - Never trust AI-generated cryptographic implementations without expert review

3. Sensitive Data Leakage to External AI APIs
   - Never send op_transactions, op_gateway_configs, or customer PII to external AI APIs
   - If AI features are added, process only anonymized/synthetic data
```

---

## Security Standards Reference

### Security Headers — OwnPay Required Set (2026)

| Header | Required Value | Notes |
|--------|----------------|-------|
| Content-Security-Policy | `default-src 'self'; script-src 'self' 'nonce-{n}'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; upgrade-insecure-requests` | Nonce-based, dynamically built from gateway manifests |
| X-Frame-Options | `DENY` | Prevents clickjacking |
| X-Content-Type-Options | `nosniff` | Prevents MIME sniffing |
| Strict-Transport-Security | `max-age=63072000; includeSubDomains; preload` | 2-year HSTS preload |
| Referrer-Policy | `strict-origin-when-cross-origin` | Limits referrer leakage |
| Permissions-Policy | `geolocation=(), microphone=(), camera=(), payment=()` | Restrict browser APIs |
| Cross-Origin-Opener-Policy | `same-origin` | Isolates browsing context |
| Cross-Origin-Resource-Policy | `same-origin` | Prevents cross-origin reads |
| **X-XSS-Protection** | **REMOVE** | Deprecated — harmful in some browsers |

---

## Tools and References

### Scripts

| Script | Purpose |
|--------|---------|
| [threat_modeler.py](scripts/threat_modeler.py) | STRIDE threat analysis with DREAD risk scoring; JSON and text output; interactive guided mode |
| [secret_scanner.py](scripts/secret_scanner.py) | Detect hardcoded secrets across 20+ patterns including OwnPay-specific keys; CI/CD integration ready |

### References

| Document | Content |
|----------|---------|
| [security-architecture-patterns.md](references/security-architecture-patterns.md) | Zero Trust, defense-in-depth, authentication patterns, API security |
| [threat-modeling-guide.md](references/threat-modeling-guide.md) | STRIDE methodology, attack trees, DREAD scoring, DFD creation |
| [cryptography-implementation.md](references/cryptography-implementation.md) | AES-GCM, Ed25519, Argon2id, key management |

### Compliance Framework Quick Reference

| Framework | Applicability to OwnPay |
|-----------|------------------------|
| OWASP Top 10:2025 (Nov 2025) | Mandatory baseline for all security reviews — A01 Broken Access Control (incl. SSRF), A03 Supply Chain (new), A10 Exceptional Conditions (new) |
| OWASP ASVS 4.0 | Level 2 minimum for payment flows |
| PCI-DSS v4.0 (2024) | Required if storing/processing/transmitting card data |
| GDPR | Required for EU customer PII in op_customers |
| AGPL-3.0 | License compliance for any modifications |
| CWE Top 25 (2024) | Reference for vulnerability classification |
| NIST CSF 2.0 (2024) | Security program framework |

---

## Related Skills

| Skill | Integration Point |
|-------|-------------------|
| [owasp-security-check](../owasp-security-check/) | 20 OWASP rule files for category-specific deep dives |
| [pci-compliance](../pci-compliance/) | PCI-DSS v4.0 compliance requirements |
| [senior-architect](../senior-architect/) | Security architecture decisions |
