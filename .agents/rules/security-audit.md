---
trigger: model_decision
description: Apply for security audits, reviews, vulnerabilities, risk/pentest tasks, or when auditing PHP code, payments, authentication, authorization, API endpoints, file uploads, webhooks, or cryptography.
---

# ISO-27001 & PCI-DSS Secure Coding Compliance (Static System Hardening)

> [!IMPORTANT]
> **COMPLIANCE MANDATE:** You are operating as a senior software architect and developer with 20+ years of experience in trillion-dollar fintech platforms. In this offline local environment, you are fully authorized to develop, optimize, refactor, and implement features, as well as conduct thorough secure-coding compliance audits, acting as an internal compliance and system hardening authority.

# Security Audit — OwnPay (OWASP Top 10:2025) Part 1/2

> **Load alongside**: `senior-security` skill + `security-cryptography` rule + `security-audit-part2` rule for complete coverage.

---

## OWASP Top 10:2025 — Change Map (vs 2021)

| 2025 | Category | Change |
|---|---|---|
| **A01** | Broken Access Control | SSRF consolidated here (was A10:2021) · 40 CWEs |
| **A02** | Security Misconfiguration | ↑ Up from #5 |
| **A03** | Software Supply Chain Failures | 🆕 New — replaces Vulnerable Components |
| **A04** | Cryptographic Failures | Retained |
| **A05** | Injection | Retained |
| **A06** | Insecure Design | Retained → see Part 2 |
| **A07** | Authentication Failures | Retained → see Part 2 |
| **A08** | Software or Data Integrity Failures | Retained → see Part 2 |
| **A09** | Logging & Alerting Failures | Retained → see Part 2 |
| **A10** | Mishandling of Exceptional Conditions | 🆕 New → see Part 2 |

---

## Mandatory Pre-Audit Checklist

- [ ] Entry points mapped (routes, APIs, webhooks, uploads, cron)
- [ ] Data flow documented (input → processing → storage → output)
- [ ] Trust boundaries identified (guest / customer / staff / superadmin / gateway)
- [ ] `composer.lock` reviewed — run `composer audit`
- [ ] `.env` scoped — no keys in source, DB, or logs
- [ ] OwnPay context: active brands, custom domains, installed plugins

---

## 1) Attack Surface Analysis

```
Entry Points
  /admin/*          Admin panel (APP_DOMAIN only — DomainMiddleware blocks on custom domains)
  /checkout/*       Public payment flow
  /api/*            REST API
  /api/mobile/v1/*  Mobile JWT API
  /webhook/*        Gateway callbacks (IP-allowlisted + signature-verified)
  /install/*        Installer (must be disabled post-install)
  File uploads      Brand logo, favicon (MIME + magic-byte validated)

Trust Boundaries
  Unauthenticated   Public/guest checkout only
  Customer          Checkout session, no admin access
  Staff             Brand-scoped RBAC (PermissionMiddleware)
  Superadmin        Global — forAllTenants() permitted only here
  Gateway callback  signature-verified + IP-allowlisted
  Mobile device     Device-pinned JWT (op_paired_devices)

Privileged Operations (highest audit priority)
  Brand create/delete · Gateway credential management · Ledger posting
  Plugin activation · Staff invite/role assignment · System settings
```

---

## 2) OWASP Top 10:2025 — A01 through A05

---

### A01:2025 — Broken Access Control *(CRITICAL — 40 CWEs, includes SSRF)*

**Authorization & IDOR**
```
- Are all /admin/* routes protected by PermissionMiddleware?
- Is BrandContext::resolveFromRequest() called before every brand-scoped query?
- Can user A access user B's transactions/customers by changing an ID param? (IDOR)
- Can staff access brands they are not assigned to?
- Is forAllTenants() used ONLY in superadmin context? (never in staff flows)
- Do API endpoints verify merchant_id ownership before returning data?
```

**SSRF (consolidated from A10:2021)**
```
- Can a user supply a URL the server fetches? (webhook URLs, gateway callbacks)
- Are outbound HTTP requests validated against an allowlist?
- Can callback URLs reach internal services? (169.254.x.x, 10.x.x.x, metadata endpoints)
- Are redirect URLs validated? (no open redirects on /checkout?return= or /login?return=)
- Is GATEWAY_CALLBACK_URL env override restricted to dev environments only?
- Is DomainUrlService used exclusively for customer-facing URL construction?
```

**CORS & Custom Domains**
```
- Is CORS configured with specific origin allowlists? (no wildcard + credentials)
- Does DomainMiddleware return 404 for /admin/* on all custom domains?
- Are op_domains records trusted only when dns_verified = 1?
- Can an attacker register a domain overlapping with APP_DOMAIN?
```

---

### A02:2025 — Security Misconfiguration *(HIGH)*

