# Payment Flow Fix Report (বাংলা)

**প্রজেক্ট:** OwnPay  
**রিপোর্ট ধরন:** Incident Fix Summary (Payment Link / Invoice / API Checkout)  
**তারিখ:** 17 February 2026  
**প্রস্তুতকারক:** Codex

---

## 1) Executive Summary

পেমেন্ট লিঙ্ক সাবমিটের পরে 404 ও false-success flow সমস্যা যাচাই করতে গিয়ে ৩টি মূল সমস্যা চিহ্নিত হয়:

1. Frontend AJAX payload handling bug  
2. Local URL/base-path resolution bug  
3. Transaction insert ব্যর্থতা (`sender_type` NOT NULL) যার ফলে redirect URL তৈরি হলেও target transaction তৈরি হচ্ছিল না

এই তিনটি সমস্যা ফিক্স করার পর payment initialization flow এখন fail হলে explicit error দেয়, আর success হলে valid transaction সহ redirect করে।

---

## 2) সমস্যা ও সমাধান (Issue-by-Issue)

## Issue A: Payment Link AJAX form submission bug

### সমস্যা কী ছিল

- JS success callback-এ `response.title`/`response.message` ব্যবহার করা হচ্ছিল, কিন্তু callback param ছিল `data`
- Form submit-এ `serialize()` ব্যবহার হচ্ছিল, যা `multipart/form-data` ফাইল ফিল্ড properly পাঠায় না

### প্রভাব

- False-negative/undefined JS error
- কিছু ক্ষেত্রে backend expected payload না পেয়ে failure response দিতে পারে

### Root Cause

- Callback variable mismatch
- FormData ব্যবহারের জায়গায় url-encoded serialize usage

### Fix

- `serialize()` -> `new FormData(this)`
- `processData: false`, `contentType: false`
- `response.*` -> `data.*`

### রেফারেন্স

- `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php:203`
- `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php:212`
- `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php:213`
- `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php:221`
- `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php:222`

---

## Issue B: Local base URL ভুলভাবে hardcoded ছিল

### সমস্যা কী ছিল

`$site_url` তৈরির logic-এ local host হলে hardcoded folder (`OwnPay-panel/`) ধরা হচ্ছিল।  
Project directory আলাদা হলে AJAX wrong endpoint-এ যেত।

### প্রভাব

- Request wrong URL-এ গিয়ে 404

### Root Cause

- Environment-dependent hardcoded path

### Fix

- `$_SERVER['SCRIPT_NAME']` থেকে dynamic script directory derive করে base URL গঠন
- Folder name-independent URL resolution

### রেফারেন্স

- `pp-content/pp-include/pp-adapter.php:246`
- `pp-content/pp-include/pp-adapter.php:247`
- `pp-content/pp-include/pp-adapter.php:248`

---

## Issue C: Transaction insert fail হচ্ছিল (`sender_type` required)

### সমস্যা কী ছিল

`pp_transaction.sender_type` কলাম `NOT NULL`, কিন্তু multiple create flows-এ insert payload-এ `sender_type` পাঠানো হচ্ছিল না।

### প্রভাব

- Insert ব্যর্থ
- Backend আগে success redirect return করছিল (false-success)
- Redirect URL `/payment/{ref}` এ গিয়ে 404 (কারণ ref DB-তে ছিল না)

### Root Cause

- DB schema requirement (`sender_type`) এবং insert columns mismatch

### Fix

1. Payment transaction insert flows-এ `sender_type` কলাম/ভ্যালু যোগ করা
2. Insert success check যোগ করা (fail হলে descriptive JSON error return)

### রেফারেন্স (schema)

- `pp-content/pp-install/db.sql:460`

### রেফারেন্স (patched insert paths)

- `pp-content/pp-include/pp-adapter.php:8727` (invoice)
- `pp-content/pp-include/pp-adapter.php:8859` (payment-link)
- `pp-content/pp-include/pp-adapter.php:8901` (payment-link-default)
- `index.php:601` (API checkout redirect)
- `index.php:739` (API checkout popup)

### রেফারেন্স (explicit fail response)

- `pp-content/pp-include/pp-adapter.php:8734`
- `pp-content/pp-include/pp-adapter.php:8866`
- `pp-content/pp-include/pp-adapter.php:8908`

---

## 3) কেন 404 আসছিল (Technical Causality)

1. Form submit -> backend payment initialization call  
2. `pp_transaction` insert fail (`sender_type` missing)  
3. Transaction তৈরি হয়নি, কিন্তু UI redirect attempted  
4. `/payment/{ref}` route `ref` পায়নি -> 404

---

## 4) Verification Summary

### Code-level checks

- Updated JS flow for multipart + callback variable correctness
- Dynamic base URL generation enabled
- Transaction creation flows updated with required DB column
- Insert failure handling now explicit

### Syntax checks

- `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php` -> lint pass
- `pp-content/pp-include/pp-adapter.php` -> lint pass
- `index.php` -> lint pass

---

## 5) Scope of This Fix

নিচের transaction-creation paths এই fix-এর scope-এ:

1. Payment Link
2. Payment Link Default
3. Invoice to Payment
4. API Checkout Redirect
5. API Checkout Popup

---

## 6) Residual Risk / Next Recommendation

যদিও issue fix হয়েছে, long-term hardening-এর জন্য:

1. DB-level default বা strict validation policy formalize করা  
2. Insert/Update helper-এ required-column guard যোগ করা  
3. Transaction creation flows-এ centralized factory/service ব্যবহার করা  
4. End-to-end automated test: create -> redirect -> payment page resolve

---

## 7) Change Log (Short)

### Modified files

