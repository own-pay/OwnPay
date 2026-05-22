---
name: owasp-security-check
description: Security audit guidelines for web applications and REST APIs based on OWASP Top 10 and web security best practices. Use when checking code for vulnerabilities, reviewing auth/authz, auditing APIs, or before production deployment.
---

# OWASP Security Check

Comprehensive security audit patterns for web applications and REST APIs. Contains 20 rules across 5 categories covering OWASP Top 10 and common web vulnerabilities.

## When to Apply

Use this skill when:

- Auditing a codebase for security vulnerabilities
- Reviewing user-provided file or folder for security issues
- Checking authentication/authorization implementations
- Evaluating REST API security
- Assessing data protection measures
- Reviewing configuration and deployment settings
- Before production deployment
- After adding new features that handle sensitive data

## How to Use This Skill

1. **Identify application type** - Web app, REST API, SPA, SSR, or mixed
2. **Scan by priority** - Start with CRITICAL rules, then HIGH, then MEDIUM
3. **Review relevant rule files** - Load specific rules from @rules/ directory
4. **Report findings** - Note severity, file location, and impact
5. **Provide remediation** - Give concrete code examples for fixes

## Audit Workflow

### Step 1: Systematic Review by Priority

Work through categories by priority:

1. **CRITICAL**: Authentication & Authorization, Data Protection, Input/Output Security
2. **HIGH**: Configuration & Headers
3. **MEDIUM**: API & Monitoring

### Step 2: Generate Report

Format findings as:

- **Severity**: CRITICAL | HIGH | MEDIUM | LOW
- **Category**: Rule name
- **File**: Path and line number
- **Issue**: What's wrong
- **Impact**: Security consequence
- **Fix**: Code example of remediation

## Rules Summary

### Authentication & Authorization (CRITICAL)

#### broken-access-control - @rules/broken-access-control.md

Check for missing authorization, IDOR, privilege escalation.

```typescript
// Bad: No authorization check
async function getUser(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let userId = url.searchParams.get("id");
  let user = await db.user.findUnique({ where: { id: userId } });
  return new Response(JSON.stringify(user));
}

// Good: Verify ownership
async function getUser(req: Request): Promise<Response> {
  let session = await getSession(req);
  let url = new URL(req.url);
  let userId = url.searchParams.get("id");

  if (session.userId !== userId && !session.isAdmin) {
    return new Response("Forbidden", { status: 403 });
  }

  let user = await db.user.findUnique({ where: { id: userId } });
  return new Response(JSON.stringify(user));
}
```

#### authentication-failures - @rules/authentication-failures.md

Check for weak authentication, missing MFA, session issues.

```typescript
// Bad: Weak password check
if (password.length >= 6) {
  /* allow */
}

// Good: Strong password requirements
function validatePassword(password: string) {
  if (password.length < 12) return false;
  if (!/[A-Z]/.test(password)) return false;
  if (!/[a-z]/.test(password)) return false;
  if (!/[0-9]/.test(password)) return false;
  if (!/[^A-Za-z0-9]/.test(password)) return false;
  return true;
}
```

### Data Protection (CRITICAL)

#### cryptographic-failures - @rules/cryptographic-failures.md

Check for weak encryption, plaintext storage, bad hashing.

```typescript
// Bad: MD5 for passwords
let hash = crypto.createHash("md5").update(password).digest("hex");

// Good: bcrypt with salt
let hash = await bcrypt(password, 12);
```

#### sensitive-data-exposure - @rules/sensitive-data-exposure.md

Check for PII in logs/responses, error messages leaking info.

```typescript
// Bad: Exposing sensitive data
return new Response(JSON.stringify(user)); // Contains password hash, email, etc.

// Good: Return only needed fields
return new Response(
  JSON.stringify({
    id: user.id,
    username: user.username,
    displayName: user.displayName,
  }),
);
```

#### data-integrity-failures - @rules/data-integrity-failures.md

Check for unsigned data, insecure deserialization.

```typescript
// Bad: Trusting unsigned JWT
let decoded = JSON.parse(atob(token.split(".")[1]));
if (decoded.isAdmin) {
  /* grant access */
}

// Good: Verify signature
let payload = await verifyJWT(token, secret);
```

