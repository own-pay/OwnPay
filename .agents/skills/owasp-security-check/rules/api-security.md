---
title: REST API Security
impact: MEDIUM
tags: [api, rest, mass-assignment, versioning]
---

# REST API Security

Check for REST API vulnerabilities including mass assignment, lack of validation, and missing resource limits.

> **Related:** Input validation in [injection-attacks.md](injection-attacks.md). Authentication in [authentication-failures.md](authentication-failures.md). Rate limiting in [rate-limiting.md](rate-limiting.md).

## Why

- **Mass assignment**: Users modify protected fields
- **Over-fetching**: Expose unnecessary data
- **Resource exhaustion**: Unlimited result sets
- **API abuse**: Missing versioning and documentation

## What to Check

- [ ] Mass assignment in update operations
- [ ] No pagination on list endpoints
- [ ] Missing Content-Type validation
- [ ] No API versioning
- [ ] Excessive data in responses
- [ ] Missing rate limits

## Bad Patterns

```typescript
// Bad: Mass assignment
async function updateUser(req: Request): Promise<Response> {
  let session = await getSession(req);
  let data = await req.json();

  // VULNERABLE: User can set isAdmin, role, etc.!
  await db.users.update({
    where: { id: session.userId },
    data, // Dangerous - accepts all fields!
  });

  return new Response("Updated");
}

// Bad: No pagination
async function getUsers(req: Request): Promise<Response> {
  // VULNERABLE: Could return millions of records
  let users = await db.users.findMany();

  return Response.json(users);
}

// Bad: No input validation
async function createPost(req: Request): Promise<Response> {
  let data = await req.json();

  // VULNERABLE: No validation of data types or values
  await db.posts.create({ data });

  return new Response("Created", { status: 201 });
}
```

## Good Patterns

```typescript
// Good: Explicit field allowlist
async function updateUser(req: Request): Promise<Response> {
  let session = await getSession(req);
  let body = await req.json();

  let allowedFields = {
    displayName: body.displayName,
    bio: body.bio,
    avatar: body.avatar,
  };

  if (
    allowedFields.displayName &&
    typeof allowedFields.displayName !== "string"
  ) {
    return new Response("Invalid displayName", { status: 400 });
  }

  await db.users.update({
    where: { id: session.userId },
    data: allowedFields,
  });

  return new Response("Updated");
}

// Good: Pagination with limits
async function getUsers(req: Request): Promise<Response> {
  let url = new URL(req.url);
  let page = parseInt(url.searchParams.get("page") || "1");
  let limit = Math.min(parseInt(url.searchParams.get("limit") || "20"), 100);

  let users = await db.users.findMany({
    take: limit,
    skip: (page - 1) * limit,
  });

  return Response.json({ data: users, page, limit });
}

// Good: Input validation
async function createPost(req: Request): Promise<Response> {
  let session = await getSession(req);
  let body = await req.json();

  if (
    !body.title ||
    typeof body.title !== "string" ||
    body.title.length > 200
  ) {
    return new Response("Invalid title", { status: 400 });
  }

  if (
    !body.content ||
    typeof body.content !== "string" ||
    body.content.length > 50000
  ) {
    return new Response("Invalid content", { status: 400 });
  }

  await db.posts.create({
    data: {
      title: body.title,
      content: body.content,
      authorId: session.userId,
    },
  });

  return new Response("Created", { status: 201 });
}
```

## Rules

1. **Prevent mass assignment** - Explicitly define allowed fields
2. **Always paginate lists** - Enforce maximum page size
3. **Validate input types** - Check types and constraints
4. **Version your API** - Use `/api/v1/` prefix for versioning
5. **Limit response data** - Return only necessary fields
6. **Validate Content-Type** - Ensure correct headers
