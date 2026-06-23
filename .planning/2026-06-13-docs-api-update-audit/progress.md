# Progress Log

## Session: 2026-06-13

### Current Status
- **Phase:** 1 - Requirements & Discovery
- **Started:** 2026-06-13

### Actions Taken
-

### Test Results
| Test | Expected | Actual | Status |
|------|----------|--------|--------|

### Errors
| Error | Resolution |
|-------|------------|

## Session 2026-06-13 (opus) — preparatory reads done
- Verified UpdateService UPDATE_PUBLIC_KEY == update/update_public_key.pem (byte-match).
- Verified GitHub release 302 host is release-assets.githubusercontent.com (live curl test).
  Confirmed NOT a download bug: only top-level download_url host (github.com) is checked;
  CURLOPT_FOLLOWLOCATION follows redirect; checksum+RSA verify post-download.
- Installer needs: .env.example, database/schema.sql, vendor/, writable storage//public//root.
  All shipped by build (.buildignore excludes none of these). Fresh-install path sound.
- Gathered: composer require (php ^8.3, ext bcmath/json/mbstring/openssl/pdo), npm lint-only,
  .env.example structure, 13 middleware groups, 123 gateways, GatewayAdapterInterface (slug/
  initiate/verify/verifyWebhook/refund/supports/supportedCurrencies), manifest.json shape.
- Workflow wr0862rle (22 audit agents + adversarial verify) running in background. Awaiting.

## Session 2 (opus, after lean workflow) — fixes applied
ARCHITECTURE.md: boot order (§2 + Kernel docblock), §4.3 sandbox wording, §4.7 fee rules (brand×gateway, exact currency, 2->accurate 4 tiers), §4.8 env fallback, EnvironmentService docblock op_env.
API code: B1 SMS retry idempotency (CommLogRepository retrySms +status='failed', returns int; controller 409 on 0). B2 SMS ingestion caps (200 batch/100 sender/16KB payload). B4 negative refund rejected (was coerced to full). B5 admin webhookTest SSRF pinning+no-redirect (mirror WebhookDispatcher). B7 pairing endpoint -> strict 5/300s bucket (also covers B9 OTP brute-force). B10 apikey revoke 404 on 0-row. B11 api.php superadmin->merchant-admin docblock. B14 ApiKeyService unset key_hash.
B3 (device fingerprint): DECIDED NOT to enforce in JwtAuthMiddleware — jwt_fingerprint=sha256(deviceUuid.merchantId), both are did/mid JWT claims, so re-check adds no anti-theft value + risks breaking app if header absent on data reqs. Corrected misleading todo.md claim instead. Theft mitigated by 15-min TTL + revocation.
DEFERRED (documented, lower value): B6 dead refresh stateful branch (harmless, method_exists-guarded), B8 SMS dedup +/-1s, B10 admin SmsTemplate/Device 0-row 404, B12 admin regex validate-on-save, B13 single-SMS decryption 200.
NEXT: openapi/README sync (C1-C8), update CLI GitHub-URL fixes, docs/ARCHITECTURE.md + docs/LOCAL_SETUP.md, verify.

## FINAL VERIFICATION (all green)
- phpunit: 525 tests / 1693 assertions / 1 skipped (same as baseline — no regressions)
- phpstan: level 9 clean
- twig-cs-fixer: 79 files clean
- openapi.yaml: valid YAML (python yaml.safe_load)
- Targeted areas (Refund|ApiKey|Sms|Webhook|Developer): 122 tests green
## Deliverables: docs/ARCHITECTURE.md (public) + docs/LOCAL_SETUP.md created. ARCHITECTURE.md (root) 6 fixes. 8 API bugs fixed. Update CLI fixed for GitHub URLs. OpenAPI/README synced (7 fixes).
