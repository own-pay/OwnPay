# OwnPay - টেকনিক্যাল অডিট রিপোর্ট

**প্রজেক্ট:** OwnPay  
**রিপোর্ট টাইপ:** Security + Business Logic + Architecture + Code Quality Audit  
**অডিট তারিখ:** 16 February 2026  
**অডিট পদ্ধতি:** Static Code Review, Configuration Review, DB Schema Review, Pattern-based Security Scan, PHP Lint Validation  
**স্কোপ:** `c:\laragon\www\OwnPay` (non-vendor core + selected gateway modules)

---

## (Executive Summary)

OwnPay একটি custom monolithic PHP payment platform যেখানে core payment flow, admin management, device/OTP companion flow, webhook processing, এবং plugin-based gateway/theme system একসাথে কাজ করে। কোডবেস কার্যকর হলেও বেশ কিছু **উচ্চ-ঝুঁকির নিরাপত্তা দুর্বলতা** এবং **বিজনেস-ক্রিটিক্যাল লজিক গ্যাপ** বিদ্যমান।

### সামগ্রিক রিস্ক অবস্থা

- **Critical:** 4
- **High:** 8
- **Medium:** 12
- **Low:** 4

### প্রোডাকশনে যাওয়ার আগে বাধ্যতামূলক (Blocker)

1. SQL injection surface বন্ধ করা (core query pattern hardening)  
2. Hardcoded secret removal + signed token replay protection  
3. Inbound webhook signature verification  
4. Update pipeline integrity hardening (SHA-256/signature + zip path guard)  
5. XSS surface remediation (output encoding + safe DOM rendering)

### দ্রুত যাচাই ফলাফল

- **PHP lint:** 272 non-vendor PHP files scanned, **0 syntax error**

---

## অডিট স্কোপ ও সীমাবদ্ধতা

### স্কোপে যা ছিল

- `index.php` routing + public/API/webhook/cron logic
- `pp-content/pp-include/pp-functions.php` (DB helpers, utilities, upload/update helpers)
- `pp-content/pp-include/pp-adapter.php` (action/controller monolith)
- `pp-content/pp-install/db.sql` (schema/index design)
- public themes + admin views
- selected gateway modules (SSL আচরণসহ)

### স্কোপে যা ছিল না

- পূর্ণ runtime pentest
- external infrastructure (WAF/CDN/Server hardening)
- third-party gateway backend environments

---

# ১) Deep Security Audit

## SEC-01: Systemic SQL Injection Surface

**Severity:** Critical  
**OWASP:** A03:2021 Injection  
**CWE:** CWE-89

### প্রমাণ (Evidence)

- `pp-content/pp-include/pp-functions.php:235` (`escape_string()` কার্যত no-op)
- `pp-content/pp-include/pp-functions.php:315` (`UPDATE ... WHERE $condition`)
- `pp-content/pp-include/pp-functions.php:335` (`DELETE ... WHERE $condition`)
- `pp-content/pp-include/pp-adapter.php:865`, `2221`, `3105`, `7006` (search input SQL concat)
- অসংখ্য CRUD action-এ `ItemID` string-concat condition (যেমন `pp-content/pp-include/pp-adapter.php:1157`, `3326`, `4004`, `7297`)

### কীভাবে ইস্যু

Prepared statement ব্যবহার করা হলেও WHERE অংশ অনেক জায়গায় string concat। ফলে user-controlled data condition string-এ ঢুকে query semantics পাল্টাতে পারে।

### কীভাবে attacker bypass/abuse করতে পারে

- Filter/search endpoint-এ crafted input দিয়ে authorization filter bypass, mass read
- Delete/Update endpoints-এ crafted `ItemID` দিয়ে unintended row update/delete
- SQL syntax manipulation করে data extraction/logic bypass

### সম্ভাব্য প্রভাব

- Data leakage
- Unauthorized modification/deletion
- Cross-tenant data exposure

### সুপারিশ (Fix)

1. `getData/updateData/deleteData` refactor করে **condition-ও parameterized** করুন  
2. Raw condition string passing policy remove করুন  
3. Central query builder বা safe repository layer ব্যবহার করুন

```php
function updateDataSafe(string $table, array $columns, array $values, string $where, array $whereParams = []): bool {
    $pdo = connectDatabase();
    $set = [];
    foreach ($columns as $i => $col) {
        $set[] = "`$col` = :v$i";
    }
    $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE $where";
    $stmt = $pdo->prepare($sql);

    foreach ($values as $i => $v) {
        $stmt->bindValue(":v$i", $v);
    }
    foreach ($whereParams as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    return $stmt->execute();
}
```

