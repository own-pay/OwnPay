# mobile_design.md — Companion App UI/UX Specification (Deliverable 4)

> Design spec for the single OwnPay companion app (dual purpose: User Dashboard + background SMS transaction-matching automator). Visual language matches the core OwnPay Indigo identity; flows reflect the verified backend (`/api/mobile/v1/*`, the OTP pairing handshake, the `filter-rules` privacy gate). Implementation targets Android (the SMS-permission platform).

---

## 1. Visual Language

### 1.1 Color system (HSL, aligned to the web admin)
Single-hue-derived tokens that match `--op-primary #6C5CE7 ≈ hsl(248 79% 63%)` so the app and web console feel like one product. Dark-first (the device is usually wall-mounted/charging).

```
Brand        --m-primary        hsl(248 79% 63%)   /* indigo */
             --m-primary-press  hsl(248 80% 56%)
             --m-accent         hsl(268 84% 70%)   /* violet — gradient partner */
             --m-grad           linear-gradient(135°, hsl(248 79% 63%), hsl(268 84% 70%))
Surfaces     --m-bg             hsl(240 38% 9%)
             --m-surface        hsl(240 30% 13%)
             --m-surface-2      hsl(240 28% 17%)
             --m-border         hsl(240 22% 24%)
Text         --m-text           hsl(214 32% 92%)
             --m-text-muted     hsl(215 20% 66%)
Semantic     --m-ok   hsl(160 84% 42%)   --m-warn hsl(38 92% 52%)
             --m-err  hsl(0 84% 62%)     --m-info hsl(212 92% 64%)
Health       --m-batt-good hsl(160 84% 42%)  --m-batt-mid hsl(38 92% 52%)  --m-batt-low hsl(0 84% 62%)
             --m-temp-cool hsl(212 92% 64%)  --m-temp-warm hsl(38 92% 52%)  --m-temp-hot  hsl(0 84% 62%)
```
Light theme mirrors the web `[data-theme="light"]` tokens. All money/numeric values use `tabular-nums`.

### 1.2 Typography
- **Outfit** — display/numerals: balances, KPIs, the live "matched today" counter, OTP digits. Weights 500/600/700.
- **Inter** — body, labels, list rows, settings. Weights 400/500/600.
- Scale (sp): 12 caption · 14 body · 16 subtitle · 20 title · 28 headline · 40 display.
- Material-3 component baseline (cards, FAB, bottom-nav, chips) restyled with the tokens above.

### 1.3 Iconography & motion
- Line icons (2px, rounded) matching the web sidebar set; status pills use `*-soft` fills + solid text.
- Motion 120–200ms `cubic-bezier(.2,.8,.2,1)`: connection-pulse on the status orb, count-up on the matched counter, queue-badge bounce on increment, biometric-unlock reveal. Honor system "reduce motion".

### 1.4 The battery/temperature health grid (critical — always-on charging device)
Because the companion phone runs 24/7 on a charger reading SMS, **device health is a first-class screen, not an afterthought** — overheating/over-charging is the top hardware failure mode for these always-plugged devices.
- A **2-column health grid** on the status dashboard: tiles for **Battery %**, **Temperature**, **Charging state**, **Uptime / last heartbeat**, **Signal**, **Pending queue**.
- Battery tile: ring gauge colored by `--m-batt-*`; warn if charging continuously at 100% (recommend 30–80% charge-limit hint) and if battery temp crosses thresholds.
- Temperature tile: live °C with `--m-temp-*` bands (cool/warm/hot) and a 24h sparkline; a persistent banner if sustained > ~40°C ("Move device to a cooler, ventilated spot — continuous charging detected").
- Charging tile: shows charger connected/AC/USB and a gentle "battery care" tip for 24/7 operation.
- These map to Android `BatteryManager`/`Intent.ACTION_BATTERY_CHANGED` (level, temperature, status) — no special permission required.

---

## 2. User Navigation Flows (screen-by-screen)

Bottom nav (4): **Home (Status)** · **Transactions** · **SMS Gate** · **Settings**. First-run is a linear pairing wizard.

### 2.1 Pairing setup (first-run wizard) — maps to the OTP handshake
1. **Welcome / purpose disclosure** — full-screen *prominent disclosure* (required for Play): what SMS is read (only whitelisted bank/wallet senders), why (auto-confirm payments), where it goes (your own server, encrypted), never sold/shared. Primary CTA "Get started", secondary "Learn more".
2. **Server address** — enter/confirm the self-hosted OwnPay URL (`APP_URL`); validates TLS reachability.
3. **Enter pairing code** — 6-digit OTP input (Outfit, large, auto-advance) generated in the admin panel; countdown shows the 5-min expiry; "resend" guidance. → `POST /api/mobile/v1/devices` *(note: backend FIND-019 must be fixed so this route is reachable pre-token)*.
4. **Device fingerprint + biometric enrolment** — generate the device fingerprint; prompt to enable biometric lock for the in-app audit trail.
5. **Grant SMS permission** — only now trigger the runtime `READ_SMS`/`RECEIVE_SMS` request (after the disclosure + affirmative consent). If declined → app still works in **manual-confirm** mode (graceful, and a Play-friendly fallback).
6. **Success** — store `access_token`/`refresh_token`/`aes_key`; fetch `filter-rules`; land on Home.

