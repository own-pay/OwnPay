---
title: Security Misconfiguration
impact: HIGH
tags: [configuration, defaults, error-handling, owasp-a05]
---

# Security Misconfiguration

Check for insecure default configurations, unnecessary features enabled, verbose error messages, and missing security patches.

## Why

- **Information disclosure**: Verbose errors reveal system details
- **Unauthorized access**: Default credentials still active
- **Attack surface**: Unnecessary features expose vulnerabilities
- **Known vulnerabilities**: Outdated software with public exploits

## What to Check

**Vulnerability Indicators:**

- [ ] Debug mode enabled in production
- [ ] Default credentials not changed
- [ ] Unnecessary features/endpoints enabled
- [ ] Detailed error messages in production
- [ ] Directory listing enabled
- [ ] Outdated dependencies
- [ ] Missing security patches

## Bad Patterns

```typescript
// Bad: Debug mode in production
const DEBUG = true; // Should be from env
if (DEBUG) {
  console.log("Detailed system info:", process.env);
}

// Bad: Verbose error messages
catch (error) {
  return Response.json({
    error: error.message,
    stack: error.stack,
    query: sqlQuery,
    env: process.env
  }, { status: 500 });
}

// Bad: Default credentials
const ADMIN_PASSWORD = "admin123";

// Bad: Unnecessary admin endpoints exposed
async function debugInfo(req: Request): Promise<Response> {
  return Response.json({
    env: process.env,
    config: appConfig,
    routes: allRoutes
  });
}
```

## Good Patterns

```typescript
// Good: Environment-aware configuration
const isProduction = process.env.NODE_ENV === "production";

const config = {
  debug: !isProduction,
  logLevel: isProduction ? "error" : "debug",
  errorDetails: !isProduction
};

// Good: Generic error messages in production
catch (error) {
  console.error("Error:", error);

  let message = isProduction
    ? "An error occurred"
    : error.message;

  return Response.json({ error: message }, { status: 500 });
}

// Good: Strong credentials from environment
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD;
if (!ADMIN_PASSWORD || ADMIN_PASSWORD.length < 20) {
  throw new Error("ADMIN_PASSWORD must be set and strong");
}

// Good: Disable debug endpoints in production
async function debugInfo(req: Request): Promise<Response> {
  if (process.env.NODE_ENV === "production") {
    return new Response("Not found", { status: 404 });
  }

  return Response.json({ routes: publicRoutes });
}
```

## Rules

1. **Disable debug mode in production** - No verbose logging or errors
2. **Change default credentials** - Require strong passwords
3. **Disable unnecessary features** - Minimize attack surface
4. **Generic error messages** - Don't reveal system details
5. **Keep dependencies updated** - Regularly patch vulnerabilities
6. **Remove development endpoints** - No debug/admin routes in production
7. **Secure default configurations** - Fail securely by default
8. **Regular security audits** - npm audit, dependency checks
