---
title: Authentication Failures
impact: CRITICAL
tags: [authentication, passwords, mfa, sessions, owasp-a07]
---

# Authentication Failures

Check for weak authentication mechanisms, missing MFA, session management issues, and credential handling vulnerabilities.

> **Related:** Session security in [session-security.md](session-security.md). Rate limiting in [rate-limiting.md](rate-limiting.md).

## Why

- **Account takeover**: Attackers gain unauthorized access to user accounts
- **Credential stuffing**: Weak auth enables automated attacks
- **Session hijacking**: Improper session management allows theft
- **Brute force attacks**: Weak passwords and no rate limiting enable guessing

## What to Check

- [ ] Weak password requirements (length < 12, no complexity)
- [ ] No multi-factor authentication option
- [ ] Passwords stored in plaintext or with weak hashing (MD5, SHA1)
- [ ] Missing account lockout after failed attempts
- [ ] Session tokens predictable or not securely generated
- [ ] No session expiration or timeout
- [ ] Session not regenerated after login
- [ ] Credentials exposed in URLs or logs

## Bad Patterns

```typescript
// Bad: Weak password hashing (SHA-256 too fast)
const hash = crypto.createHash("sha256").update(password).digest("hex");

// Bad: No password requirements
async function signup(req: Request): Promise<Response> {
  let { email, password } = await req.json();
  // Accepts "123" as valid password!
  await db.users.create({
    data: { email, password: await bcrypt(password, 10) },
  });
}

// Bad: Timing attack reveals if email exists
const user = await db.users.findUnique({ where: { email } });
if (!user) return new Response("Invalid", { status: 401 }); // Early return!
if (!(await bcrypt.compare(password, user.password))) {
  return new Response("Invalid", { status: 401 });
}

// Bad: No rate limiting or account lockout
async function login(req: Request): Promise<Response> {
  // Unlimited attempts allowed!
  let user = await authenticate(email, password);
}
```

## Good Patterns

```typescript
// Good: bcrypt with proper cost factor
const hash = await bcrypt(password, 12); // Cost factor 12+

// Good: Strong password validation
function validatePassword(password: string): string | null {
  if (password.length < 12) return "Password must be ≥12 characters";
  if (!/[A-Z]/.test(password)) return "Must include uppercase";
  if (!/[a-z]/.test(password)) return "Must include lowercase";
  if (!/[0-9]/.test(password)) return "Must include number";
  return null;
}

async function signup(req: Request): Promise<Response> {
  let { email, password } = await req.json();

  let error = validatePassword(password);
  if (error) return new Response(error, { status: 400 });

  await db.users.create({
    data: { email, password: await bcrypt(password, 12) },
  });
}

// Good: Constant-time comparison
async function login(req: Request): Promise<Response> {
  let { email, password } = await req.json();
  let user = await db.users.findUnique({ where: { email } });

  // Always compare (constant time)
  let hash = user?.password || "$2b$12$fakehash...";
  let valid = await bcrypt.compare(password, hash);

  if (!user || !valid) {
    return new Response("Invalid credentials", { status: 401 });
  }

  return createSession(user);
}

// Good: Account lockout after failed attempts
async function loginWithLockout(req: Request): Promise<Response> {
  let { email, password } = await req.json();
  let user = await db.users.findUnique({ where: { email } });

  if (user?.lockedUntil && user.lockedUntil > new Date()) {
    return new Response("Account locked", { status: 423 });
  }

  let valid = user && (await bcrypt.compare(password, user.password));

  if (!user || !valid) {
    let attempts = (user?.failedAttempts || 0) + 1;
    await db.users.update({
      where: { email },
      data: {
        failedAttempts: attempts,
        lockedUntil:
          attempts >= 5 ? new Date(Date.now() + 30 * 60 * 1000) : null,
      },
    });
    return new Response("Invalid credentials", { status: 401 });
  }

  // Reset on success
  await db.users.update({
    where: { id: user.id },
    data: { failedAttempts: 0, lockedUntil: null },
  });

  return createSession(user);
}
```

## Rules

1. **Require strong passwords** - Minimum 12 characters with complexity
2. **Hash passwords properly** - Use bcrypt, argon2, or scrypt (never MD5/SHA1)
3. **Implement rate limiting** - Limit authentication attempts per IP/account
4. **Use secure session tokens** - Cryptographically random tokens
5. **Set session expiration** - Both absolute and idle timeout
6. **Regenerate session on login** - Prevent session fixation attacks
7. **Implement account lockout** - Temporarily lock after multiple failures
8. **Support MFA** - Especially for privileged accounts
9. **Never log credentials** - Don't log passwords, tokens, or reset links
