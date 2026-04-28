---
title: Secrets Management
impact: CRITICAL
tags: [secrets, api-keys, environment-variables, credentials]
---

# Secrets Management

Check for hardcoded secrets, exposed API keys, and improper credential management.

> **Related:** Encryption key management in [cryptographic-failures.md](cryptographic-failures.md). Sensitive data exposure in [sensitive-data-exposure.md](sensitive-data-exposure.md).

## Why

- **Credential exposure**: API keys in code can be stolen
- **Repository leaks**: Committed secrets in Git history
- **Unauthorized access**: Exposed keys grant system access
- **Compliance violations**: Regulations require secret protection

## What to Check

- [ ] Hardcoded API keys, passwords, tokens in code
- [ ] Secrets committed to version control
- [ ] .env files committed to repository
- [ ] API keys in client-side code
- [ ] Secrets in logs or error messages
- [ ] No secret rotation policy

## Bad Patterns

```typescript
// Bad: Hardcoded API key
const STRIPE_SECRET_KEY = "sk_live_51H..."; // VULNERABLE!

// Bad: Hardcoded database password
const db = createConnection({
  host: "localhost",
  user: "admin",
  password: "SuperSecret123!" // VULNERABLE!
});

// Bad: Secret in client-side code
const config = {
  apiKey: "AIzaSyB..." // VULNERABLE: Exposed in browser
};

// Bad: .env file committed to Git
// .env (in repository) - VULNERABLE!
DATABASE_URL=postgresql://user:password@localhost/db
API_SECRET=my-secret-key

// Bad: Logging secrets
console.log("Connecting with API key:", process.env.API_KEY);
```

## Good Patterns

```typescript
// Good: Use environment variables
const STRIPE_SECRET_KEY = process.env.STRIPE_SECRET_KEY;

if (!STRIPE_SECRET_KEY) {
  throw new Error("STRIPE_SECRET_KEY not set");
}

// Good: Validate env vars at startup
function validateEnv() {
  let required = ["DATABASE_URL", "JWT_SECRET", "STRIPE_SECRET_KEY"];
  let missing = required.filter((key) => !process.env[key]);
  if (missing.length > 0) {
    throw new Error(`Missing env vars: ${missing.join(", ")}`);
  }
}

// Good: Add .env to .gitignore (never commit secrets)
// Good: Provide .env.example for documentation (safe to commit)

// Good: Secret rotation
async function rotateApiKey(userId: string) {
  let newKey = crypto.randomBytes(32).toString("hex");
  await db.apiKeys.create({
    data: {
      userId,
      key: newKey,
      expiresAt: new Date(Date.now() + 90 * 24 * 60 * 60 * 1000),
    },
  });
  return newKey;
}

// Good: Use secret management service
async function getSecret(name: string): Promise<string> {
  if (process.env.NODE_ENV === "production") {
    return await secretsManager.getSecretValue(name);
  }
  let value = process.env[name];
  if (!value) throw new Error(`Secret ${name} not found`);
  return value;
}
```

## Rules

1. **Never hardcode secrets** - Use environment variables or secret managers
2. **Add .env to .gitignore** - Never commit secret files
3. **Rotate secrets regularly** - Implement expiration and rotation
4. **Validate env vars at startup** - Fail fast if secrets missing
5. **Don't log secrets** - Sanitize logs to remove sensitive values
6. **No secrets in client code** - Keep API keys server-side only
7. **Use secret management services** - For production (AWS Secrets Manager, Vault, etc.)
8. **Scan Git history** - Use tools to find accidentally committed secrets
