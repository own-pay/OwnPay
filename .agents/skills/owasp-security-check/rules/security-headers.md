---
title: Security Headers
impact: HIGH
tags: [headers, csp, hsts, xss, clickjacking, owasp]
---

# Security Headers

Check for proper HTTP security headers that protect against XSS, clickjacking, MIME sniffing, and downgrade attacks.

> **Related:** XSS input validation in [injection-attacks.md](injection-attacks.md). CORS in [cors-configuration.md](cors-configuration.md).

## Why

- **XSS protection**: CSP prevents script injection
- **Clickjacking prevention**: X-Frame-Options stops iframe embedding
- **HTTPS enforcement**: HSTS ensures encrypted connections
- **MIME sniffing attacks**: X-Content-Type-Options prevents content confusion
- **Information leakage**: Referrer-Policy controls referrer data

## What to Check

- [ ] Missing Content-Security-Policy header
- [ ] Missing Strict-Transport-Security (HSTS)
- [ ] Missing X-Frame-Options
- [ ] Missing X-Content-Type-Options
- [ ] Overly permissive CSP (`unsafe-inline`, `unsafe-eval`)
- [ ] No Permissions-Policy
- [ ] Missing Referrer-Policy

## Bad Patterns

```typescript
// Bad: No security headers
async function handler(req: Request): Promise<Response> {
  let html = "<html><body>Hello</body></html>";

  // VULNERABLE: Missing all security headers
  return new Response(html, {
    headers: { "Content-Type": "text/html" },
  });
}

// Bad: Permissive CSP
const headers = {
  // VULNERABLE: unsafe-inline allows XSS
  "Content-Security-Policy": "default-src * 'unsafe-inline' 'unsafe-eval'",
};
```

## Good Patterns

```typescript
// Good: Comprehensive security headers
function getSecurityHeaders(): Record<string, string> {
  return {
    "Content-Security-Policy": [
      "default-src 'self'",
      "script-src 'self'",
      "style-src 'self' 'unsafe-inline'",
      "img-src 'self' data: https:",
      "font-src 'self'",
      "connect-src 'self'",
      "frame-ancestors 'none'",
      "base-uri 'self'",
      "form-action 'self'",
    ].join("; "),
    "X-Frame-Options": "DENY",
    "Strict-Transport-Security": "max-age=31536000; includeSubDomains; preload",
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "strict-origin-when-cross-origin",
    "Permissions-Policy": "camera=(), microphone=(), geolocation=()",
  };
}

async function handler(req: Request): Promise<Response> {
  let html = "<html><body>Hello</body></html>";

  return new Response(html, {
    headers: {
      "Content-Type": "text/html",
      ...getSecurityHeaders(),
    },
  });
}

// Good: CSP with nonces for inline scripts
async function renderPage(req: Request): Promise<Response> {
  let nonce = crypto.randomBytes(16).toString("base64");

  let html = `
    <!DOCTYPE html>
    <html>
      <head>
        <script nonce="${nonce}">
          console.log('This script is allowed');
        </script>
      </head>
      <body>Content</body>
    </html>
  `;

  return new Response(html, {
    headers: {
      "Content-Type": "text/html",
      "Content-Security-Policy": `default-src 'self'; script-src 'self' 'nonce-${nonce}'`,
    },
  });
}
```

## Rules

1. **Always set CSP** - Strict policy without `unsafe-inline`/`unsafe-eval`
2. **Enable HSTS** - Minimum 1 year, include subdomains
3. **Set X-Frame-Options** - Use `DENY` or `SAMEORIGIN`
4. **Set X-Content-Type-Options** - Always `nosniff`
5. **Configure Referrer-Policy** - `strict-origin-when-cross-origin`
6. **Use nonces for inline scripts** - When inline scripts are needed
7. **Set Permissions-Policy** - Restrict unnecessary browser features
