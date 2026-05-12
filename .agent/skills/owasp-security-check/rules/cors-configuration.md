---
title: CORS Configuration
impact: HIGH
tags: [cors, cross-origin, same-origin-policy, owasp]
---

# CORS Configuration

Check for overly permissive Cross-Origin Resource Sharing (CORS) policies that allow unauthorized cross-origin requests.

> **Related:** CSRF protection in [csrf-protection.md](csrf-protection.md). Security headers in [security-headers.md](security-headers.md).

## Why

- **Unauthorized access**: Malicious sites can access your API
- **Credential theft**: CORS with credentials exposes sensitive data
- **CSRF attacks**: Improper CORS enables cross-site attacks
- **Data leakage**: Private APIs exposed to untrusted origins

## What to Check

- [ ] `Access-Control-Allow-Origin: *` with credentials
- [ ] Reflecting request origin without validation
- [ ] Missing origin validation
- [ ] Overly permissive allowed methods/headers
- [ ] No CORS policy on sensitive endpoints

## Bad Patterns

```typescript
// Bad: Wildcard with credentials
return Response.json(data, {
  headers: {
    "Access-Control-Allow-Origin": "*",
    "Access-Control-Allow-Credentials": "true",
  },
});

// Bad: Reflecting any origin
const origin = req.headers.get("origin");
return Response.json(data, {
  headers: {
    "Access-Control-Allow-Origin": origin || "*",
    "Access-Control-Allow-Credentials": "true",
  },
});

// Bad: Weak regex
return /.*\.yourdomain\.com/.test(origin); // evil-yourdomain.com matches!
```

## Good Patterns

```typescript
// Good: Strict origin allowlist
const ALLOWED_ORIGINS = [
  "https://yourdomain.com",
  "https://app.yourdomain.com",
  "https://admin.yourdomain.com",
];

async function handler(req: Request): Promise<Response> {
  let origin = req.headers.get("origin");
  let corsHeaders: Record<string, string> = {};

  if (origin && ALLOWED_ORIGINS.includes(origin)) {
    corsHeaders["Access-Control-Allow-Origin"] = origin;
    corsHeaders["Access-Control-Allow-Credentials"] = "true";
    corsHeaders["Access-Control-Allow-Methods"] = "GET, POST, PUT, DELETE";
    corsHeaders["Access-Control-Allow-Headers"] = "Content-Type, Authorization";
  }

  return Response.json(data, { headers: corsHeaders });
}

// Good: Environment-based CORS
function getAllowedOrigins(): string[] {
  if (process.env.NODE_ENV === "production") {
    return ["https://yourdomain.com", "https://app.yourdomain.com"];
  }
  return ["http://localhost:3000", "http://localhost:5173"];
}

// Good: Preflight request handling
async function corsHandler(req: Request): Response | null {
  let origin = req.headers.get("origin");
  let allowed = getAllowedOrigins();

  if (!origin || !allowed.includes(origin)) {
    return new Response("Origin not allowed", { status: 403 });
  }

  let corsHeaders = {
    "Access-Control-Allow-Origin": origin,
    "Access-Control-Allow-Credentials": "true",
    "Access-Control-Allow-Methods": "GET, POST, PUT, DELETE, PATCH",
    "Access-Control-Allow-Headers": "Content-Type, Authorization",
    "Access-Control-Max-Age": "86400",
  };

  if (req.method === "OPTIONS") {
    return new Response(null, { status: 204, headers: corsHeaders });
  }

  return null;
}
```

## Rules

1. **Never use `Access-Control-Allow-Origin: *` with credentials** - Pick one or the other
2. **Use strict origin allowlist** - Explicitly list allowed origins
3. **Validate origin before reflecting** - Don't blindly reflect request origin
4. **Separate dev and prod origins** - Don't allow localhost in production
5. **Limit allowed methods** - Only necessary HTTP methods
6. **Limit allowed headers** - Only required headers
7. **Handle preflight requests** - Respond to OPTIONS correctly
