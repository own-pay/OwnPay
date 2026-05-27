---
trigger: model_decision
description: Apply when implementing front-end designs, optimizing web/application performance, conducting general web security reviews, handling inputs/cookies, or configuring HTTP headers.
---

# Web Security & Performance Rules

## 1. Web Security (OWASP, PCI-DSS, ISO-27001)
- **Input Sanitization & CSRF:**
  - Route all input sanitization through `OwnPay\Service\System\InputSanitizer::array()` using the strictly allowed methods (`string`, `html`, `email`, `url`, `phone`, `slug`, `attr`, `trim`).
  - Enforce CSRF protection on all mutating state POST/PUT/DELETE requests.
- **SQL Injection Prevention:**
  - Use parameterized PDO queries with parameter binding exclusively. Zero raw string interpolation.
- **Authentication & Authorization:**
  - Secure brand scoping: retrieve active brand context via `BrandContext::resolveFromRequest($req)` and scope all queries via `TenantScope`.
  - Passwords hashed with `PASSWORD_ARGON2ID` with OWASP-compliant cost parameters.
- **XSS & Content Security Policy (CSP):**
  - Sanitize and escape all inputs before rendering.
  - Set security headers via `SecurityHeadersMiddleware` (HSTS, CSP, X-Content-Type-Options: nosniff, X-Frame-Options: DENY, Referrer-Policy).
  - Use nonce-based CSP: default-src 'self'; object-src 'none'; frame-ancestors 'none'.
- **Cookie Security:**
  - Enforce `HttpOnly`, `Secure`, and `SameSite` flags. Encrypt sensitive cookies and set short expirations.
- **Dependency & Error Security:**
  - Keep `composer.lock` and NPM dependencies audited (`composer audit` & `npm audit`).
  - Mask runtime errors and DB stack traces under production (`APP_DEBUG=false`).

## 2. Web Performance (Core Web Vitals & Optimizations)
- **Core Web Vitals Targets:** LCP < 2.5s, FID < 100ms, CLS < 0.1.
- **LCP & TTFB:**
  - Minimize TTFB by optimizing database queries and implementing caching (e.g. redis, runtime caches).
  - Defer render-blocking resources. Preload critical fonts/CSS.
- **JavaScript & CSS Optimization:**
  - Minify assets, use code splitting, tree shaking, and lazy load scripts (use `defer` or `async`).
  - Optimize styling: inline critical CSS, defer non-critical CSS, and minimize layout shifts (CLS).
- **Media & Assets:**
  - Optimize images (WebP/AVIF, responsive `srcset`, explicit dimensions, `loading="lazy"`).
  - Serve assets via CDN when applicable.
- **Caching Strategy:**
  - Implement HTTP cache-control headers, stale-while-revalidate, and service-worker strategies.
