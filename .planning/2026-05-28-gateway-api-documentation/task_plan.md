# Task Plan: OwnPay Gateway API Integration Handbook

## Goal
Create a 100% complete, fully production-ready, multi-volume developer API integration handbook under `docs/v2/plugins/gateways/` for all 45 payment gateways, containing full PHP implementation blueprints, manifest schemas, backchannel verification logic, timing-safe webhook verifications, and refund endpoints as of May 2026.

## Current Phase
Phase 5: Delivery & Walkthrough

## Phases

### Phase 1: Requirements & Discovery
- [x] Read existing Stripe gateway implementation to verify OwnPay gateway architectural conventions.
- [x] Study `GatewayAdapterInterface` and `GatewayDefaults` traits to establish the standard interface contract.
- [x] Group all 45 gateways logically into 6 regions/volumes for modular, structured documentation.
- **Status:** complete

### Phase 2: Planning & Structure
- [x] Establish the document design system: harmonic colors, Alerts, full PHP codeblocks without place-holders, and strict schemas.
- [x] Define the exact `manifest.json` settings schema and `GatewayAdapterInterface` structure for each volume.
- **Status:** complete

### Phase 3: Implementation
- [x] **Volume 1: Global Card Processors & Wallets** (Stripe, PayPal, Adyen, Square, Wise)
- [x] **Volume 2: South Asia & Local MFS** (Razorpay, PhonePe, CCAvenue, SSLCommerz, bKash, Nagad, Rocket, Upay)
- [x] **Volume 3: Southeast Asia & Wallets** (PromptPay, GCash, OVO, DANA, Maya, GrabPay, Alipay, WeChat Pay)
- [x] **Volume 4: Europe & APMs** (Klarna, Mollie, Bancontact, iDEAL, Worldline)
- [x] **Volume 5: Latin America, Middle East & Africa** (Paystack, Flutterwave, Mercado Pago, PagSeguro, MercadoLibre Wallet, M-Pesa, Airtel Money, JazzCash, Easypaisa)
- [x] **Volume 6: East Asia, LatAm Pix, & Crypto** (KakaoPay, Toss, PayMe, Pix, Coinbase Commerce, BTCPay Server, OpenNode, NOWPayments, Binance Merchant, Binance Personal)
- **Status:** complete

### Phase 4: Verification & Linting
- [x] Validate the completeness of all 45 gateways (no TODOs or stubs in PHP code).
- [x] Verify that all code blocks use `declare(strict_types=1);` and comply with PCI-DSS timing-safe HMAC checks.
- [x] Update `docs/v2/plugins/developer-guide.md` and `AGENTS.md` to reference the newly created handbook.
- **Status:** complete

### Phase 5: Delivery & Walkthrough
- [x] Create `walkthrough.md` in `.planning` listing the completed volumes.
- [x] Deliver the complete set of premium integration handbooks to the user.
- **Status:** complete

### Phase 6: Gateway Plugins Implementation
- [x] Implement production-ready `manifest.json` and gateway classes for the missing card processors (Adyen, Square, Wise)
- [x] Implement plugins for South Asian gateways & MFS (Razorpay, PhonePe, CCAvenue, Rocket, Upay)
- [x] Implement plugins for Southeast Asian wallets (PromptPay, GCash, OVO, DANA, Maya, GrabPay, Alipay, WeChat Pay)
- [x] Implement plugins for European gateways (Klarna, Mollie, Bancontact, iDEAL, Worldline)
- [x] Implement plugins for LatAm & Africa gateways (Paystack, Flutterwave, Mercado Pago, PagSeguro, MercadoLibre Wallet, M-Pesa, Airtel Money, JazzCash, Easypaisa)
- [x] Implement plugins for East Asia & Crypto channels (KakaoPay, Toss, PayMe, Pix, Coinbase Commerce, BTCPay Server, OpenNode, Binance Personal)
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Grouping into 6 Volumes | Categorizing 45 gateways by region and type makes the documentation highly structured, scannable, and extremely premium. |
| Zero Placeholders Policy | To ensure production readiness, every gateway's blueprint must feature 100% fully written, functional PHP code. |
| Writing all 38 missing plugins | Fulfills the user's explicit request to have fully functional gateway plugins created in modules/gateways/ rather than just docs. |

## Errors Encountered
| Error | Resolution |
|-------|------------|
