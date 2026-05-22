---
title: Server-Side Request Forgery (SSRF)
impact: CRITICAL
tags: [ssrf, url-validation, owasp-a10]
---

# Server-Side Request Forgery (SSRF)

Check for unvalidated URLs that allow attackers to make requests to internal services or arbitrary external URLs.

> **Related:** URL validation in redirects is covered in [redirect-validation.md](redirect-validation.md).

## Why

- **Internal network access**: Attackers reach internal services
- **Cloud metadata exposure**: Access to AWS/GCP metadata endpoints
- **Port scanning**: Map internal network
- **Bypass firewall**: Access protected resources

## What to Check

**Vulnerability Indicators:**

- [ ] User-provided URLs passed to fetch/axios without validation
- [ ] No allowlist for allowed domains
- [ ] Missing checks for internal IP ranges
- [ ] Webhook URLs not validated
- [ ] URL redirects followed automatically

## Bad Patterns

```typescript
// Bad: Fetching user-provided URL
async function fetchUrl(req: Request): Promise<Response> {
  let { url } = await req.json();

  // SSRF: Can access internal services!
  let response = await fetch(url);
  let data = await response.text();

  return new Response(data);
}

// Bad: No validation on webhook URL
async function registerWebhook(req: Request): Promise<Response> {
  let { webhookUrl } = await req.json();

  await db.webhook.create({
    data: { url: webhookUrl },
  });

  // Later: fetch(webhookUrl) - could be internal
}
```

## Good Patterns

```typescript
// Good: Validate against allowlist
const ALLOWED_DOMAINS = ["api.example.com", "cdn.example.com"];

async function fetchUrl(req: Request): Promise<Response> {
  let { url } = await req.json();
  let parsedUrl = new URL(url);

  if (parsedUrl.protocol !== "https:") {
    return new Response("Only HTTPS allowed", { status: 400 });
  }

  if (!ALLOWED_DOMAINS.includes(parsedUrl.hostname)) {
    return new Response("Domain not allowed", { status: 400 });
  }

  if (isInternalIP(parsedUrl.hostname)) {
    return new Response("Internal IPs not allowed", { status: 400 });
  }

  let response = await fetch(url, { redirect: "manual" });
  return new Response(await response.text());
}

function isInternalIP(hostname: string): boolean {
  return [
    /^127\./,
    /^10\./,
    /^172\.(1[6-9]|2[0-9]|3[0-1])\./,
    /^192\.168\./,
    /^169\.254\./,
    /^localhost$/i,
  ].some((range) => range.test(hostname));
}
```

## Rules

1. **Validate URLs against allowlist** - Never trust user URLs
2. **Block internal IP ranges** - 127.0.0.1, 10.x, 192.168.x, etc.
3. **Enforce HTTPS** - No HTTP or other protocols
4. **Disable redirects** - Or validate redirect targets
5. **Block cloud metadata** - 169.254.169.254 (AWS/GCP/Azure)
