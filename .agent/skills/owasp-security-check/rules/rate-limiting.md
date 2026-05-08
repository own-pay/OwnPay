---
title: Rate Limiting and DoS Prevention
impact: MEDIUM
tags: [rate-limiting, dos, brute-force]
---

# Rate Limiting and DoS Prevention

Check for rate limiting on authentication endpoints, APIs, and resource-intensive operations to prevent abuse and denial of service.

> **Related:** Authentication rate limiting is covered in [authentication-failures.md](authentication-failures.md). API rate limiting is covered in [api-security.md](api-security.md).

## Why

- **Brute force prevention**: Stop password guessing attacks
- **Resource exhaustion**: Prevent server overload
- **Cost control**: Limit API abuse and costs
- **Fair usage**: Ensure availability for all users

## What to Check

**Vulnerability Indicators:**

- [ ] No rate limiting on login/signup endpoints
- [ ] No rate limiting on password reset
- [ ] Unlimited API requests
- [ ] No throttling on expensive operations
- [ ] Missing 429 (Too Many Requests) responses

## Bad Patterns

```typescript
// Bad: No rate limiting on login
async function login(req: Request): Promise<Response> {
  let { email, password } = await req.json();

  // Allows unlimited login attempts
  let user = await authenticate(email, password);

  if (!user) {
    return new Response("Invalid credentials", { status: 401 });
  }

  return createSession(user);
}

// Bad: No API rate limiting
async function apiEndpoint(req: Request): Promise<Response> {
  // Can be called unlimited times
  let data = await expensiveQuery();
  return Response.json(data);
}
```

## Good Patterns

```typescript
// Good: Rate limiting with Redis
const loginRateLimit = new Ratelimit({
  redis,
  limiter: Ratelimit.slidingWindow(5, "15m"), // 5 attempts per 15 min
  analytics: true,
});

async function login(req: Request): Promise<Response> {
  let ip = req.headers.get("x-forwarded-for") || "unknown";

  let { success, limit, remaining, reset } = await loginRateLimit.limit(ip);

  if (!success) {
    return new Response("Too many login attempts", {
      status: 429,
      headers: {
        "Retry-After": String(Math.ceil((reset - Date.now()) / 1000)),
        "X-RateLimit-Limit": String(limit),
        "X-RateLimit-Remaining": String(remaining),
        "X-RateLimit-Reset": String(reset),
      },
    });
  }

  let { email, password } = await req.json();
  let user = await authenticate(email, password);

  if (!user) {
    return new Response("Invalid credentials", { status: 401 });
  }

  return createSession(user);
}

// Good: Per-user API rate limiting
const apiRateLimit = new Ratelimit({
  redis,
  limiter: Ratelimit.slidingWindow(100, "1h"),
});

async function apiEndpoint(req: Request): Promise<Response> {
  let session = await getSession(req);
  if (!session) return new Response("Unauthorized", { status: 401 });

  let { success } = await apiRateLimit.limit(session.userId);
  if (!success) return new Response("Rate limit exceeded", { status: 429 });

  let data = await performOperation();
  return Response.json(data);
}

// Good: Tiered rate limiting
function getRateLimit(tier: string): Ratelimit {
  let limits = {
    free: Ratelimit.slidingWindow(10, "1h"),
    pro: Ratelimit.slidingWindow(100, "1h"),
    enterprise: Ratelimit.slidingWindow(1000, "1h"),
  };
  return new Ratelimit({ redis, limiter: limits[tier] || limits.free });
}
```

## Rules

1. **Rate limit auth endpoints** - Prevent brute force
2. **Per-IP and per-user limits** - Multiple layers
3. **Return 429 status** - Standard rate limit response
4. **Include retry headers** - Retry-After, X-RateLimit-\*
5. **Different limits for tiers** - Free vs paid users
6. **Rate limit expensive operations** - Reports, exports, search