#### secrets-management - @rules/secrets-management.md

Check for hardcoded secrets, exposed env vars.

```typescript
// Bad: Hardcoded secret
const API_KEY = "sk_live_a1b2c3d4e5f6";

// Good: Environment variables
let API_KEY = process.env.API_KEY;
if (!API_KEY) throw new Error("API_KEY not configured");
```

### Input/Output Security (CRITICAL)

#### injection-attacks - @rules/injection-attacks.md

Check for SQL, XSS, NoSQL, Command, Path Traversal injection.

```typescript
// Bad: SQL injection
let query = `SELECT * FROM users WHERE email = '${email}'`;

// Good: Parameterized query
let user = await db.user.findUnique({ where: { email } });
```

#### ssrf-attacks - @rules/ssrf-attacks.md

Check for unvalidated URLs, internal network access.

```typescript
// Bad: Fetching user-provided URL
let url = await req.json().then((d) => d.url);
let response = await fetch(url);

// Good: Validate against allowlist
const ALLOWED_DOMAINS = ["api.example.com", "cdn.example.com"];
let url = new URL(await req.json().then((d) => d.url));
if (!ALLOWED_DOMAINS.includes(url.hostname)) {
  return new Response("Invalid URL", { status: 400 });
}
```

#### file-upload-security - @rules/file-upload-security.md

Check for unrestricted uploads, MIME validation.

```typescript
// Bad: No file type validation
let file = await req.formData().then((fd) => fd.get("file"));
await writeFile(`./uploads/${file.name}`, file);

// Good: Validate type and extension
const ALLOWED_TYPES = ["image/jpeg", "image/png", "image/webp"];
const ALLOWED_EXTS = [".jpg", ".jpeg", ".png", ".webp"];
let file = await req.formData().then((fd) => fd.get("file") as File);

if (!ALLOWED_TYPES.includes(file.type)) {
  return new Response("Invalid file type", { status: 400 });
}
```

#### redirect-validation - @rules/redirect-validation.md

Check for open redirects, unvalidated redirect URLs.

```typescript
// Bad: Unvalidated redirect
let returnUrl = new URL(req.url).searchParams.get("return");
return Response.redirect(returnUrl);

// Good: Validate redirect URL
let returnUrl = new URL(req.url).searchParams.get("return");
let allowed = ["/dashboard", "/profile", "/settings"];
if (!allowed.includes(returnUrl)) {
  return Response.redirect("/");
}
```

### Configuration & Headers (HIGH)

#### insecure-design - @rules/insecure-design.md

Check for security anti-patterns in architecture.

```typescript
// Bad: Security by obscurity
let isAdmin = req.headers.get("x-admin-secret") === "admin123";

// Good: Proper role-based access control
let session = await getSession(req);
let isAdmin = await db.user
  .findUnique({
    where: { id: session.userId },
  })
  .then((u) => u.role === "ADMIN");
```

#### security-misconfiguration - @rules/security-misconfiguration.md

Check for default configs, debug mode, error handling.

```typescript
// Bad: Exposing stack traces
catch (error) {
  return new Response(error.stack, { status: 500 });
}

// Good: Generic error message
catch (error) {
  console.error(error); // Log server-side only
  return new Response("Internal server error", { status: 500 });
}
```

#### security-headers - @rules/security-headers.md

Check for CSP, HSTS, X-Frame-Options, etc.

```typescript
// Bad: No security headers
return new Response(html);

// Good: Security headers set
return new Response(html, {
  headers: {
    "Content-Security-Policy": "default-src 'self'",
    "X-Frame-Options": "DENY",
    "X-Content-Type-Options": "nosniff",
    "Strict-Transport-Security": "max-age=31536000; includeSubDomains",
  },
});
```

#### cors-configuration - @rules/cors-configuration.md

Check for overly permissive CORS.

