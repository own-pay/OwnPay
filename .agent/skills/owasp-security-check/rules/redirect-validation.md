---
title: Open Redirect Prevention
impact: MEDIUM
tags: [redirects, phishing, open-redirect]
---

# Open Redirect Prevention

Check for unvalidated redirect and forward URLs that could be used for phishing attacks.

> **Related:** SSRF prevention (server-side URL validation) is covered in [ssrf-attacks.md](ssrf-attacks.md).

## Why

- **Phishing attacks**: Legitimate domain redirects to malicious site
- **Credential theft**: Users trust your domain and enter credentials
- **OAuth attacks**: Redirect after auth to steal tokens
- **Trust abuse**: Your domain's reputation exploited

## What to Check

**Vulnerability Indicators:**

- [ ] Redirect URLs from query parameters
- [ ] No validation of redirect target
- [ ] External redirects allowed without warning
- [ ] OAuth return_uri not validated

## Bad Patterns

```typescript
// Bad: Unvalidated redirect
async function callback(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let returnUrl = url.searchParams.get("return");

  // Attacker can set return=https://evil.com
  return Response.redirect(returnUrl!);
}

// Bad: No validation on OAuth callback
async function oauthCallback(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let redirectUri = url.searchParams.get("redirect_uri");

  // Complete OAuth flow...

  return Response.redirect(redirectUri!);
}
```

## Good Patterns

```typescript
// Good: Validate against allowlist
const ALLOWED_REDIRECTS = ["/dashboard", "/profile", "/settings"];

async function callback(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let returnUrl = url.searchParams.get("return") || "/";

  if (!ALLOWED_REDIRECTS.includes(returnUrl)) {
    return Response.redirect("/");
  }

  return Response.redirect(returnUrl);
}

// Good: Validate URL is relative
function isValidRedirect(url: string): boolean {
  return url.startsWith("/") && !url.startsWith("//");
}

async function callback(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let returnUrl = url.searchParams.get("return") || "/";

  if (!isValidRedirect(returnUrl)) {
    return Response.redirect("/");
  }

  return Response.redirect(returnUrl);
}

// Good: Validate OAuth redirect_uri
const ALLOWED_OAUTH_REDIRECTS = [
  "https://app.example.com/callback",
  "https://admin.example.com/callback",
];

async function oauthCallback(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let redirectUri = url.searchParams.get("redirect_uri");

  if (!redirectUri || !ALLOWED_OAUTH_REDIRECTS.includes(redirectUri)) {
    return new Response("Invalid redirect_uri", { status: 400 });
  }

  // Complete OAuth flow...
  return Response.redirect(redirectUri);
}
```

## Rules

1. **Validate redirect URLs** - Use allowlist
2. **Only allow relative URLs** - Starts with / not //
3. **Never trust user input** - For redirect targets
4. **Validate OAuth redirects** - Pre-registered URIs only
5. **Default to safe redirect** - Home page if invalid