### 2.2 Home — Connection Status dashboard
- Top: **connection orb** (pulsing) — Connected / Reconnecting / Offline; last heartbeat time (`/devices/heartbeats`).
- **Matched today** count-up (Outfit display) + small "pending" and "ignored (local)" counters.
- The **battery/temperature health grid** (§1.4).
- **Offline cache queue indicator**: a prominent badge "N messages queued" when the server is unreachable; tapping shows the queue with retry/backoff status. The device must **persist matched-but-unsent** messages locally (encrypted) and flush on reconnect — never drop a financial SMS.
- Pull-to-refresh re-pings status + re-fetches `filter-rules`.

### 2.3 Transactions
- Reverse-chron list of recent transactions (`/dashboard`), each row: amount (tabular Outfit), gateway/wallet, status pill, time, and "matched via SMS" indicator. Tap → detail (linked SMS receipt excerpt, redacted).
- Filter chips (status/gateway/date). Empty-state with guidance.

### 2.4 SMS Gate (the privacy-gate transparency screen — also a Play-review asset)
- **Whitelist (read-only mirror of `allowed_senders`)**: the bank/wallet senders configured in admin; clearly labelled "managed from your OwnPay admin panel". Shows last `filter-rules` sync time + interval.
- **Ignored locally**: the `negative_keywords` (OTP/PIN/password/verify/code) shown as "These are never read or sent — filtered on your device."
- **Live gate log (today)**: counts of *matched & sent* vs *ignored on-device*, reinforcing that personal/OTP SMS never leave the phone. This screen is the visible proof of the data-safety claim.
- Note: whitelist is **configured server-side** (admin panel) and mirrored here read-only — the app cannot widen its own scope (defense-in-depth).

### 2.5 Biometric-locked local Audit Trail
- Separate, **biometric-gated** screen (BiometricPrompt). Until unlocked, shows only a lock state.
- Contents: an append-only, on-device, encrypted log of every SMS event — `received → gate decision (matched/ignored + reason) → encrypted → sent → server ack`, with timestamps and the (redacted) sender. Read-only; exportable as an encrypted file for support.
- Purpose: user-verifiable transparency + a concrete artifact for Google Play review (demonstrates whitelist-only behavior).

### 2.6 Settings
- **Account/session**: signed-in user, brand, sign-out (revokes device → `/devices/{id}` or bulk).
- **Security**: biometric lock toggle, token status (auto-refresh via `/devices/token-refreshes`), re-pair.
- **Automation**: SMS permission status (+ deep link to system settings if revoked), manual-confirm fallback toggle, `filter-rules` refresh interval (read-only, server-driven).
- **Device health**: charge-care tips, temperature thresholds, notification preferences.
- **Theme**: System / Dark / Light (mirrors web tokens).

### 2.7 Cross-cutting states (no dead ends)
- **Offline**: banner + queue badge; everything read-only continues from cache; matched SMS persist locally and flush on reconnect.
- **Token expired**: silent refresh; only if refresh fails → re-auth prompt (never a blank screen).
- **Permission revoked by OS**: clear remediation card with a deep link, and automatic fall-back to manual-confirm.
- **Server unreachable / wrong URL**: actionable error with "edit server address", not a generic failure.

---

## 3. Design ↔ backend traceability
| Screen / element | Backend |
|---|---|
| Pairing code entry | `POST /api/mobile/v1/devices` (OTP, FOR UPDATE single-use) — *unblock per FIND-019* |
| Connection orb / heartbeat | `POST /api/mobile/v1/devices/heartbeats`, `GET /devices/statuses` |
| Token auto-refresh | `POST /api/mobile/v1/devices/token-refreshes` (rotation + jti blacklist) |
| Matched / transactions | `GET /api/mobile/v1/dashboard` |
| SMS send (gated) | `POST /api/mobile/v1/sms` (GCM AES-256-GCM) |
| Whitelist / ignored / interval | `GET /api/mobile/v1/config/filter-rules` |
| Notifications | `GET /notifications`, `POST /notifications/acknowledgements` (device-scoped) |

> The visual system intentionally mirrors `DESIGN.md` (same indigo HSL hue, Inter + Outfit, dark-first, soft-fill status pills) so operators experience the web console and companion app as one coherent OwnPay product.