---

## SEC-02: Hardcoded HMAC Secret + Replay Window Missing

**Severity:** Critical  
**OWASP:** A02:2021 Cryptographic Failures  
**CWE:** CWE-798

### প্রমাণ

- `pp-content/pp-include/pp-adapter.php:426` hardcoded secret in `hash_hmac`
- `pp-content/pp-include/pp-adapter.php:406`, `422`, `423` token fields

### কীভাবে ইস্যু

Secret source-code-এ hardcoded। Timestamp থাকলেও freshness window validation নেই।

### attacker কীভাবে abuse করতে পারে

- Source leak হলে forged token তৈরি
- পুরানো signed request replay করে action পুনরাবৃত্তি

### ফিক্স

1. Secret DB/env vault-এ move  
2. Timestamp skew check (`<= 5 minutes`)  
3. Secret rotation + key versioning

```php
$secret = get_env('app-hmac-secret', 'both');
$ts = $_POST['pp-app-timestamp'] ?? '';
if (!ctype_digit($ts) || abs(time() - (int)$ts) > 300) {
    http_response_code(401); exit('Expired request');
}
$data = ($_POST['pp-app-id'] ?? '') . '|' . $ts;
$expected = hash_hmac('sha256', $data, $secret);
if (!hash_equals($expected, $_POST['pp-token'] ?? '')) {
    http_response_code(401); exit('Invalid signature');
}
```

---

## SEC-03: Inbound Webhook Signature Verification Missing

**Severity:** Critical  
**OWASP:** A01:2021 Broken Access Control (state change without trust proof)  
**CWE:** CWE-345

### প্রমাণ

- `index.php:1126` invoice webhook branch
- `index.php:1128` raw body read
- `index.php:1144` `pp_id` direct trust

### কীভাবে ইস্যু

Inbound payload authenticity যাচাই হয় না (no HMAC/signature/mTLS)।

### attacker path

- Public endpoint-এ forged payload পাঠিয়ে payment state changes trigger
- Replayed payload দিয়ে repeat state change

### ফিক্স

1. `X-PP-Signature`, `X-PP-Timestamp`, `X-PP-Event-Id` enforce  
2. Shared secret HMAC verify  
3. Event-id table-এ unique constraint দিয়ে idempotency

```php
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_PP_SIGNATURE'] ?? '';
$ts  = $_SERVER['HTTP_X_PP_TIMESTAMP'] ?? '';
$secret = get_env('invoice-webhook-secret', 'both');
$base = $ts . '.' . $raw;
$expected = hash_hmac('sha256', $base, $secret);

if (!ctype_digit($ts) || abs(time() - (int)$ts) > 300 || !hash_equals($expected, $sig)) {
    http_response_code(401); exit('Invalid signature');
}
```

---

## SEC-04: TLS Verification Disabled in Outbound Calls

**Severity:** High  
**OWASP:** A02:2021 Cryptographic Failures  
**CWE:** CWE-295

### প্রমাণ

- `index.php:1879`
- `pp-content/pp-include/pp-adapter.php:4642`, `4703`, `4770`
- `pp-content/pp-modules/pp-gateways/paystation/class.php:140`
- `pp-content/pp-modules/pp-gateways/sslcommerz/class.php:108`

### ঝুঁকি

MITM attacker API response tamper করে false rate/payment status inject করতে পারে।

### ফিক্স

```php
curl_setopt_array($ch, [
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_CAINFO => __DIR__ . '/certs/cacert.pem', // বা system CA bundle
]);
```

---

## SEC-05: Insecure Update Pipeline (SHA-1 + Zip Slip Risk)

**Severity:** Critical  
**OWASP:** A08:2021 Software and Data Integrity Failures  
**CWE:** CWE-327, CWE-22

### প্রমাণ

- `pp-content/pp-include/pp-adapter.php:7570` (`sha1_file`)
- `pp-content/pp-include/pp-functions.php:1551` unzip helper
- `pp-content/pp-include/pp-functions.php:1578` target path নির্মাণ
- `pp-content/pp-include/pp-functions.php:1584` copy from zip path

### কীভাবে exploit হতে পারে

- Malicious update zip-এ `../` path থাকলে arbitrary file overwrite
- SHA-1 collision/tamper bypass ঝুঁকি

### ফিক্স

1. SHA-256/512 mandatory  
2. Offline signature verify (Ed25519 বা RSA)  
3. Extract entry canonicalization (`realpath` boundary check)

