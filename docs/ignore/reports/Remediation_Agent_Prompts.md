# Own Pay — AI Agent Remediation Prompts (Phases 2-5)

এই প্রম্পটগুলো এমনভাবে ডিজাইন করা হয়েছে যাতে আপনি যে কোনো অ্যাডভান্সড এআই এজেন্ট (যেমন: Antigravity, Claude Engineer, বা Bolt.new) ব্যবহার করে ধাপে ধাপে প্রোজেক্টটি ফিক্স করতে পারেন।

---

## 🚀 Phase 2: SOA Bridge & Business Logic Wiring
**Objective:** legacy monolith-এর সাথে আধুনিক SOA (Service-Oriented Architecture) লেয়ারের সংযোগ স্থাপন এবং বিজনেস লজিক হার্ডেনিং।

### Agent Prompt:
> **Role:** Senior Fintech Backend Engineer.
> **Context:** The project has a dormant SOA layer in `src/` and a legacy core in `app/core/`.
> **Task:**
> 1. **Bootstrap SOA:** `index.php`-এর শুরুতে `vendor/autoload.php` রিকয়ার করো। একটি গ্লোবাল সার্ভিস কন্টেইনার বা সরাসরি সার্ভিস মেথড কল করার ব্যবস্থা করো।
> 2. **Idempotency Protection:** `app/core/adapter.php`-তে থাকা পেমেন্ট প্রসেসিং এবং ওয়েব হুক হ্যান্ডলিং-এ `OwnPay\Service\IdempotencyService` ইন্টিগ্রেট করো। একই রিকোয়েস্ট যেন দুইবার প্রসেস না হয় তা নিশ্চিত করো।
> 3. **State Machine Enforcement:** `adapter.php`-এর পেমেন্ট স্ট্যাটাস আপডেট লজিক সরাসরি পরিবর্তন না করে `OwnPay\Service\PaymentService::transitionStatus()` মেথডের মাধ্যমে পরিচালনা করো। (initiated → pending → completed → refunded) ফ্লো নিশ্চিত করো।
> 4. **Accounting Ledger:** পেমেন্ট সাকসেসফুল হলে, রিফান্ড দিলে বা সেটেলমেন্ট হলে `OwnPay\Service\LedgerService`-এর সংশ্লিষ্ট মেথডগুলো কল করে `op_ledger` টেবিলে ডাবল-এন্ট্রি রেকর্ড নিশ্চিত করো।
> 5. **Optimistic Locking:** ডাটাবেস রেস কন্ডিশন এড়াতে `ap_transactions` টেবিলে `version` কলাম যোগ করো এবং আপডেটের সময় `WHERE id = ? AND version = ?` কুয়েরি ব্যবহার করো।
>
> **Constraint:** ডোন্ট রিরাইট এভরিথিং। লিগ্যাসি কোডের ভেতর থেকে সার্ভিস গুলো কল করে ব্রিজ তৈরি করো।

---

## 🏗️ Phase 3: Architectural Decomposition
**Objective:** ৫8২ KB-র `adapter.php` এবং ১২৯ KB-র `functions.php` গড-ফাইলগুলোকে ভেঙে ছোট ছোট কন্ট্রোলার এবং সার্ভিসে রূপান্তর।

### Agent Prompt:
> **Role:** Principal Software Architect.
> **Objective:** Break the monolith.
> **Task:**
> 1. **Decompose `adapter.php`:** ৩.১ সেকশন অনুযায়ী `adapter.php`-এর বিশাল লজিকগুলোকে ভেঙে ডোমেইন-ভিত্তিক কন্ট্রোলারে (যেমন: `CustomerController`, `InvoiceController`, `TransactionController`) ভাগ করো।
> 2. **Refactor `functions.php`:** গ্লোবাল ফাংশনগুলোকে অবজেক্ট-ওরিয়েন্টেড সার্ভিসে রূপান্তর করো (যেমন: `DateTimeService`, `CurrencyService`, `EmailService`) এবং সেগুলোকে `src/Service/` ফোল্ডারে রাখো।
> 3. **Standardize Gateways:** `app/modules/gateways/` ফোল্ডারে থাকা ৪৬টি গেটওয়ে মডিউলকে `OwnPay\Gateway\GatewayAdapterInterface` অনুযায়ী আপডেট করো। সব গেটওয়েতে `process()`, `verify()`, এবং `refund()` মেথড ইনহেরিট করো।
>
> **Verification:** নিশ্চিত করো যে `require` চেইন ঠিক আছে এবং কোনো গ্লোবাল ভ্যারিয়েবল ডিপেন্ডেন্সি ব্রেক হয়নি।

