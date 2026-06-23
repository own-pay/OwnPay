> **STATUS UPDATE (2026-06-11): IMPLEMENTED.** This specification was implemented in remediation pass 2. See `docs/v2/audit/fixes_applied.md` (FIX-005/007/008) and the updated finding status in `report_claude_fable_5.md`. Retained for historical traceability.

# FIND-006 — TOTP replay guard is session-scoped, not persisted per user

Severity: MEDIUM
Status: SPEC_WRITTEN (auth-flow change; touches static verifier + caller wiring)

## Problem (technical)
Login 2FA verification calls `TwoFactorMiddleware::verifyTotp()` (`src/Controller/Admin/AuthController.php:221`), which stores the last-used time slice in **`$_SESSION['totp_last_used_window']`** (`src/Middleware/TwoFactorMiddleware.php:129–143`). Because the replay marker lives in the session, a captured TOTP code can be replayed in a **different** session within its ~30–90s validity window — the new session starts with `lastUsedWindow = 0`. (The legitimate user consuming the code in their own session does not protect a separate attacker session.)

A correctly-built replay guard already exists but is unused: `Authenticator::verifyCodeWithReplayGuard()` accepts a persisted `lastUsedWindow`.

## Recommended solution
Persist the last-used time slice **per user** in shared storage and enforce it in the verifier. Use the existing `op_cache` table (no schema migration required) keyed by user id.

1. In `AuthController` 2FA verify, before calling the verifier, load the persisted window:
```php
$cache = $this->c->get(\OwnPay\Cache\CacheInterface::class);
$cacheKey = 'totp_last_window_' . $userId;
$lastWindow = (int) ($cache->get($cacheKey) ?? 0);

$slice = $this->authenticator->verifyCodeWithReplayGuard($decryptedSecret, $code, $lastWindow, 1);
if ($slice < 0) {
    // invalid or replayed
}
$cache->set($cacheKey, $slice, 120); // TTL > window tolerance
```
2. Keep the session marker as a secondary guard, but the persisted per-user window is authoritative.

## Files to change
- `src/Controller/Admin/AuthController.php` — 2FA verify path: load/persist per-user window, call `verifyCodeWithReplayGuard()`.
- (No change needed to `Authenticator::verifyCodeWithReplayGuard()` — already implemented.)
- Optionally deprecate the session-only path in `TwoFactorMiddleware::verifyTotp()` once the controller is migrated.

## Why specified rather than applied
The change is on the authentication critical path; a careless edit risks locking out legitimate logins (e.g. wrong TTL vs. window tolerance, or per-user-vs-per-device keying). It deserves dedicated tests rather than an inline patch during the audit.

## Verification
- Test: same code accepted once, then rejected on a second submission **from a fresh session** within the window.
- Test: valid next-window code still accepted after a prior success.
- Test: clock-drift code within `±1` window accepted once.
