---
title: Sensitive Data Exposure
impact: CRITICAL
tags: [data-exposure, pii, privacy, information-disclosure, owasp]
---

# Sensitive Data Exposure

Check for PII, credentials, and sensitive data exposed in API responses, error messages, logs, or client-side code.

> **Related:** Encryption in [cryptographic-failures.md](cryptographic-failures.md). Secrets in [secrets-management.md](secrets-management.md). Logging in [logging-monitoring.md](logging-monitoring.md).

## Why

- **Privacy violation**: Exposes users' personal information
- **Compliance risk**: GDPR, CCPA, HIPAA violations
- **Identity theft**: PII enables fraud and impersonation
- **Credential theft**: Exposed secrets enable account takeover

## What to Check

- [ ] Password hashes returned in API responses
- [ ] Email, phone, SSN in public endpoints
- [ ] Error messages revealing stack traces or database info
- [ ] Debug information in production
- [ ] API keys, tokens in client-side code
- [ ] Excessive data in responses (return only what's needed)
- [ ] Sensitive data logged to console or files

## Bad Patterns

```typescript
// Bad: Returning all user fields including sensitive data
async function getUser(req: Request): Promise<Response> {
  let user = await db.users.findUnique({ where: { id } });
  // Returns password hash, email, tokens, etc.
  return Response.json(user);
}

// Bad: Logging sensitive data
console.log("User login:", { email, password, creditCard });

// Bad: Exposing internal IDs
return Response.json({
  internalUserId: user.id,
  databaseId: user.dbId,
});
```

## Good Patterns

```typescript
// Good: Explicit field selection
async function getUser(req: Request): Promise<Response> {
  let session = await getSession(req);

  let user = await db.users.findUnique({
    where: { id: session.userId },
    select: {
      id: true,
      name: true,
      avatar: true,
      createdAt: true,
      // Excludes: password, email, tokens, etc.
    },
  });

  return Response.json(user);
}

// Good: DTO for public profiles
async function getUserProfile(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let userId = url.searchParams.get("id");

  let user = await db.users.findUnique({
    where: { id: userId },
    select: { id: true, name: true, avatar: true, bio: true },
  });

  return Response.json(user);
}

// Good: Conditional field exposure
async function getUserProfile(req: Request): Promise<Response> {
  let session = await getSession(req);
  let url = new URL(req.url);
  let userId = url.searchParams.get("id");
  let isOwn = session?.userId === userId;

  let user = await db.users.findUnique({
    where: { id: userId },
    select: {
      id: true,
      name: true,
      avatar: true,
      bio: true,
      email: isOwn,
      emailVerified: isOwn,
    },
  });

  return Response.json(user);
}

// Good: Sanitize logs
function sanitizeForLogging(obj: any): any {
  let sensitive = ["password", "token", "secret", "apiKey", "creditCard"];
  let sanitized = { ...obj };

  for (const key of Object.keys(sanitized)) {
    if (sensitive.some((s) => key.toLowerCase().includes(s))) {
      sanitized[key] = "[REDACTED]";
    }
  }

  return sanitized;
}

console.log("Login attempt:", sanitizeForLogging({ email, password }));
// Output: { email: "user@example.com", password: "[REDACTED]" }
```

## Rules

1. **Never return password hashes** - Even hashed, they can be cracked
2. **Use explicit field selection** - Don't return entire database records
3. **Create DTOs for responses** - Define exactly what fields are public
4. **Generic error messages** - Don't expose system details to users
5. **Log full errors server-side** - Return generic messages to clients
6. **Sanitize logs** - Redact passwords, tokens, PII before logging
7. **Different views for different users** - Own profile vs others' profiles
8. **Disable debug in production** - No verbose errors or stack traces
