---
title: Injection Attack Prevention
impact: CRITICAL
tags: [injection, sql, xss, nosql, command-injection, path-traversal, owasp-a03]
---

# Injection Attack Prevention

Check for SQL injection, XSS, NoSQL injection, Command injection, and Path Traversal through proper input validation and output encoding.

> **Related:** XSS headers in [security-headers.md](security-headers.md). File upload path traversal in [file-upload-security.md](file-upload-security.md).

## Why

- **Data breach**: SQL/NoSQL injection exposes entire databases
- **Account takeover**: XSS steals session cookies and credentials
- **Remote code execution**: Command injection compromises servers
- **Data manipulation**: Unauthorized modification or deletion

## What to Check

- [ ] String concatenation or template literals in database queries
- [ ] User input rendered in HTML without escaping
- [ ] User input passed to shell commands (`exec`, `spawn` with `shell: true`)
- [ ] User input used in file paths without validation
- [ ] Dynamic code execution (`eval`, `Function` constructor, `setTimeout` with strings)
- [ ] `dangerouslySetInnerHTML` or `.innerHTML` with user content
- [ ] NoSQL queries accepting raw objects with `$where`, `$regex`, `$ne` operators

## Bad Patterns

```typescript
// Bad: SQL injection
const query = `SELECT * FROM users WHERE email = '${email}'`;

// Bad: XSS via dangerouslySetInnerHTML
<div dangerouslySetInnerHTML={{ __html: comment }} />

// Bad: Command injection
execSync(`convert ${filename} output.jpg`);

// Bad: Path traversal
const content = await fs.readFile(`./uploads/${filename}`, "utf-8");
```

## Good Patterns

```typescript
// Good: Parameterized query
async function getUser(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let email = url.searchParams.get("email");

  let user = await db.users.findUnique({ where: { email } });
  return Response.json(user);
}

// Good: React auto-escapes by default
function UserComment({ comment }: { comment: string }) {
  return <div>{comment}</div>;
}

// Good: Avoid shell commands, validate strictly
async function convertImage(req: Request): Promise<Response> {
  let formData = await req.formData();
  let file = formData.get("file") as File;

  let ALLOWED = ["image/jpeg", "image/png", "image/webp"];

  if (!ALLOWED.includes(file.type)) {
    return new Response("Invalid type", { status: 400 });
  }

  let buffer = await file.arrayBuffer();
  // Use image library, not shell
  return new Response("Uploaded", { status: 200 });
}

// Good: Allowlist for file paths
async function readFile(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let filename = url.searchParams.get("file");

  let ALLOWED = ["terms.pdf", "privacy.pdf", "guide.pdf"];

  if (!filename || !ALLOWED.includes(filename) || filename.includes("..")) {
    return new Response("Invalid file", { status: 400 });
  }

  let content = await fs.readFile(`./documents/${filename}`, "utf-8");
  return new Response(content);
}
```

## Rules

1. **Always use parameterized queries** - Never concatenate user input into SQL
2. **Validate all input** - Use type checks and format validation
3. **Escape output by context** - HTML, JavaScript, SQL require different escaping
4. **Use allowlists over denylists** - Explicitly allow known-good values
5. **Never use eval()** - Find safe alternatives for dynamic execution
6. **Avoid shell commands** - Use libraries or built-in APIs instead
7. **Validate file paths** - Prevent directory traversal with strict validation