1. `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php`
2. `pp-content/pp-include/pp-adapter.php`
3. `index.php`

### Nature of changes

- Frontend request payload + callback bug fix
- URL base path resolution fix
- DB-required column alignment (`sender_type`)
- Graceful failure response for transaction init

---

## 8) Remaining Open Issues (Similar Class)

নিচের ইস্যুগুলো এখনও ওপেন আছে এবং এগুলোও payment/invoice integrity, security, বা reliability-তে সরাসরি প্রভাব ফেলতে পারে।

## Issue D: Payment Link quantity race/logic bug

### সমস্যা

`quantity` কমানো হচ্ছে validation-এর আগে।

- `pp-content/pp-include/pp-adapter.php:8758`
- `pp-content/pp-include/pp-adapter.php:8763`
- `pp-content/pp-include/pp-adapter.php:8779`
- `pp-content/pp-include/pp-adapter.php:8862`

### attacker/bypass path

Attacker বা bot repeated init call দিয়ে failed transaction trigger করলেও stock কমিয়ে দিতে পারে (business denial)।

### fix

1. আগে `status/expired` validate  
2. transaction insert success confirm  
3. তারপরই atomic way-তে quantity decrement

---

## Issue E: Public `action-v2` endpoint এ CSRF protection নেই

### সমস্যা

`action-v2` ব্লকে CSRF token verify করা হচ্ছে না।

- `pp-content/pp-include/pp-adapter.php:8649`

### attacker/bypass path

Victim browser session থাকলে crafted cross-site form/post দিয়ে unwanted payment init trigger হতে পারে।

### fix

`action-v2` ব্লকে admin action block-এর মতো `hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])` verification যোগ করতে হবে।

---

## Issue F: Invoice webhook signature verification নেই

### সমস্যা

Webhook raw payload decode হচ্ছে, কিন্তু signature/HMAC verification নেই।

- `index.php:1126`
- `index.php:1136`
- `index.php:1144`

### attacker/bypass path

Forged webhook request পাঠিয়ে invoice status update flow trigger করার ঝুঁকি থাকে।

### fix

1. `X-Signature` header + shared secret ভিত্তিক HMAC verify  
2. timestamp + replay protection  
3. invalid signature হলে `401` return

---

## Issue G: SQL injection risk surface (dynamic query concat)

### সমস্যা

`escape_string()` আসলে sanitize করছে না, এবং বহু জায়গায় dynamic SQL concat হচ্ছে।

- `pp-content/pp-include/pp-functions.php:235`
- `pp-content/pp-include/pp-adapter.php:3462`
- `pp-content/pp-include/pp-adapter.php:3487`
- `pp-content/pp-include/pp-adapter.php:3497`

### attacker/bypass path

search/filter input-এ crafted payload দিয়ে query logic manipulate করা সম্ভব হতে পারে।

### fix

সব dynamic condition parameterized query-তে migrate করতে হবে; string interpolation বন্ধ করতে হবে।

---

## Issue H: XSS risk in payment-link template output

### সমস্যা

Product title/description raw output হচ্ছে, output escaping নেই।

- `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php:140`
- `pp-content/pp-modules/pp-themes/twenty-six/payment-link.php:141`

### attacker/bypass path

Stored malicious HTML/JS inject হলে visitor browser-এ execute হতে পারে।

### fix

User-controlled output এ `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` প্রয়োগ করা।

---

## Issue I: TLS verification disable

### সমস্যা

Gateway integration-এ SSL verify off করা আছে।

- `pp-content/pp-modules/pp-gateways/paystation/class.php:140`
- `pp-content/pp-modules/pp-gateways/sslcommerz/class.php:108`
- `pp-content/pp-modules/pp-gateways/nagad-merchant-api/vendor/xenon/nagad-api/src/Helper.php:122`
- `pp-content/pp-modules/pp-gateways/nagad-merchant-api/vendor/xenon/nagad-api/src/Helper.php:156`

### attacker/bypass path

Man-in-the-middle attack-এ gateway API response spoof/tamper হওয়ার ঝুঁকি বাড়ে।

### fix

`CURLOPT_SSL_VERIFYPEER=true`, `CURLOPT_SSL_VERIFYHOST=2`, valid CA bundle enforce।

---

## Issue J: Hardcoded secret key and weak secret management

### সমস্যা

HMAC secret hardcoded।

- `pp-content/pp-include/pp-adapter.php:428`

### attacker/bypass path

Code leak/log leak হলে signature forging সহজ হয়, এবং key rotation কঠিন হয়।

### fix

Secret env/config vault-এ নিতে হবে, rotation policy ও per-environment key ব্যবহার করতে হবে।

---

## Issue K: Auto-update integrity & extraction hardening gap

### সমস্যা

Update checksum SHA-1; zip extraction path validation stronger না।

- `pp-content/pp-include/pp-adapter.php:7572`
- `pp-content/pp-include/pp-functions.php:1551`
- `pp-content/pp-include/pp-functions.php:1584`

### attacker/bypass path

Supply-chain বা tampered update archive হলে unsafe file overwrite risk বাড়ে।

### fix

1. SHA-256/512 ও signed manifest ব্যবহার  
2. zip entry canonical path validation (`..`, absolute path block)  
3. allowlisted target path only

---

## Issue L: Production error disclosure

### সমস্যা

Production-safe error handling enforce করা হয়নি।

- `pp-content/pp-include/pp-adapter.php:5`
- `pp-content/pp-include/pp-functions.php:98`

### attacker/bypass path

Runtime error থেকে internal path, DB details বা environment তথ্য leak হতে পারে।

### fix

`display_errors=0`, centralized logging, generic user-facing error response policy।
