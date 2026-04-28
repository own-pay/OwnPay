---
title: Broken Access Control
impact: CRITICAL
tags: [access-control, authorization, idor, owasp-a01]
---

# Broken Access Control

Check for missing authorization checks, insecure direct object references (IDOR), privilege escalation, and path traversal.

> **Related:** Path traversal in [injection-attacks.md](injection-attacks.md) and [file-upload-security.md](file-upload-security.md).

## Why

- **Data breach**: Users access others' sensitive data
- **Privilege escalation**: Regular users gain admin access
- **Data manipulation**: Unauthorized modification or deletion
- **Compliance violation**: GDPR, HIPAA, PCI-DSS penalties

## What to Check

- [ ] Routes accessing resources without verifying ownership
- [ ] User IDs taken from request params without validation
- [ ] Admin endpoints without role checks
- [ ] File paths constructed from user input
- [ ] Authorization checks that can be bypassed
- [ ] Horizontal privilege escalation (user A→user B's data)
- [ ] Vertical privilege escalation (user→admin functions)

## Bad Patterns

```typescript
// Bad: No authorization check
const userId = url.searchParams.get("id");
const user = await db.users.findUnique({ where: { id: userId } });
return Response.json(user); // Anyone can access!

// Bad: No role check
await db.users.delete({ where: { id: userId } }); // No admin verification!

// Bad: Path traversal
const filename = url.searchParams.get("file");
const content = await fs.readFile(`./uploads/${filename}`, "utf-8");
```

## Good Patterns

```typescript
// Good: Verify ownership before access
async function getUserProfile(req: Request): Promise<Response> {
  let session = await getSession(req);
  let url = new URL(req.url);
  let userId = url.searchParams.get("id");

  if (session.userId !== userId && !session.isAdmin) {
    return new Response("Forbidden", { status: 403 });
  }

  let user = await db.users.findUnique({ where: { id: userId } });
  return Response.json(user);
}

// Good: Role-based access control
async function deleteUser(req: Request): Promise<Response> {
  let session = await getSession(req);

  let user = await db.users.findUnique({
    where: { id: session.userId },
    select: { role: true },
  });

  if (user.role !== "ADMIN") {
    return new Response("Forbidden", { status: 403 });
  }

  let url = new URL(req.url);
  let userId = url.searchParams.get("id");

  await db.users.delete({ where: { id: userId } });
  return new Response("Deleted");
}

// Good: Prevent path traversal
async function downloadFile(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let filename = url.searchParams.get("file");
  let ALLOWED = ["terms.pdf", "privacy.pdf", "guide.pdf"];

  if (
    !filename ||
    !ALLOWED.includes(filename) ||
    filename.includes("..") ||
    filename.includes("/")
  ) {
    return new Response("Invalid file", { status: 400 });
  }

  let content = await fs.readFile(`./documents/${filename}`, "utf-8");
  return new Response(content);
}
```

## Rules

1. **Never trust user input for authorization** - Verify against server-side session
2. **Check ownership on every resource access** - Don't assume URL ID is valid
3. **Implement deny-by-default** - Require explicit permission grants
4. **Use role-based access control** - Define clear roles and check them
5. **Validate file paths** - Never construct paths directly from user input
6. **Log authorization failures** - Track denied access for monitoring
7. **Test with different roles** - Verify unprivileged users can't access privileged resources