**Runtime**
```
- Is APP_DEBUG=false in production? (stack traces must never reach browser)
- Are default credentials changed? (admin@example.com / admin12345 are insecure defaults)
- Are /storage, /database, /config, /.env blocked from HTTP access?
- Are unused PHP extensions disabled?
```

**Security Headers** (set by SecurityHeadersMiddleware)
```
Required — flag HIGH if missing:
  Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
  Content-Security-Policy: (nonce-based; no unsafe-inline)
    Minimum: default-src 'self'; object-src 'none'; base-uri 'self';
             form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests
  X-Content-Type-Options: nosniff
  X-Frame-Options: DENY
  Referrer-Policy: strict-origin-when-cross-origin
  Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()
  Cross-Origin-Opener-Policy: same-origin
  Cross-Origin-Resource-Policy: same-origin

Deprecated — flag MEDIUM if present:
  X-XSS-Protection  ← remove; harmful in some browsers

CSP Rules:
  - Gateway CSP domains loaded from manifest.json csp field ONLY (never hardcoded)
  - checkout.csp.sources filter hook for runtime additions
```

---

### A03:2025 — Software Supply Chain Failures *(HIGH — NEW)*
> CWEs: 1104, 1329, 1357, 1395, 447, 937

**Composer Dependencies**
```
- Run: composer audit          ← check ALL findings; block on CRITICAL/HIGH
- Run: composer outdated       ← identify outdated packages
- Is composer.lock committed and version-pinned?
- Is there a CVE response SLA? (CRITICAL within 24h, HIGH within 7 days)
```

**SBOM & Integrity**
```
- Is a Software Bill of Materials maintained for all direct + transitive deps?
- Are dependency hashes verified on install?
- Are internal packages protected from public registry namespace squatting?
```

**CI/CD Pipeline**
```
- Are CI/CD actions pinned to commit SHA (not floating tags)?
- Are build secrets stored in secrets manager (not env vars in CI config)?
- Are build outputs signed before deploy?
```

**Plugin Supply Chain (OwnPay-specific)**
```
- Are all gateway/addon plugins from verified, audited sources?
- Is PluginSandbox scan run programmatically before plugin activation?
- Are plugin manifest.json CSP declarations the ONLY CSP additions?
- Are third-party JS/CSS loaded with SRI integrity hashes?
  Example: <script src="..." integrity="sha384-..." crossorigin="anonymous">
```

---

### A04:2025 — Cryptographic Failures *(CRITICAL)*

```
Passwords
  - Argon2id (PASSWORD_ARGON2ID) with OWASP 2025 params:
    memory_cost >= 65536 (64MB), time_cost >= 3, threads >= 4
  - password_verify() used (timing-safe) — never === on hash strings
  - Reset tokens: random_bytes(32) — never rand()/uniqid()
  - Reset tokens expire in 1 hour, invalidated on single use

Keys & Secrets
  - APP_KEY / ENCRYPTION_KEY: base64-encoded 32 bytes (from .env only)
  - HMAC_KEY / JWT_SECRET: hex-encoded 32 bytes (from .env only)
  - PaymentIntentCheckoutController throws RuntimeException if HMAC_KEY missing
  - No hardcoded fallback keys anywhere in source

Encryption at Rest
  - TOTP secrets: AES-256-GCM encrypted before storing in totp_secret_enc
  - Gateway API credentials encrypted in op_gateway_configs
  - AES-256-GCM used (never AES-ECB or AES-CBC without authentication tag)

Transit & Tokens
  - TLS 1.3 preferred, TLS 1.2 minimum (never TLS 1.0/1.1/SSL 3.0)
  - All security tokens generated with random_bytes() — never rand()/mt_rand()
  - Webhook signatures: HMAC-SHA256 — never MD5-HMAC or SHA1-HMAC
  - PII masked via PIIMasker in all log output
```

---

### A05:2025 — Injection *(CRITICAL)*

**SQL Injection**
```
- ALL SQL queries via PDO prepared statements — zero string interpolation
- Dynamic ORDER BY / column names protected with strict allowlists
- No raw query building in BaseRepository or child repositories
```

**Twig SSTI (Server-Side Template Injection)**
```
- Is $twig->createTemplate($userInput) ever called? (CRITICAL — RCE on Twig 3.x)
- Are brand.custom_css and brand.custom_js sanitized before persistence?
- Is |raw used ONLY on pre-sanitized, explicitly trusted content?
```

**Command Injection**
```
- User input never passed to: exec(), shell_exec(), passthru(), system(),
  popen(), proc_open(), or backtick operators
- Variable function bypass detected: ($func = 'exec'; $func('id'))
- call_user_func()/call_user_func_array() audited for dangerous callbacks
```

**Path Traversal & Other**
```
- User input never in include(), require(), or raw file paths
- File paths canonicalized and validated before use
- InputSanitizer::array() used with explicit allowlist:
  Allowed methods: string, html, email, url, phone, slug, attr, trim
- XML parsing configured with external entities disabled (XXE)
```