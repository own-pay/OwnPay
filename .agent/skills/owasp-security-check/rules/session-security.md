---
title: Session Security
impact: HIGH
tags: [sessions, cookies, jwt, tokens]
---

# Session Security

Check for secure session management including cookie flags, token storage, and session lifecycle.

> **Related:** Authentication is covered in [authentication-failures.md](authentication-failures.md). CSRF protection is covered in [csrf-protection.md](csrf-protection.md).

## Why

- **Session hijacking**: Attackers steal session tokens
- **Session fixation**: Attackers set known session ID
- **XSS token theft**: JavaScript access to tokens
- **CSRF attacks**: Missing cookie protection

## What to Check

**Vulnerability Indicators:**

- [ ] Cookies missing HttpOnly flag
- [ ] Cookies missing Secure flag
- [ ] Cookies missing SameSite attribute
- [ ] JWT stored in localStorage
- [ ] Sessions never expire
- [ ] Session not regenerated after login
- [ ] Predictable session IDs

## Bad Patterns

```typescript
// Bad: No security flags on cookie
return new Response("OK", {
  headers: { "Set-Cookie": `session=${sessionId}` },
});

// Bad: Session never expires
await db.session.create({
  data: { id: sessionId, userId }, // No expiresAt!
});

// Bad: Predictable session ID
const sessionId = `${Date.now()}-${Math.random()}`;
```

## Good Patterns

```typescript
// Good: Secure cookie with all flags
async function createSession(userId: string): Promise<Response> {
  let sessionId = crypto.randomBytes(32).toString("hex");

  await db.session.create({
    data: {
      id: sessionId,
      userId,
      expiresAt: new Date(Date.now() + 60 * 60 * 1000), // 1 hour
      createdAt: new Date(),
    },
  });

  return new Response("OK", {
    headers: {
      "Set-Cookie": [
        `session=${sessionId}`,
        "HttpOnly",
        "Secure",
        "SameSite=Strict",
        "Path=/",
        "Max-Age=3600",
      ].join("; "),
    },
  });
}

// Good: Session validation with expiry
async function validateSession(req: Request): Promise<string | null> {
  let sessionId = getCookie(req, "session");
  if (!sessionId) return null;

  let session = await db.session.findUnique({ where: { id: sessionId } });
  if (!session || session.expiresAt < new Date()) {
    if (session) await db.session.delete({ where: { id: sessionId } });
    return null;
  }

  // Extend session (sliding expiration)
  await db.session.update({
    where: { id: sessionId },
    data: { expiresAt: new Date(Date.now() + 60 * 60 * 1000) },
  });

  return session.userId;
}

// Good: Logout invalidates session
async function logout(req: Request): Promise<Response> {
  let sessionId = getCookie(req, "session");
  if (sessionId) await db.session.delete({ where: { id: sessionId } });

  return new Response("OK", {
    headers: { "Set-Cookie": "session=; Max-Age=0; Path=/" },
  });
}
```

## Rules

1. **Set HttpOnly flag** - Prevent XSS token theft
2. **Set Secure flag** - HTTPS only
3. **Set SameSite=Strict** - CSRF protection
4. **Use cryptographically random IDs** - crypto.randomBytes
5. **Set expiration** - Both absolute and idle timeout
6. **Regenerate on login** - Prevent session fixation
7. **Don't store in localStorage** - Use HttpOnly cookies
8. **Validate on every request** - Check expiry and validity
