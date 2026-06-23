# OwnPay Core - Adversarial Audit Executive Summary

## 1. Audit Scope & Context
An adversarial security audit was performed against the OwnPay self-hosted payment gateway (PHP 8.2+). The audit scope encompassed six rigorous domains: Core Sovereign Boundaries, Ledger Integrity, Plugin Sandboxing, MFS/SMS Verification parsing, Checkout/Invoice stability, and Attack Surface Mapping. The audit was conducted from a white-box perspective with full access to the source code, simulating both external threat actors and malicious insiders.

## 2. Release Recommendation
**CONDITIONAL**

OwnPay demonstrates a solid architectural foundation with robust mitigations against common web vulnerabilities (e.g., SSRF protections with DNS rebinding mitigations, constant-time webhook signatures, and strict tenant scoping via `TenantScope`). However, three significant architectural and implementation flaws were identified that undermine the integrity of the authorization and billing models. These must be remediated prior to a production release.

## 3. High-Level Risk Profile
- **Authentication & Privilege Escalation**: A critical privilege escalation vector exists within the mobile device pairing workflow. Low-privileged staff members can exploit an owner-fallback mechanism to issue themselves JWT access tokens inheriting the privileges of a Super Administrator.
- **Data Integrity & Ledger Reliability**: The invoice update mechanism lacks database transaction wrapping, leading to a race condition that can orphan line items and zero out invoice totals if perturbed during execution.
- **Cryptographic & Verification State Confusion**: Several payment gateway adapters (e.g., Alipay, Easypaisa) fail to fail-securely when signature keys are omitted, falling back to a simulated success state if the gateway mode is configured to 'sandbox'. While dependent on merchant misconfiguration, this state confusion bypasses payment verification entirely.

## 4. Key Security Controls Validated
Several high-risk areas were rigorously challenged and successfully survived refutation, confirming the effectiveness of OwnPay's defense-in-depth controls:
- **Double-Entry Ledger Integrity**: The double-entry bookkeeping enforces strict balancing constraints. The transaction state logic prevents double-spending even if underlying cryptographic SMS payloads are replayed.
- **SSRF & DNS Rebinding Mitigation**: The outbound webhook dispatcher correctly pins validated IP addresses to the cURL handler, effectively nullifying DNS Rebinding (TOCTOU) attacks against merchant-configurable endpoints.
- **Cross-Brand Leakage**: Strict `TenantScope` traits and `$merchantId` bindings are ubiquitous throughout the repository layers, effectively sandboxing data across independent brands.
