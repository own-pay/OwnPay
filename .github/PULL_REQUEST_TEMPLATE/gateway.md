# Payment Gateway / Plugin Pull Request

> [!IMPORTANT]
> **Branch Rule**: Pull Requests MUST target the **`dev`** branch.

---

## 🔌 Gateway / Plugin Overview

- **Gateway Name**: 
- **Plugin Slug**: `modules/gateways/<slug>/`
- **Supported Currencies**: `['USD', 'EUR', ...]` (or `[]` for any)
- **Supported Countries / Regions**: 
- **Related Issue**: Closes #<!-- issue number -->

---

## 🛡️ AST Sandbox & Plugin Security Checklist

<!-- All gateway adapters must adhere to strict sandbox constraints: -->

- [ ] **Manifest**: `manifest.json` is present with valid schema, version, capabilities, and CSP connect origins.
- [ ] **Interface**: Adapter implements `OwnPay\Gateway\GatewayAdapterInterface` (or uses `GatewayDefaults` trait).
- [ ] **AST Sandbox Clean**: Code contains **NO** forbidden calls (`exec`, `shell_exec`, `system`, `passthru`, `eval`, backticks, direct raw PDO/database access).
- [ ] **Webhook Verification**: Webhook callbacks cryptographically verify request signatures (e.g. HMAC-SHA256, RSA).
- [ ] **Safe HTTP Requests**: Uses the injected PSR-18 HTTP client or standard safe HTTP dispatchers with timeout limits.
- [ ] **Field Encryption**: API secrets, private keys, and webhooks are marked sensitive for encrypted storage.
- [ ] **Refund Handling**: If supported, `refund()` implements accurate ledger parameter mapping and error handling.

---

## 🧪 Gateway Flow Verification

<!-- Confirm the complete payment lifecycle was verified in sandbox/test mode: -->

- [ ] **Initiation Flow**: Customer checkout successfully redirects or creates payment session.
- [ ] **Callback / Return Flow**: Return URL handles successful and canceled payments gracefully.
- [ ] **Webhook Flow**: Asynchronous webhook handler processes charge completion and idempotency.
- [ ] **Error Handling**: Failed payments and invalid credentials display helpful localized errors.
- [ ] **Static Analysis**: `composer analyse` (PHPStan Level 9) passes with 0 errors.

---

## ⚖️ DCO & License
- [ ] My commits include a **DCO sign-off** (`git commit -s`).
- [ ] I agree to license this work under the **GNU AGPL-3.0 License**.