```php
$entryNew = str_replace('\\', '/', $entryNew);
if (str_contains($entryNew, '../') || str_starts_with($entryNew, '/')) {
    throw new RuntimeException('Zip Slip detected');
}
```

---

## SEC-06: Stored/Reflected XSS Surfaces

**Severity:** High  
**OWASP:** A03:2021 Injection  
**CWE:** CWE-79

### প্রমাণ

- `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php:140`, `141`
- `pp-content/pp-modules/pp-themes/twenty-six/invoice.php:127`, `137`, `208`
- Admin table rendering:
  - `pp-content/pp-admin/pp-root/customers.php:594`, `595`
  - `pp-content/pp-admin/pp-root/brand-setting/faq-setting.php:550`, `551`
  - `pp-content/pp-admin/pp-root/transaction/index.php:442`, `443`

### attacker path

- Stored payload FAQ/customer/product/invoice fields-এ save
- Admin/public view open হলে script execute
- Session theft, CSRF token extraction, UI redress, request forging

### ফিক্স

1. Server-side output encode (`htmlspecialchars`)  
2. JS side `textContent`/DOM node ব্যবহার, `innerHTML` minimize  
3. CSP rollout

```php
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
<h1><?= e($title) ?></h1>
```

---

## SEC-07: Debug/Error Disclosure in Runtime

**Severity:** High  
**Evidence:** `pp-content/pp-include/pp-adapter.php:5`, `pp-content/pp-include/pp-functions.php:98`

### ঝুঁকি

- Stack/DB error leakage attacker-কে schema/infra reconnaissance সুবিধা দেয়।

### ফিক্স

- Production-এ `display_errors=0`, only centralized logs
- Generic user-safe error responses

---

## SEC-08: Session Hardening Gaps

**Severity:** High  
**Evidence:** `pp-content/pp-include/pp-functions.php:195`, `205`, `221`, `pp-content/pp-include/pp-adapter.php:483`

### সমস্যা

- login success-এ `session_regenerate_id(true)` নেই
- cookie lifetime 365 দিন (risk-heavy)
- logout cookie clear path-এ fixed secure flag inconsistency

### ফিক্স

```php
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => !empty($_SERVER['HTTPS']),
  'httponly' => true,
  'samesite' => 'Lax'
]);
session_start();
session_regenerate_id(true);
```

---

## SEC-09: Companion Token Expiry Not Enforced

**Severity:** Medium  
**Evidence:** `pp-content/pp-include/pp-adapter.php:9324`, `9331`, `9348`, `9706`

### ঝুঁকি

Long-lived token leak হলে mobile companion API misuse চলতে পারে।

### ফিক্স

- `token_expires_at`, `last_used_at`, device binding, rotation
- stale token revoke

---

## SEC-10: Weak Randomness Usage

**Severity:** Medium  
**Evidence:** `pp-content/pp-include/pp-functions.php:370`, `380`, `1339`, `1859`

### ফিক্স

`random_bytes()` / `random_int()` ব্যবহার করুন।

---

## SEC-11: Upload Validation Extension-only

**Severity:** Medium  
**Evidence:** `pp-content/pp-include/pp-functions.php:1364`, `1365`, `1411`

### ঝুঁকি

Spoofed extension ফাইল upload হওয়ার সম্ভাবনা।

### ফিক্স

- MIME check (`finfo`)
- Optional image decode/re-encode gate
- strict max size + storage segregation + no execute permission

---

## SEC-12: Incomplete Security Headers

**Severity:** Medium  
**Evidence:** `index.php:77`, `78`, `79`

### Missing

- Content-Security-Policy
- Strict-Transport-Security
- Permissions-Policy

---

# ২) Business Logic & Flow Analysis

## BIZ-01: Payment Link Quantity Decrement Before Status Validation

**Severity:** High  
**Evidence:** `pp-content/pp-include/pp-adapter.php:8753-8758`, `8774-8776`

### কীভাবে সমস্যা

quantity আগে কমে, পরে status check হয়। inactive/expired আইটেমেও quantity কমে যেতে পারে।

### impact

- Inventory mismatch
- Revenue/reporting distortion

### fix

- Single transaction-এ `WHERE status='active' AND quantity>0` atomic update

---

## BIZ-02: Refund API State Validation Missing

**Severity:** High  
**Evidence:** `index.php:893-899`

### সমস্যা

বর্তমান code completed/pending/initiated distinction ছাড়াই refund state set করতে পারে।

