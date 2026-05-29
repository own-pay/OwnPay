# Phase 1 Discovery Report — End-to-End Browser Security & Functionality Audit

This report documents all discoveries made during the full unauthenticated and authenticated browser audit of the OwnPay platform at `https://ownpay.test`.

## AUDIT SUMMARY

- **Total pages visited:** 28
- **Total interactive elements triggered:** 84+
- **Total findings:** 4
  - **Critical:** 0
  - **High:** 2
  - **Medium:** 0
  - **Low:** 2

---

## FINDINGS

### ID: FIND-001
- **Severity:** HIGH
- **Page:** multiple pages (e.g. `/`, `/admin/transactions`, `/admin/disputes`, `/admin/gateways`, `/admin/customers`, `/admin/reports`, `/admin/activities`, `/admin/plugins`).
- **Trigger:** Initial page navigation and view rendering.
- **Finding:** Browser blocked inline element styles due to Content Security Policy (CSP) violations.
- **Evidence:**
  `[error] Applying inline style violates the following Content Security Policy directive 'style-src 'self' 'nonce-...''.`
- **Risk:** Visual elements using element-level inline styles (such as background gradients, spacing, and logo alignments) are entirely ignored and blocked by the browser's security boundaries. This causes a heavily degraded user experience, broken structural grids, and invisible elements in highly secure production deployments.
- **Status:** OPEN

---

### ID: FIND-002
- **Severity:** HIGH
- **Page:** `/install` (all setup step templates and locked lockout template)
- **Trigger:** Navigating to installer setup steps.
- **Finding:** Content Security Policy blocks inline event handler (`onerror`) execution on image elements.
- **Evidence:**
  `[error] Executing inline event handler violates the following Content Security Policy directive 'script-src 'self' 'nonce-...''.`
- **Risk:** The fallback mechanism for failed resources is completely disabled, leading to broken assets. In addition, using inline JS attributes violates the baseline principles of CSP and script isolation, allowing potential script injection vectors if dynamic attributes are compromised.
- **Status:** OPEN

---

### ID: FIND-003
- **Severity:** LOW
- **Page:** `/install` (all steps and locked view)
- **Trigger:** Navigating to installer setup steps.
- **Finding:** Primary OwnPay setup logo resource is broken and returns `net::ERR_NAME_NOT_RESOLVED`.
- **Evidence:**
  `GET https://cdn.ownpay.org/assets/logo.png [net::ERR_NAME_NOT_RESOLVED]`
- **Risk:** Shows a broken image logo in the installer header; also makes unnecessary external lookups on page load, which slow down setup performance.
- **Status:** OPEN

---

### ID: FIND-004
- **Severity:** LOW
- **Page:** `/login` / `/forgot-password`
- **Trigger:** Initial page load of authentication screens.
- **Finding:** Missing favicon file (`/favicon.ico`) resulting in 404 (Not Found).
- **Evidence:**
  `GET https://ownpay.test/favicon.ico [404]`
- **Risk:** Aesthetic deficiency in browser tab bar, and spawns unnecessary request cycles through the framework front-controller to handle the missing resource.
- **Status:** OPEN
