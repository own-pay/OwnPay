# Findings & Decisions

## Requirements
- Develop 5 new production-ready gateways for Bangladesh localized ecosystems:
  1. **NexusPay (DBBL)**
  2. **CellFin (Islami Bank)**
  3. **Tap (Trust Axiata Pay)**
  4. **OK Wallet (ONE Bank)**
  5. **PortWallet**
- Adhere strictly to OwnPay Genesis v0.1.0 standards.
- 100% type-safe, PHP 8.2+ declare(strict_types=1).
- Constructor dependency injection, NO static service locators.
- **High-Precision Financial Math:** Use PHP `BCMath` library (`bcmul`, `bcdiv`, `bcadd`) with string casting for currency sub-unit/cents conversions to prevent IEEE 754 precision loss.
- **Webhook & Callback Security:** Implement cryptographic verification (HMAC, signature hashing, API check-back validation).
- **Sandbox Simulation Isolation:** Do NOT mock validation logic; check mode and strictly block `SIM_` simulation prefix parameter fallbacks when operating in `live` mode (raise runtime exceptions or fail checks).
- 0 static analysis errors under PHPStan Level 9, 100% unit tests success.

## Research Findings
### 1. PortWallet (PortPos) API v2.0.0
- **Base URLs:**
  - Sandbox: `https://api-sandbox.portwallet.com`
  - Live: `https://api.portwallet.com`
- **Authentication:**
  - `Authorization: Bearer base64(appkey:md5(secretkey + unix_timestamp))`
- **Payment Initiation:**
  - Endpoint: `POST /payment/v2/invoice`
  - Body parameters include: `order` (amount, currency, redirect_url, ipn_url, reference), `billing` (customer name, email, phone).
  - Sub-unit: Uses standard decimal format (e.g. `100.00`).
- **Payment Verification:**
  - Endpoint: `GET /payment/v2/invoice/ipn/@invoice_id/@amount`
  - Auth header: Same bearer format.

### 2. NexusPay (DBBL), CellFin (IBBL), Tap (Trust Axiata Pay), OK Wallet (ONE Bank)
- Regulated MFS systems have proprietary REST/JSON protocols:
  - **Endpoints:** Direct card input / MFS payment gateway API.
  - **Calculations:** BDT amount in decimal or sub-units (using high precision BCMath).
  - **Signatures:** MD5 or SHA256 hashes constructed from `merchantId`, `amount`, `transactionId`, `secretKey`, etc.
  - **Live Isolation:** Enforce sandbox URLs and strict verification; block any test parameters in live environment.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Create dedicated directories under `modules/gateways/` | Conforms to OwnPay modular layout and plugin discovery mechanism. |
| BCMath for all amount formatting | Ensures no floating-point precision loss across MFS networks. |
| MD5/SHA256 signature verification | Secures Instant Payment Notifications (IPN) and return callback loops. |
| Throw exception for `SIM_` prefixes in `live` mode | Prevents bypass vulnerabilities and sandbox spoofing. |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| Regulated banking MFS APIs | Researched common PHP patterns and standard REST implementations for direct corporate integration. |

## Resources
- [PortWallet API Documentation](https://portwallet.com)
- [OwnPay South Asia Integration Handbook](docs/v2/plugins/gateways/volume-2-south-asia.md)
