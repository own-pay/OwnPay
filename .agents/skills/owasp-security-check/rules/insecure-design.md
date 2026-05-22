---
title: Insecure Design
impact: HIGH
tags: [design, architecture, threat-modeling, owasp-a04]
---

# Insecure Design

Check for security anti-patterns and flaws in application architecture that can't be fixed by implementation alone.

## Why

- **Fundamental flaws**: Can't be patched, require redesign
- **Business logic bypass**: Attackers exploit workflow flaws
- **Privilege escalation**: Design allows unauthorized access
- **Data corruption**: Race conditions and logic errors

## What to Check

**Vulnerability Indicators:**

- [ ] Security by obscurity instead of proper access control
- [ ] Missing rate limiting on expensive operations
- [ ] No input validation on business logic
- [ ] Race conditions in multi-step workflows
- [ ] Trust boundaries not defined
- [ ] Missing defense in depth
- [ ] No threat modeling performed

## Bad Patterns

```typescript
// Bad: Security by obscurity
if (req.headers.get("x-admin-secret") === "admin123") {
  // Admin operations
}

// Bad: Race condition in balance check
const balance = await getBalance(from);
if (balance >= amount) {
  // Race: balance could change here!
  await updateBalance(from, balance - amount);
}

// Bad: No rate limiting
async function generateReport(req: Request): Promise<Response> {
  const report = await runExpensiveQuery(); // Can DoS
  return new Response(report);
}

// Bad: Trust user role from client
const { isAdmin } = await req.json();
if (isAdmin) {
  await db.users.delete({ where: { id } }); // User can claim admin!
}
```

## Good Patterns

```typescript
// Good: Proper RBAC
async function adminEndpoint(req: Request): Promise<Response> {
  let session = await getSession(req);
  let user = await db.users.findUnique({
    where: { id: session.userId },
    select: { role: true },
  });

  if (user.role !== "ADMIN") {
    return new Response("Forbidden", { status: 403 });
  }

  // Admin operations
}

// Good: Transaction for atomic operations
async function transferMoney(from: string, to: string, amount: number) {
  await db.$transaction(async (tx) => {
    let fromAccount = await tx.account.findUnique({
      where: { id: from },
      select: { balance: true },
    });

    if (!fromAccount || fromAccount.balance < amount) {
      throw new Error("Insufficient funds");
    }

    await tx.account.update({
      where: { id: from },
      data: { balance: { decrement: amount } },
    });

    await tx.account.update({
      where: { id: to },
      data: { balance: { increment: amount } },
    });
  });
}

// Good: Rate limiting on expensive operations
async function generateReport(req: Request): Promise<Response> {
  let session = await getSession(req);

  let { success } = await reportLimit.limit(session.userId);
  if (!success) {
    return new Response("Rate limit exceeded", { status: 429 });
  }

  let report = await runExpensiveQuery();
  return new Response(report);
}

// Good: Server-side role verification
async function deleteUser(req: Request): Promise<Response> {
  let session = await getSession(req);

  let user = await db.users.findUnique({
    where: { id: session.userId },
    select: { role: true },
  });

  if (user.role !== "ADMIN") {
    return new Response("Forbidden", { status: 403 });
  }

  let { targetUserId } = await req.json();
  await db.users.delete({ where: { id: targetUserId } });

  return new Response("Deleted");
}
```

## Rules

1. **Don't rely on security by obscurity** - Use proper authentication
2. **Use transactions for atomic operations** - Prevent race conditions
3. **Rate limit expensive operations** - Prevent resource exhaustion
4. **Verify privileges server-side** - Never trust client data
5. **Implement defense in depth** - Multiple layers of security
6. **Perform threat modeling** - Identify risks in design phase
7. **Define trust boundaries** - Know what to validate
8. **Fail securely** - Default deny, not default allow
