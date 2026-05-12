---
title: CSRF Protection
impact: HIGH
tags: [csrf, tokens, cookies, same-site]
---

# CSRF Protection

Check for Cross-Site Request Forgery protection on state-changing operations.

> **Related:** Session cookie configuration is covered in [session-security.md](session-security.md). CORS configuration is covered in [cors-configuration.md](cors-configuration.md).

## Why

- **Unauthorized actions**: Attackers perform actions as victim
- **Account takeover**: Change email/password without consent
- **Financial fraud**: Unauthorized transfers
- **Data manipulation**: Modify user data

## What to Check

**Vulnerability Indicators:**

- [ ] State-changing endpoints accept GET requests
- [ ] No CSRF tokens on forms
- [ ] Cookies without SameSite attribute
- [ ] Missing Origin/Referer validation
- [ ] No double-submit cookie pattern

## Bad Patterns

```typescript
// Bad: No SameSite on cookie
return new Response("OK", {
  headers: { "Set-Cookie": "session=abc123; HttpOnly; Secure" },
});

// Bad: State change via GET
async function deleteAccount(req: Request): Promise<Response> {
  let userId = new URL(req.url).searchParams.get("id");
  await db.users.delete({ where: { id: userId } });
}

// Bad: No CSRF token
const { to, amount } = await req.json();
await transfer(to, amount); // Attacker can trigger!
```

## Good Patterns

```typescript
// Good: SameSite cookie
async function login(req: Request): Promise<Response> {
  return new Response("OK", {
    headers: {
      "Set-Cookie": "session=abc123; HttpOnly; Secure; SameSite=Strict; Path=/",
    },
  });
}

// Good: CSRF token validation
async function generateCSRFToken(sessionId: string): Promise<string> {
  let token = crypto.randomBytes(32).toString("hex");

  await db.csrfToken.create({
    data: {
      token,
      sessionId,
      expiresAt: new Date(Date.now() + 60 * 60 * 1000),
    },
  });

  return token;
}

async function validateCSRFToken(
  sessionId: string,
  token: string,
): Promise<boolean> {
  let stored = await db.csrfToken.findFirst({
    where: { token, sessionId, expiresAt: { gt: new Date() } },
  });

  if (stored) {
    await db.csrfToken.delete({ where: { id: stored.id } });
    return true;
  }
  return false;
}

async function transferMoney(req: Request): Promise<Response> {
  let session = await getSession(req);
  let { to, amount, csrfToken } = await req.json();

  if (!(await validateCSRFToken(session.id, csrfToken))) {
    return new Response("Invalid CSRF token", { status: 403 });
  }

  await transfer(to, amount);
  return new Response("OK");
}

// Good: Double-submit cookie pattern
async function setupCSRF(req: Request): Promise<Response> {
  let token = crypto.randomBytes(32).toString("hex");

  return Response.json(
    { csrfToken: token },
    {
      headers: {
        "Set-Cookie": `csrf=${token}; SameSite=Strict; Secure`,
        "Content-Type": "application/json",
      },
    },
  );
}

async function validateDoubleSubmit(req: Request): Promise<boolean> {
  let cookies = parseCookies(req.headers.get("cookie"));
  let { csrfToken } = await req.json();

  return cookies.csrf === csrfToken;
}
```

## Rules

1. **Use SameSite=Strict or Lax** - On all session cookies
2. **No state changes via GET** - Use POST/PUT/DELETE
3. **Implement CSRF tokens** - For session-based auth
4. **Double-submit cookie** - Alternative to tokens
5. **Validate Origin header** - Additional protection layer
