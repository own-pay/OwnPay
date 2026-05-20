# White-Label Multi-Brand Custom Domain Pipeline

> **Version**: 0.1.0 · **Status**: IMPLEMENTED · **Date**: 2026-05-21

## 1. Problem Statement

OwnPay is a sovereign white-labeled fintech engine. The end-customer must **NEVER** see
OwnPay's master domain. Every interaction—API response, checkout room, gateway callback,
status page—must run seamlessly under the merchant's configured custom domain.

**Before**: `checkout_url` → `https://ownpay.test/checkout/intent/{token}` (hardcoded `APP_URL`)
**After**: `checkout_url` → `https://pay.merchantbrand.com/checkout/intent/{token}` (dynamic per-brand)

---

## 2. Design Decisions

| # | Decision | Answer |
|---|----------|--------|
| D1 | TLS/SSL | Infrastructure concern (Apache/Nginx). App resolves domains only. |
| D2 | Admin boundary | `APP_DOMAIN` env = master domain. Admin only accessible there. |
| D3 | checkout_url | Resolve brand's primary custom domain from `op_domains` at response time. |
| D4 | Asset binding | Relative URLs. Browser resolves against `HTTP_HOST`. |
| D5 | Gateway callbacks | Custom domain in production. `GATEWAY_CALLBACK_URL` > custom domain > `APP_URL`. |
| D6 | Admin blocking | `/admin/*` returns 404 on custom domains. |
| D7 | Post-payment | 5-second countdown + redirect to `intent.redirect_url`. Already implemented. |
| D8 | Brand theming | Full per-brand CSS/JS via `BrandThemeService` + `op_system_settings` scoped. |
| D9 | Currency exchange | Auto-convert at checkout via `CurrencyService::convert()` + `op_exchange_rates`. |
| D10 | `APP_DOMAIN` | Explicit hostname in `.env`. Fallback: parse from `APP_URL`. |

---

## 3. URL Resolution Priority Chain

```
DomainUrlService::resolveBaseUrl(merchantId)
    ┌──────────────────────────────────────────────────┐
    │ 1. GATEWAY_CALLBACK_URL env (dev ngrok override) │ → "https://xxxx.ngrok-free.app"
    │ 2. Brand's primary custom domain (op_domains)    │ → "https://pay.brand.com"
    │ 3. APP_URL env                                    │ → "https://ownpay.test"
    │ 4. Request host                                   │ → "https://current.host"
    │ 5. Fallback                                       │ → "https://localhost"
    └──────────────────────────────────────────────────┘
```

---

## 4. Data Model

### `op_domains` (EXISTS — no schema changes)

| Column | Type | Purpose |
|--------|------|---------|
| `domain` | VARCHAR(253) UNIQUE | `pay.merchantbrand.com` |
| `merchant_id` | BIGINT FK | Brand ID |
| `type` | ENUM('checkout','admin','api') | Domain purpose |
| `is_primary` | TINYINT(1) | Primary domain flag |
| `dns_verified` | TINYINT(1) | DNS verification required |
| `status` | ENUM('active','pending','inactive') | Domain lifecycle |

### `op_exchange_rates` (EXISTS — used for currency conversion)

| Column | Type | Purpose |
|--------|------|---------|
| `base_currency` | CHAR(3) | From currency |
| `target_currency` | CHAR(3) | To currency |
| `rate` | DECIMAL(18,8) | Exchange rate |

### `op_system_settings` (EXISTS — brand-scoped theme overrides)

Brand-specific theme settings use `merchant_id` column for scoping.

---

## 5. Request Flow

### 5.1 Payment Initiation (API)

```
Merchant Server → POST /api/v1/payments/initiate
                  ↓
PaymentController → createIntent() → DomainUrlService.buildCheckoutUrl(mid, token)
                  ↓
                  → lookup op_domains WHERE merchant_id=mid AND status=active AND is_primary=1
                  → if found: "https://pay.brand.com/checkout/intent/{token}"
                  → else: "https://{APP_URL}/checkout/intent/{token}"
                  ↓
Response → { checkout_url: "https://pay.brand.com/checkout/intent/{token}" }
```

### 5.2 Checkout Page Load

