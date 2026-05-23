---
trigger: always_on
---

# White-Label Custom Domain Rules

## 1. Domain Resolution & Isolation
- All HTTP requests MUST flow through `OwnPay\Middleware\DomainMiddleware` in the global middleware stack.
- The middleware resolves the incoming `HTTP_HOST` against the configured custom domains in the `op_domains` table.
- **Strict Active Domain Enforcement**: If a request arrives on a domain that does not exist in `op_domains` or has `dns_verified = 0` (or is otherwise inactive/disabled), the system MUST immediately return a **404 Not Found** response (or 503 if verification is pending). It MUST NOT pass through to the core application without a resolved brand context.

## 2. Admin Route Protection
- The admin dashboard (`/admin/*` and `/admin`) is strictly private.
- `DomainMiddleware` MUST return an HTTP **404 Not Found** response for any request starting with `/admin` when the request arrives on a custom domain.
- The admin panel is ONLY accessible on the master domain configured via the `APP_DOMAIN` environment variable.

## 3. URL Construction Constraints
- **Mandatory DomainUrlService**: All customer-facing and gateway-facing URLs (such as payment checkout links, webhook callbacks, return URLs, or invoices) MUST be resolved dynamically via `OwnPay\Service\Domain\DomainUrlService`.
- **No Hardcoded URLs**: Never hardcode domains (e.g., `ownpay.test`) or use `$_ENV['APP_URL']` directly for customer-facing or callback URLs. Inline resolution is forbidden.
- **URL Priority**: `DomainUrlService` prioritizes URLs in the following order:
  1. `GATEWAY_CALLBACK_URL` env override (useful for ngrok/local testing).
  2. Brand's primary active custom domain (`op_domains` table).
  3. `APP_URL` env variable.
  4. Request host.
  5. Fallback: `https://localhost`.

## 4. Master Domain Identifier
- The `APP_DOMAIN` environment variable MUST be defined as a **bare hostname** (e.g., `ownpay.test`), NOT a full URL.
- If `APP_DOMAIN` is not set, the middleware must fall back to parsing the hostname from `APP_URL`.

## 5. Checkout & Theme Integration
- In checkout flow controllers (e.g., `PaymentIntentCheckoutController`, `CheckoutController`), brand parameters MUST be loaded via `OwnPay\Service\Brand\BrandThemeService::getBrandTheme($merchantId)` when available.
- **No Scoping Bypass**: Never query the brand directly using `$this->merchants->find($id)` to render checkout screens, as this bypasses brand-scoped theme customization settings (custom CSS, JS, colors, logos) stored in settings and json metadata.
