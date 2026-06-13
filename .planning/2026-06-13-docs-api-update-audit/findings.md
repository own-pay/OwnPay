# Findings: Docs / API / Update Audit

## Update pipeline — my direct reads (pre-workflow)

### UpdateService.php (src/Update/, 765 lines)
- Embedded UPDATE_PUBLIC_KEY constant MATCHES update/update_public_key.pem (verified byte-for-byte). Good.
- Signature scheme consistent: CLI `openssl_sign($zipData, ...SHA256)`; client `openssl_verify(file_get_contents(zip), base64_decode(sig), pubkey, SHA256)`. Both over raw zip bytes.
- execute() flow: flock lock -> DB in-progress check -> startUpdate -> fetchManifest -> find release -> validate url/checksum/signature present -> host whitelist (update.ownpay.org, github.com, objects.githubusercontent.com) -> backup -> maintenance enter -> download -> checksum -> RSA verify -> extract (zip-slip guarded incl. backslash) -> migrations -> clearCache -> health -> maintenance exit. Rollback on any failure.
- Uses INLINE manifest fields checksum_sha256 + signature (NOT the _url fields). So checksum_url/signature_url in manifest are vestigial for the client. Note for docs.
- RESOLVED (not a bug): host whitelist only checks the INITIAL manifest download_url host (github.com ✓). downloadPackage uses CURLOPT_FOLLOWLOCATION=true and does NOT re-validate redirect targets, so GitHub's 302 to release-assets.githubusercontent.com (verified live — that's the current host, NOT objects.githubusercontent.com) is followed automatically. Downloads work. Checksum+RSA verify post-download blocks any malicious redirect. The objects./release-assets. entries in the whitelist are effectively dead code (only top-level host is checked). LOW: could drop them or document.
- CONCERN: downloadPackage has no max size cap (CURLOPT_TIMEOUT 300 only) — could fill disk. Minor.
- CONCERN: extractPackage extractTo over appRoot — an update zip could overwrite .env? No: zip build hard-denies .env so it's not IN the zip; extract only writes files present. But it WOULD overwrite config/app.php, .htaccess etc (intended). storage/ contents not in zip (skeleton only). Good for preserving user data.

### cli/build-update.php (754 lines) + .buildignore
- Hard-deny: .env*, *private_key*.pem, .git/. Plus .buildignore excludes update/, tests/, cli/, docs/, node_modules, storage/ (skeleton re-added), .env, keys, repo meta-docs.
- Re-adds storage skeleton dirs + public/assets/uploads with .gitkeep so zip works as fresh install.
- Bumps config/app.php version in-zip via regex.
- CONCERN: default download URL still `https://update.ownpay.org/releases/{v}/ownpay-{v}.zip` (prompt default) — should default to GitHub release URL per user requirement. checksum_url/signature_url HARDCODED to update.ownpay.org in manifest writer (lines 630-634) regardless of where zip is hosted.
- CONCERN: .buildignore excludes `update/` ENTIRELY — but UpdateService reads embedded key constant (not the pem file), so OK. BUT does fresh install need update/update_public_key.pem? UpdateService uses the hardcoded constant, so no. OK.
- QUESTION: is vendor/ included? .buildignore does NOT list vendor/ -> vendor IS shipped. Good for fresh-install-without-composer. (0.2.0 manifest = 318MB suggests vendor+more was shipped; 0.2.1/0.2.2 = 8.5MB suggests vendor was NOT shipped or trimmed — INVESTIGATE discrepancy.)
- Next-steps text says "upload files inside update/ to your update server" — but if zip goes to GitHub releases, the workflow is split (zip->GitHub, manifest+metadata->update server). CLI guidance may mislead.

### update/ server artifacts
- manifest.json: channels.stable.download_url = github.com/own-pay/OwnPay/releases/download/v0.2.2/ownpay-0.2.2.zip (GOOD, GitHub).
  But checksum_url/signature_url = update.ownpay.org (split hosting — client uses inline fields so OK).
- releases[] has 0.2.0 (update.ownpay.org, 318MB, migrations), 0.2.1 (update.ownpay.org, 8.5MB), 0.2.2 (github, 8.5MB).
- git ls-files update/: only index.html, manifest.json, update_public_key.pem tracked. PRIVATE KEY NOT TRACKED (good). Verify .gitignore covers it.

### Routes / API
- config/routes/api.php: 14 merchant (api), 1 csp (api-public), ~10 mobile (mobile/mobile-bootstrap), 7 admin (admin-api). RESTful pluralized resources.