### fix

- Allowed transition matrix: `completed -> refunded` only
- Already refunded হলে idempotent response

---

## BIZ-03: Domain Whitelist Check Not Brand-Scoped

**Severity:** High  
**Evidence:** `index.php:437-439`, `479-481`

### impact

Multi-tenant setup-এ cross-brand domain trust leak হতে পারে।

### fix

Query-তে `brand_id` বাধ্যতামূলক করুন।

---

## BIZ-04: Payment Link Default Amount Validation Gap

**Severity:** Medium  
**Evidence:** `pp-content/pp-include/pp-adapter.php:8888`, `8894`

### impact

Invalid amount (0/negative/non-numeric) transaction তৈরি হতে পারে।

---

## BIZ-05: Create Payment API Idempotency Missing

**Severity:** High  
**Evidence:** `index.php:601-604`, `739-742`

### attacker/abuse scenario

Network retry/replay-এ একই request থেকে multiple transaction তৈরি।

### fix

- `Idempotency-Key` header
- DB unique constraint `(brand_id, idempotency_key)`

---

## BIZ-06: Login/2FA Rate Limit Missing

**Severity:** Medium  

### impact

Brute-force credential stuffing risk বৃদ্ধি।

---

## BIZ-07: Forgot Password Flow Sends New Temp Password via Hook Payload

**Severity:** Medium  
**Evidence:** `pp-content/pp-include/pp-adapter.php:651`, `655`

### ঝুঁকি

Misconfigured notification hook/log collector cleartext temp credential expose করতে পারে।

### fix

Password reset link/token flow adopt করুন; clear password না পাঠানো।

---

# ৩) Architecture Review

## ARC-01: Monolithic Action Router (~10k line) in `pp-adapter.php`

**Severity:** High  
**Evidence:** `pp-content/pp-include/pp-adapter.php`

### impact

- Change risk high
- Testability low
- Security consistency enforce কঠিন

### fix

- Use-case ভিত্তিক service layer
- action handlers split by domain (`AuthAction`, `TransactionAction`, `AdminAction`)

---

## ARC-02: Inefficient Pagination Counting (full fetch + count)

**Severity:** High  
**Evidence:** `pp-content/pp-include/pp-adapter.php:875`, `899`, `995`, `1014` এবং সমজাতীয় block

### fix

- `SELECT COUNT(*)` for totals
- Paginated query only data page fetch

---

## ARC-03: Cron Endpoint Doing Too Many Jobs in Single Run

**Severity:** Medium  
**Evidence:** `index.php:1705-1970`

### impact

- Timeout risk
- Partial failure complexity
- Recovery ambiguity

### fix

Job queue বিভাজন:
- reconcile job
- webhook retry job
- currency refresh job
- update-check job

---

## ARC-04: DB Integrity Model Weak (No FK/Unique)

**Severity:** High  
**Evidence:** `pp-content/pp-install/db.sql:488-699`

### impact

Orphan data, duplicate refs, inconsistent status graphs।

### fix

- FK plan with phased rollout
- core uniqueness: transaction ref, api key, domain per brand

---

## ARC-05: Date Fields Stored as `varchar(20)`

**Severity:** Medium  
**Evidence:** `pp-content/pp-install/db.sql:36-37`, `466-467`

### impact

Sorting/index efficiency কমে, date arithmetic fragile হয়।

### fix

`DATETIME`/`TIMESTAMP` migration।

---

# ৪) Directory & Structural Analysis

## STR-01: Coupled Core Layers

- Routing, business rules, persistence, rendering concerns interleaved.
- Separation of concerns দুর্বল।

## STR-02: Inline JS-heavy Admin Rendering

- reusable component pattern নেই।
- escaping responsibility fragmented।

## STR-03: Dependency Governance Fragmented

- root-level dependency manifest/lock অনুপস্থিত।
- module-local vendor-only approach long-term auditability কমায়।

## STR-04: Config/Secrets Hybrid Storage

- file + DB mix; secret lifecycle policy formalized নয়।

---

# ৫) Syntax & Code Level Audit

## Q-01: Syntax Result

- 272 non-vendor PHP files linted
- 0 syntax errors

## Q-02: Dead/Incorrect Condition Example

`show_limit` int cast করার পর `'all'` check অর্থহীন হতে পারে।  
Affected repeated pagination blocks in `pp-content/pp-include/pp-adapter.php`.

## Q-03: JS Bug in Payment Link Template