---

## 🎨 Phase 4: Frontend Modernization & UX Consistency
**Objective:** jQuery সরিয়ে Vanilla JS ব্যবহার এবং ইউজার এক্সপেরিয়েন্স প্রিমিয়াম করা।

### Agent Prompt:
> **Role:** Lead UI/UX Engineer.
> **Objective:** Modernize Admin Dashboard.
> **Task:**
> 1. **Kill jQuery AJAX:** এডমিন ড্যাশবোর্ডের (`app/admin/dashboard/`) সবগুলো ফাইলে থাকা ৫০+ `$.ajax()` কল রিফ্যাক্টর করে আধুনিক `fetch()` API ব্যবহার করো।
> 2. **Client-side Validation:** সবগুলো ফর্মে HTML5 ভ্যালিডেশন (required, email, pattern) যুক্ত করো।
> 3. **Clean JS Templates:** জাভাস্ক্রিপ্ট ফাইলের ভেতর সরাসরি HTML গ্রাফিক্স/রেন্ডারিং না লিখে ডেডিকেটেড রেন্ডারার ফাংশন ব্যবহার করো।
> 4. **Skeleton Loading:** ডাটা লোড হওয়ার সময় থমথমে সাদা স্ক্রিনের বদলে Tabler UI-এর স্টাইলিশ স্কেলিটন বা শিমার লোডিং ইফেক্ট যুক্ত করো।
> 5. **Unified Design:** লগইন, ইনস্টলার এবং ড্যাশবোর্ডের ফন্ট (Inter), কালার প্যালেট এবং কার্ড শ্যাডো একরকম করো যাতে পুরো সিস্টেমটি একটি সিঙ্গেল প্রিমিয়াম ব্র্যান্ডের মতো মনে হয়।

---

## 🛡️ Phase 5: Final Cleanup & Hardening
**Objective:** সিস্টেমের পারফরম্যান্স অপ্টিমাইজেশন এবং হাই-লেভেল সিকিউরিটি পলিসি ইমপ্লিমেন্টেশন।

### Agent Prompt:
> **Role:** Cyber Security Ops & Performance Engineer.
> **Task:**
> 1. **Asset Optimization:** ড্যাশবোর্ডে CDN ব্যবহারের পর এখন লোকাল `assets/css/tabler.min.css` এবং ফন্ট ফাইলগুলো ডিলিট করো যদি সেগুলো আর প্রয়োজন না হয়।
> 2. **Nagad SDK Fix:** `nagad-merchant-api` গেটওয়ের ভেতর সরাসরি `vendor/` ডিরেক্টরি ডিলিট করে সেটি প্রোজেক্টের মেইন `composer.json`-এ নিয়ে আসো এবং `CURLOPT_SSL_VERIFYPEER` ট্রু করো।
> 3. **Brand Alignment:** লিগ্যাসি `pp-` প্রিপিক্সের প্যারামিটারগুলোকে (e.g. `pp-token`) `ap-` তে পরিবর্তন করো, তবে ব্যাকওয়ার্ড কম্প্যাটিবিলিটির জন্য ওল্ড নামগুলোও ৩ মাসের জন্য অ্যালিয়াস হিসেবে রাখো।
> 4. **Strict CSP:** `index.php`-তে থাকা Content-Security-Policy থেকে `'unsafe-inline'` সরিয়ে দাও এবং প্রতিটি স্ক্রিপ্ট লোডের জন্য ইউনিক `nonce` ব্যবহার করো।
> 5. **Production Readiness:** রুট ডিরেক্টরিতে আর কোনো লুজ ফাইল বা ডাম্প ফাইল আছে কি না চেক করে ক্লিন করো।