## Open verification items (workflow handling)
- ARCHITECTURE.md section-by-section vs code (7 agents)
- Every API endpoint auth/scoping/validation (11 agents)
- Update pipeline correctness (5 agents)
- openapi.yaml sync with routes

## Decisions
- Public repo: github.com/own-pay/OwnPay (user-confirmed)
- Docs visuals: Mermaid + official links (user-confirmed)

## WORKFLOW FINDINGS (8/9 units; api:merchant-core died on 429 — audit inline)

### ARCHITECTURE.md fixes (verify direction, then edit)
- [A1 medium/certain] Boot order INVERTED: §2 10-step + Kernel:18-25 docblock list "4 Load Middleware, 5 Boot Plugins" but code boots plugins FIRST (AUD-G1). Swap in BOTH.
- [A2 low/certain] §4.3 "scanner explicitly permits fwrite/ini_set/header/setcookie" — no allowlist; they pass by omission from blocklist. Reword.
- [A3 high/certain] §4.7 Fee Rules: documents 4 tiers incl 2 "Any Currency" — impossible (op_fee_rules.currency CHAR(3) NOT NULL; resolveActiveRule hard-filters currency=:currency). VERIFY then rewrite to 2 tiers (doc->code alignment).
- [A4 medium/certain] §4.7 "using the TenantScope trait" — resolver uses hand SQL (merchant_id=:mid OR IS NULL), not trait. Reword.
- [A5 low/certain] EnvironmentService.php:14 docblock cites op_env fallback — stale. Remove.
- [A6 info/likely] §4.8 "exclusively through op_system_settings" — get() falls back to env vars. Soften.

### API code bugs (confirmed)
- [B1 high/certain] SMS queue retry NOT idempotent: CommLogRepository::retrySms requeues any status incl 'sent' -> double-send. Add "AND status='failed'".
- [B2 high/certain] SMS ingestion no size/batch cap: SmsController::receive -> DoS + TEXT truncation. Cap count<=200, body<=16KB, sender<=100.
- [B3 high/certain] api:mobile JwtAuthMiddleware never checks X-Device-Fingerprint (only refresh does) -> stolen 24h token usable anywhere. RISKY FIX: verify mobile sends header before enforcing; else document.
- [B4 medium/certain] Negative refund coerced to full refund (RefundService:114). Reject non-positive.
- [B5 medium/certain] Admin webhookTest SSRF weaker than merchant path (DeveloperController:138-171, no IP pin/redirect block). Mirror WebhookDispatcher.
- [B6 medium/certain] Dead refresh stateful branch (DevicePairingService:266-361 writes nonexistent columns). Remove dead code.
- [B7 medium/likely] Mobile pair/refresh endpoints in 60/min bucket not strict login (5/300s). Add to strict.
- [B8 medium/likely] SMS dedup +/-1s bypassable (client received_at). Hash-based dedup.
- [B9 medium/likely] Pairing OTP brute-force only per-admin issuance, no per-verify lockout.
- [B10 medium/certain] Admin write/retry/revoke + API-key revoke return 200 on 0 rows. Return 404.
- [B11 low/certain] api.php:12 docblock "superadmin authorization" wrong -> merchant-scoped admin. Reword.
- [B12 low/likely] Admin regex template saved without syntax validation -> silent no-match. Validate on save.
- [B13 low/likely] Single-SMS decryption fail returns 200. Differentiate.
- [B14 info] ApiKeyService::list unsets 'hash' not 'key_hash' (no-op). Fix column name.

### OpenAPI/README sync (docs/v2/api)
- [C1 high/certain] openapi PaymentIntentResponse missing `data` wrapper + wrong fields.
- [C2 high/certain] openapi RefundCreateResponse wrong (no refund_id; raw record under data).
- [C3 high/certain] Global error envelope doc {success,error,errors,request_id} but BearerAuth emits {success,message}. Reconcile.
- [C4 medium/certain] payments req: webhook_url documented but controller reads callback_url.
- [C5 medium/certain] README bulk-revocations 'ids' should be 'device_ids'.
- [C6 medium/certain] refunds POST missing 422/404 in openapi.
- [C7 low/certain] README redirect_url/cancel_url marked required but optional.
- [C8 info] trx_id alias undocumented.

### Clean (no action): arch:8-links-overall (all 9 doc links exist, 15+ claims verified),
### route↔handler integrity (all 31 routes valid), most of arch:1-4 + 4.5-7.

### Still TODO inline: api:merchant-core (HealthController, PaymentController, TransactionController)
