---
title: Cryptographic Failures
impact: CRITICAL
tags: [cryptography, encryption, hashing, tls, owasp-a02]
---

# Cryptographic Failures

Check for weak encryption, improper key management, plaintext storage of sensitive data, and missing encryption in transit.

> **Related:** Password hashing in [authentication-failures.md](authentication-failures.md). Secrets in [secrets-management.md](secrets-management.md). Data signing in [data-integrity-failures.md](data-integrity-failures.md).

## Why

- **Data breach**: Sensitive data exposed if stolen
- **Compliance violation**: GDPR, PCI-DSS require encryption
- **Man-in-the-middle**: Unencrypted connections intercepted
- **Password compromise**: Weak hashing enables rainbow table attacks

## What to Check

- [ ] Sensitive data stored in plaintext (passwords, tokens, PII)
- [ ] Weak hashing algorithms (MD5, SHA1) for passwords
- [ ] Weak encryption algorithms (DES, RC4, ECB mode)
- [ ] Hardcoded encryption keys or predictable keys
- [ ] Missing HTTPS/TLS for data transmission
- [ ] Insufficient key length (< 2048 bits for RSA, < 256 bits symmetric)
- [ ] No encryption for sensitive data at rest

## Bad Patterns

```typescript
// Bad: MD5 for password hashing
async function hashPassword(password: string): Promise<string> {
  // VULNERABLE: MD5 is too fast, easily cracked
  return crypto.createHash("md5").update(password).digest("hex");
}

// Bad: Storing passwords in plaintext
await db.users.create({
  data: {
    email,
    password, // VULNERABLE: Plaintext!
  },
});

// Bad: Weak encryption algorithm
const cipher = crypto.createCipher("des", "weak-key"); // VULNERABLE: DES is weak

// Bad: Hardcoded encryption key
const ENCRYPTION_KEY = "my-secret-key-12345"; // VULNERABLE: Hardcoded

function encryptData(data: string): string {
  const cipher = crypto.createCipheriv("aes-256-cbc", ENCRYPTION_KEY, iv);
  return cipher.update(data, "utf8", "hex");
}

// Bad: No encryption for sensitive data
await db.creditCards.create({
  data: {
    number: "4111111111111111", // VULNERABLE: Plaintext
    cvv: "123",
    expiresAt: "12/25",
  },
});
```

## Good Patterns

```typescript
// Good: bcrypt for password hashing
async function hashPassword(password: string): Promise<string> {
  return await bcrypt(password, 12);
}

// Good: AES-256-GCM encryption
function encryptData(plaintext: string): { encrypted: string; iv: string } {
  let key = Buffer.from(process.env.ENCRYPTION_KEY!, "hex");
  let iv = crypto.randomBytes(16);

  let cipher = crypto.createCipheriv("aes-256-gcm", key, iv);
  let encrypted = cipher.update(plaintext, "utf8", "hex");
  encrypted += cipher.final("hex");
  encrypted += cipher.getAuthTag().toString("hex");

  return { encrypted, iv: iv.toString("hex") };
}

function decryptData(encrypted: string, ivHex: string): string {
  let key = Buffer.from(process.env.ENCRYPTION_KEY!, "hex");
  let iv = Buffer.from(ivHex, "hex");
  let authTag = Buffer.from(encrypted.slice(-32), "hex");
  let ciphertext = encrypted.slice(0, -32);

  let decipher = crypto.createDecipheriv("aes-256-gcm", key, iv);
  decipher.setAuthTag(authTag);

  return decipher.update(ciphertext, "hex", "utf8") + decipher.final("utf8");
}

// Good: Encrypt sensitive fields
async function saveCreditCard(req: Request): Promise<Response> {
  let { number, cvv } = await req.json();

  let { encrypted: encryptedNumber, iv: numberIv } = encryptData(number);
  let { encrypted: encryptedCvv, iv: cvvIv } = encryptData(cvv);

  await db.creditCards.create({
    data: { encryptedNumber, numberIv, encryptedCvv, cvvIv },
  });

  return new Response("Saved", { status: 201 });
}
```

## Rules

1. **Use strong password hashing** - bcrypt, argon2, or scrypt (never MD5/SHA1)
2. **Use modern encryption** - AES-256-GCM or ChaCha20-Poly1305
3. **Never hardcode keys** - Use environment variables or key management systems
4. **Encrypt sensitive data at rest** - PII, credentials, financial data
5. **Enforce HTTPS/TLS** - All data in transit must be encrypted
6. **Use sufficient key lengths** - RSA ≥ 2048 bits, symmetric ≥ 256 bits
7. **Generate random IVs** - New random IV for each encryption operation
8. **Rotate keys regularly** - Implement key rotation policies
