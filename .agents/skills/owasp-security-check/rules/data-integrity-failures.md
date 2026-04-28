---
title: Software and Data Integrity Failures
impact: CRITICAL
tags: [integrity, jwt, serialization, ci-cd, owasp-a08]
---

# Software and Data Integrity Failures

Check for unsigned data, insecure deserialization, and lack of integrity verification in code and data.

> **Related:** JWT signing in [cryptographic-failures.md](cryptographic-failures.md) and [session-security.md](session-security.md). Dependency integrity in [vulnerable-dependencies.md](vulnerable-dependencies.md).

## Why

- **Data tampering**: Attackers modify unsigned data
- **Remote code execution**: Insecure deserialization exploits
- **Supply chain attacks**: Unsigned packages or builds
- **Trust violations**: Cannot verify data authenticity

## What to Check

- [ ] JWT tokens decoded without signature verification
- [ ] Accepting unsigned or unverified data
- [ ] Insecure deserialization of user input
- [ ] No integrity checks on file downloads
- [ ] Missing code signing in CI/CD
- [ ] Auto-update without verification
- [ ] Using eval() or Function() with external data

## Bad Patterns

```typescript
// Bad: No signature verification
async function handleWebhook(req: Request): Promise<Response> {
  const payload = await req.json();
  // Trusting payload without verification!
  await processOrder(payload);
}

// Bad: JWT without verification
async function getUser(req: Request): Promise<Response> {
  let token = req.headers.get("authorization")?.split(" ")[1];
  let payload = JSON.parse(atob(token!.split(".")[1])); // Just decode!
  // Attacker can modify payload
  return Response.json({ userId: payload.sub });
}

// Bad: No integrity check on downloads
async function downloadUpdate(req: Request): Promise<Response> {
  let file = await fetch("https://cdn.example.com/update.zip");
  // No checksum verification
  return new Response(file.body);
}
```

## Good Patterns

```typescript
// Good: Verify webhook signature
async function handleWebhook(req: Request): Promise<Response> {
  let signature = req.headers.get("x-webhook-signature");
  let payload = await req.text();

  let expected = crypto
    .createHmac("sha256", process.env.WEBHOOK_SECRET!)
    .update(payload)
    .digest("hex");

  if (signature !== expected) {
    return new Response("Invalid signature", { status: 401 });
  }

  await processOrder(JSON.parse(payload));
  return new Response("OK");
}

// Good: Verify JWT signature
async function getUser(req: Request): Promise<Response> {
  let token = req.headers.get("authorization")?.split(" ")[1];

  if (!token) {
    return new Response("Unauthorized", { status: 401 });
  }

  let payload = await verifyJWT(token, process.env.JWT_SECRET!);

  let user = await db.users.findUnique({
    where: { id: payload.sub },
  });

  return Response.json(user);
}

// Good: Verify file integrity with checksum
async function downloadUpdate(req: Request): Promise<Response> {
  let file = await fetch("https://cdn.example.com/update.zip");
  let buffer = await file.arrayBuffer();

  let hash = crypto
    .createHash("sha256")
    .update(Buffer.from(buffer))
    .digest("hex");
  let expected = "a1b2c3d4..."; // From trusted source

  if (hash !== expected) {
    return new Response("Integrity check failed", { status: 400 });
  }

  return new Response(buffer);
}

// Good: Signed cookies
function signCookie(value: string, secret: string): string {
  let sig = crypto.createHmac("sha256", secret).update(value).digest("hex");
  return `${value}.${sig}`;
}

function verifyCookie(signedValue: string, secret: string): string | null {
  let [value, signature] = signedValue.split(".");
  let expected = crypto
    .createHmac("sha256", secret)
    .update(value)
    .digest("hex");
  return signature === expected ? value : null;
}
```

## Rules

1. **Always verify JWT signatures** - Never decode without verification
2. **Never trust client data** - Look up prices, roles, permissions server-side
3. **Use JSON.parse, never eval** - Safe deserialization only
4. **Use Subresource Integrity** - For all CDN-loaded scripts/styles
5. **Sign cookies** - Use HMAC for tamper detection
6. **Verify checksums** - For downloaded code and updates
7. **Lock dependency versions** - Use lockfiles to ensure integrity
8. **Sign code in CI/CD** - Verify builds haven't been tampered with