**Evidence:** `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php:219-220`  
`response.title/response.message` ব্যবহার করা হয়েছে যেখানে callback param `data`।

## Q-04: Manual JSON String Composition

**Evidence:** `pp-content/pp-include/pp-adapter.php:8726`, `8855`, `8894`

### সমস্যা

Unescaped quote/backslash এ malformed JSON বা stored injection vector তৈরি হতে পারে।

### fix

```php
$customerInfoJson = json_encode([
  'name' => $customer_name,
  'email' => $customer_email,
  'mobile' => $customer_mobile,
], JSON_UNESCAPED_UNICODE);
```

---

# ৬) Deprecated / Outdated Patterns & Dependencies

## DEP-01: SHA-1 for Update Integrity

**Evidence:** `pp-content/pp-include/pp-adapter.php:7570`  
**Recommendation:** SHA-256 + signature

## DEP-02: Legacy RNG Primitives

`rand/mt_rand/str_shuffle` -> `random_int/random_bytes`

## DEP-03: Vendor Deprecation Indicators

Symfony translation extractor deprecation markers found in vendored package:
- `pp-content/pp-modules/pp-gateways/nagad-merchant-api/vendor/symfony/translation/Extractor/PhpExtractor.php:14`

## DEP-04: Future Compatibility Risk

Runtime requirement gate currently PHP `8.1.x - 8.3.x` পর্যন্ত scoped:
- `pp-content/pp-include/pp-adapter.php:23`, `25`

## DEP-05: Password Hashing Upgrade Path

বর্তমানে `PASSWORD_BCRYPT`; high-security deployments-এ `PASSWORD_ARGON2ID` preferred হতে পারে।

---

# Actionable Remediation Plan (Priority-Based)

## P0

1. SQL concat removal + safe query wrappers  
2. Hardcoded secret removal + replay protection  
3. Webhook signature verification  
4. SSL verify enforcement সব cURL path-এ  
5. Update pipeline hash/signature + zip traversal guard  
6. Public/admin XSS patch

## P1

1. Payment-link atomic stock validation  
2. Refund transition validation  
3. Brand-scoped whitelist checks  
4. Login/2FA rate limiting  
5. Session regeneration + cookie hardening  
6. Companion token expiry/rotation

## P2

1. Schema modernization (datetime, unique, FK)  
2. Monolith split into service/use-case modules  
3. Root dependency governance + automated security checks  
4. Automated test suite (security regression + business flow)

---

# Suggested Verification Checklist (Post-Fix QA)

1. SQLi payload দিয়ে list/filter/delete/edit endpoints re-test  
2. Expired timestamp + invalid signature request reject হচ্ছে কি না  
3. Unsiged webhook request always `401` কিনা  
4. MITM simulation-এ TLS failure enforced কিনা  
5. XSS payload save করে UI render-safe কিনা  
6. Duplicate checkout request-এ idempotent behavior আছে কিনা  
7. Refund শুধু completed transaction-এ allowed কিনা  
8. Inactive/expired payment-link-এ quantity unchanged থাকে কিনা  
9. Session fixation test pass কিনা  
10. Cron jobs partial failure হলেও recoverable কিনা

---

# Sample Hardened Snippets (Quick Start)

## 1) Safe Output Encoder

```php
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

## 2) Idempotency Key Gate

```php
$idem = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
if ($idem === '') {
    http_response_code(400); exit('Missing Idempotency-Key');
}
// enforce unique (brand_id, idempotency_key)
```

## 3) Secure Upload MIME Gate

```php
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg','image/png','image/gif','image/webp'];
if (!in_array($mime, $allowed, true)) {
    return json_encode(['status' => false, 'message' => 'Invalid file type']);
}
```

---

# Appendix A: High-Risk Evidence Index

- `pp-content/pp-include/pp-functions.php:235`
- `pp-content/pp-include/pp-functions.php:315`
- `pp-content/pp-include/pp-functions.php:335`
- `pp-content/pp-include/pp-adapter.php:426`
- `index.php:1126`
- `index.php:1879`
- `pp-content/pp-include/pp-adapter.php:7570`
- `pp-content/pp-include/pp-functions.php:1551`
- `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php:140`
- `pp-content/pp-admin/pp-root/customers.php:594`

---

# Final Note

এই রিপোর্টটি code-level evidence-ভিত্তিক এবং production risk কমানোর জন্য actionable remediation path প্রদান করে।  
সঠিক rollout strategy হবে:

1. P0 fixes -> 2. security re-test -> 3. business regression test -> 4. staged deployment.