```typescript
// Bad: Wildcard with credentials
headers.set("Access-Control-Allow-Origin", "*");
headers.set("Access-Control-Allow-Credentials", "true");

// Good: Specific origin
let allowedOrigins = ["https://app.example.com"];
let origin = req.headers.get("origin");
if (origin && allowedOrigins.includes(origin)) {
  headers.set("Access-Control-Allow-Origin", origin);
}
```

#### csrf-protection - @rules/csrf-protection.md

Check for CSRF tokens, SameSite cookies.

```typescript
// Bad: No CSRF protection
let cookies = parseCookies(req.headers.get("cookie"));
let session = await getSession(cookies.sessionId);

// Good: SameSite cookie + token validation
return new Response("OK", {
  headers: {
    "Set-Cookie": "session=abc; SameSite=Strict; Secure; HttpOnly",
  },
});
```

#### session-security - @rules/session-security.md

Check for cookie flags, JWT issues, token storage.

```typescript
// Bad: Insecure cookie
return new Response("OK", {
  headers: { "Set-Cookie": "session=abc123" },
});

// Good: Secure cookie with all flags
return new Response("OK", {
  headers: {
    "Set-Cookie":
      "session=abc123; Secure; HttpOnly; SameSite=Strict; Path=/; Max-Age=3600",
  },
});
```

### API & Monitoring (MEDIUM-HIGH)

#### api-security - @rules/api-security.md

Check for REST API vulnerabilities, mass assignment.

```typescript
// Bad: Mass assignment vulnerability
let userData = await req.json();
await db.user.update({ where: { id }, data: userData });

// Good: Explicitly allow fields
let { displayName, bio } = await req.json();
await db.user.update({
  where: { id },
  data: { displayName, bio }, // Only allowed fields
});
```

#### rate-limiting - @rules/rate-limiting.md

Check for missing rate limits, brute force prevention.

```typescript
// Bad: No rate limiting
async function login(req: Request): Promise<Response> {
  let { email, password } = await req.json();
  // Allows unlimited login attempts
}

// Good: Rate limiting
let ip = req.headers.get("x-forwarded-for");
let { success } = await ratelimit.limit(ip);
if (!success) {
  return new Response("Too many requests", { status: 429 });
}
```

#### logging-monitoring - @rules/logging-monitoring.md

Check for insufficient logging, sensitive data in logs.

```typescript
// Bad: Logging sensitive data
console.log("User login:", { email, password, ssn });

// Good: Log events without sensitive data
console.log("User login attempt", {
  email,
  ip: req.headers.get("x-forwarded-for"),
  timestamp: new Date().toISOString(),
});
```

#### vulnerable-dependencies - @rules/vulnerable-dependencies.md

Check for outdated packages, known CVEs.

```bash
# Bad: No dependency checking
npm install

# Good: Regular audits
npm audit
npm audit fix
```

## Common Vulnerability Patterns

Quick reference of patterns to look for:

- **User input without validation**: `req.json()` → immediate use
- **Missing auth checks**: Routes without authorization middleware
- **Hardcoded secrets**: Strings containing "password", "secret", "key"
- **SQL injection**: String concatenation in queries
- **XSS**: `dangerouslySetInnerHTML`, `.innerHTML`
- **Weak crypto**: `md5`, `sha1` for passwords
- **Missing headers**: No CSP, HSTS, or security headers
- **CORS wildcards**: `Access-Control-Allow-Origin: *` with credentials
- **Insecure cookies**: Missing Secure, HttpOnly, SameSite flags
- **Path traversal**: User input in file paths without validation

## Severity Quick Reference

**Fix Immediately (CRITICAL):**

- SQL/XSS/Command Injection
- Missing authentication on sensitive endpoints
- Hardcoded secrets in code
- Plaintext password storage
- IDOR vulnerabilities

**Fix Soon (HIGH):**

- Missing CSRF protection
- Weak password requirements
- Missing security headers
- Overly permissive CORS
- Insecure session management

**Fix When Possible (MEDIUM):**

- Missing rate limiting
- Incomplete logging
- Outdated dependencies (no known exploits)
- Missing input validation on non-critical fields

**Improve (LOW):**

- Missing optional security headers
- Verbose error messages (non-production)
- Suboptimal crypto parameters