```
Customer → GET https://pay.brand.com/checkout/intent/{token}
            ↓
DomainMiddleware → Host=pay.brand.com → op_domains → merchant_id=5
            ↓
BrandThemeService → load brand theme (name, logo, colors, custom CSS/JS)
            ↓
Render checkout.twig with brand-specific visual identity
```

### 5.3 Payment with Currency Conversion

```
Customer → clicks "Pay with bKash" → AJAX POST /checkout/intent/{token}/pay
            ↓
Controller → GatewayBridge.getSupportedCurrencies('bkash-api') → ['BDT']
           → Intent currency = 'USD', Gateway needs 'BDT'
           → CurrencyService.convert('100.00', 'USD', 'BDT') → '12000.00'
           → Store original amount in metadata for audit
           → initiatePayment(amount: '12000.00', currency: 'BDT')
            ↓
bKash creates checkout → user pays → redirects to custom domain status page
```

### 5.4 Gateway Callback

```
bKash → redirect to https://pay.brand.com/checkout/intent/{token}/status?paymentID=xxx
            ↓
DomainMiddleware → resolves merchant from custom domain
            ↓
status() → detect callback params → GatewayApiService.handleCallback() → execute bKash API
            ↓
Success → render status page → 5s countdown → redirect to merchant's store
```

---

## 6. Components

### New Files (3)

| File | Purpose |
|------|---------|
| `src/Service/Domain/DomainUrlService.php` | Central URL resolver — all checkout/callback URLs |
| `src/Service/Brand/BrandThemeService.php` | Per-brand visual customization for checkout |
| `docs/v2/model/white-label-domain-pipeline.md` | This architecture document |

### Modified Files (14)

| File | Change |
|------|--------|
| `.env` | Added `APP_DOMAIN=ownpay.test` |
| `.env.example` | Documented `APP_DOMAIN` |
| `src/Middleware/DomainMiddleware.php` | Full rewrite: APP_DOMAIN fallback, admin route blocking |
| `src/Controller/Api/PaymentController.php` | DomainUrlService for checkout_url |
| `src/Controller/Checkout/PaymentIntentCheckoutController.php` | DomainUrlService + currency exchange + theme |
| `src/Controller/Checkout/CheckoutController.php` | DomainUrlService + theme |
| `src/Gateway/GatewayAdapterInterface.php` | Added `supportedCurrencies()` |
| `src/Gateway/GatewayDefaults.php` | Default `supportedCurrencies()` |
| `src/Gateway/GatewayBridge.php` | Added `getSupportedCurrencies()` |
| `modules/gateways/bkash-api/BkashApiGateway.php` | Declares `['BDT']` |
| `modules/gateways/nagad-merchant-api/NagadMerchantApiGateway.php` | Declares `['BDT']` |
| `modules/gateways/sslcommerz/SslCommerzGateway.php` | Declares `['BDT','USD','EUR','GBP','AUD','CAD','SGD']` |
| `config/services.php` | Registered DomainUrlService + BrandThemeService |
| `templates/checkout/checkout.twig` | Brand custom CSS/JS injection |

---

## 7. Security

| Concern | Mitigation |
|---------|------------|
| Admin exposure | `/admin/*` blocked on custom domains → 404 |
| Cross-brand leak | `DomainMiddleware` injects correct `merchant_id`; `TenantScope` enforces |
| DNS spoofing | `dns_verified=1` required; unverified → 503 |
| Currency manipulation | Original amount stored in `metadata.original_amount` for audit |
| Custom CSS XSS | `brand.custom_css` rendered via `|raw` — admin-only input, not user-facing |

---

## 8. Setup for Custom Domains

### DNS Configuration
Merchant points their domain to OwnPay server IP:
```
CNAME pay.merchantbrand.com → ownpay-server.example.com
```

### OwnPay Admin Setup
1. Admin adds domain in **System → Domains** for the brand
2. DNS verification completes automatically (cron checks)
3. SSL configured at web server level (Apache/Nginx)
4. Domain status set to `active`

### Environment
```env
APP_URL=https://ownpay.test
APP_DOMAIN=ownpay.test
GATEWAY_CALLBACK_URL=             # Set to ngrok URL for local dev only
```
