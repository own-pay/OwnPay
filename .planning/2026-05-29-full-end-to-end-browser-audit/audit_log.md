# Comprehensive Browser Audit Page Log

This document records the individual, page-by-page browser audit logs compiled during the unauthenticated and authenticated end-to-end browser walkthroughs of OwnPay at `https://ownpay.test`.

---

## Part 1: Unauthenticated Browser Audit Logs

### PAGE: `https://ownpay.test/`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None* (Style block violations from element-level styles completely fixed under **FIND-001**)
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/login`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None* (Favicon 404 resolved under **FIND-004**)
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None* (Favicon transparent binary asset loaded correctly)
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/forgot-password`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/2fa`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/install`
- **STATUS:** 200 OK (or redirects to lockout / root if `.installed` exists)
- **CONSOLE ERRORS:**
  - *None* (CSP script execution and blockages from `onerror` handler resolved under **FIND-002**)
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None* (Broken image CDN URL references replaced with high-contrast offline `.ins-logo-fallback` element classes under **FIND-003**)
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/checkout/OP-CE101A363B`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/checkout/intent/INTENT-101`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/invoice/INV-101`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/pay/payment-link-1`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

## Part 2: Authenticated Browser Audit Logs (Admin Portal)

### PAGE: `https://ownpay.test/admin`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None* (Dynamic dynamic dashboard panels render with zero inline CSP blockages)
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/transactions`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/transactions/1`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/invoices`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/invoices/create`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/disputes`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/payment-links`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/customers`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/brands`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/staff`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/roles`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/gateways`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/domains`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/settings`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/currencies`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/devices`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/sms-center`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/sms-data`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/api-keys`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/developer`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/ledger`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/reports`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None* (Dynamic style directives load cleanly across summary charts)
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/activities`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/webhooks/events`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/my-account`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/faq`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/plugins`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None* (Dynamic style grids for all discovered plugins render with zero inline CSP blockages)
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/themes`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/system-update`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*

---

### PAGE: `https://ownpay.test/admin/balance-verification`
- **STATUS:** 200 OK
- **CONSOLE ERRORS:**
  - *None*
- **CONSOLE WARNINGS:**
  - *None*
- **CONSOLE LOGS/INFO (potential leaks):**
  - *None*
- **NETWORK ERRORS:**
  - *None*
- **FAILED RESOURCES:**
  - *None*
- **INFORMATION LEAKS OBSERVED:**
  - *None*
