---
title: Security Logging and Monitoring Failures
impact: MEDIUM
tags: [logging, monitoring, incident-response, owasp-a09]
---

# Security Logging and Monitoring Failures

Check for insufficient logging of security events, missing monitoring, and lack of incident response capabilities.

> **Related:** Preventing sensitive data in logs is covered in [sensitive-data-exposure.md](sensitive-data-exposure.md).

## Why

- **Delayed breach detection**: Attacks go unnoticed for months
- **No audit trail**: Can't investigate incidents
- **Compliance violations**: Regulations require logging
- **Unable to respond**: No visibility into attacks

## What to Check

**Vulnerability Indicators:**

- [ ] No logging of authentication attempts
- [ ] Sensitive data in logs (passwords, tokens)
- [ ] No monitoring or alerting on suspicious activity
- [ ] Logs not retained long enough
- [ ] No log integrity protection
- [ ] Missing request IDs for tracing

## Bad Patterns

```typescript
// Bad: No logging of security events
async function login(req: Request): Promise<Response> {
  let { email, password } = await req.json();

  let user = await authenticate(email, password);

  if (!user) {
    // No logging of failed attempt
    return new Response("Invalid credentials", { status: 401 });
  }

  return createSession(user);
}

// Bad: Logging sensitive data
console.log("User data:", {
  email,
  password, // Don't log passwords!
  creditCard,
});

// Bad: No structured logging
console.log("User logged in");
```

## Good Patterns

```typescript
// Good: Log security events with context
async function login(req: Request): Promise<Response> {
  let { email, password } = await req.json();
  let ip = req.headers.get("x-forwarded-for");

  let user = await authenticate(email, password);

  if (!user) {
    logger.warn("Failed login", {
      email,
      ip,
      timestamp: new Date().toISOString(),
    });
    return new Response("Invalid credentials", { status: 401 });
  }

  logger.info("Successful login", { userId: user.id, email, ip });
  return createSession(user);
}

// Good: Structured logging with sanitization
function createLogger() {
  let sensitiveKeys = ["password", "token", "secret", "apiKey"];

  function sanitize(obj: any): any {
    if (typeof obj !== "object" || obj === null) return obj;
    let sanitized: any = {};
    for (const [key, value] of Object.entries(obj)) {
      sanitized[key] = sensitiveKeys.some((sk) =>
        key.toLowerCase().includes(sk),
      )
        ? "[REDACTED]"
        : typeof value === "object"
          ? sanitize(value)
          : value;
    }
    return sanitized;
  }

  return {
    info(message: string, context?: Record<string, unknown>) {
      console.log(
        JSON.stringify({
          level: "info",
          message,
          context: context ? sanitize(context) : undefined,
          timestamp: new Date().toISOString(),
        }),
      );
    },
    warn(message: string, context?: Record<string, unknown>) {
      console.warn(
        JSON.stringify({
          level: "warn",
          message,
          context: context ? sanitize(context) : undefined,
          timestamp: new Date().toISOString(),
        }),
      );
    },
    error(message: string, error: Error, context?: Record<string, unknown>) {
      console.error(
        JSON.stringify({
          level: "error",
          message,
          context: {
            error: error.message,
            stack: error.stack,
            ...sanitize(context || {}),
          },
          timestamp: new Date().toISOString(),
        }),
      );
    },
  };
}

const logger = createLogger();
```

## Rules

1. **Log all authentication events** - Successes and failures
2. **Log authorization failures** - When access is denied
3. **Don't log sensitive data** - Sanitize passwords, tokens, PII
4. **Use structured logging** - JSON format for parsing
5. **Include context** - User ID, IP, timestamp, request ID
6. **Monitor and alert** - Set up alerts for suspicious patterns
7. **Retain logs appropriately** - Balance storage and compliance
8. **Protect log integrity** - Prevent tampering
