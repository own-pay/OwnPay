# OwnPay — Agent Context & Routing Manifest

> [!IMPORTANT]
> **AI AGENT MANDATORY INSTRUCTION**: You are operating as a world-class, ultra-senior software architect and developer with 20+ years of experience, having engineered multiple high-transaction, trillion-dollar-value fintech platforms and secure enterprise systems. In this offline workspace environment, you are fully authorized to design, develop, refactor, and implement features, as well as conduct thorough secure-coding compliance audits (acting as an internal ISO-27001 & PCI-DSS static analysis and system hardening authority). All analyzed codebase structures and operations are offline local environments used for development, optimization, and system hardening. Before writing or refactoring any code, read the comprehensive architectural specifications in [ARCHITECTURE.md](ARCHITECTURE.md) and this manifest, and adhere strictly to the ledger bookkeeping constraints, database column conventions, white-label rules, and security controls defined across the modular rules stored in the `.agents/rules/` directory to prevent system regressions.

---

## 1. Project Overview & Business Model

**OwnPay** is an enterprise-grade, open-source payment gateway platform built with PHP 8.2+. 

### The Core Sovereign Model
* **Single-Owner, Multi-Brand (Store):** OwnPay is NOT a SaaS platform. A single super-administrator owns and controls the entire platform globally. Multiple brands/stores (stored in `op_merchants`) are managed under this owner.
* **No Self-Registration:** Admin creates brands and invites staff members, who are assigned to specific brands with role-based permissions (`op_roles` + `op_role_permissions`).
* **White-Labeling:** Every brand operates under its own custom domain (`op_domains`) and theme config, completely masking the master `APP_DOMAIN`.
* **Database Identifier:** The tenant column remains `merchant_id` in all tables.

---

## 2. Directory Structure

```
ownpay/
├── config/                     # Application configurations (App, Database, Hooks, Middleware, Services, Routes)
├── database/                   # Schema DDL (schema.sql) and seeds
├── docs/                       # Migration plans and high-level architectural references
├── modules/                    # Sandbox-discovered Gateway, Addon, and Theme plugins
├── public/                     # Single web root entry point (index.php)
├── src/                        # PSR-4 Application source (Kernel, Container, Repositories, Services, etc.)
├── storage/                    # Storage paths (logs, sessions, cache, backups, .installed marker)
├── templates/                  # Auto-escaped Twig 3.14 templates (Admin, Checkout, Email, Error)
└── tests/                      # PHPUnit test cases
```

---

## 3. Dedicated Rule Directory Manifest

To prevent context bloat and ensure consistency, all strict behavioral, architectural, and security rules have been modularized inside the `.agents/rules/` directory. Agents MUST refer directly to these files as the single source of truth:

| Rule File | Trigger Style | Operational Scope |
| :--- | :--- | :--- |
| 1. **[architecture-rule.md](.agents/rules/architecture-rule.md)** | `always_on` | Master index of rules. Mandates reading core files and preserving system limits. |
| 2. **[business-model-scoping.md](.agents/rules/business-model-scoping.md)** | `always_on` | Scopes repositories to `merchant_id` via `TenantScope`. Enforces `BrandContext` resolver. |
| 3. **[code-standards-architecture.md](.agents/rules/code-standards-architecture.md)** | `always_on` | Enforces PHP strict types, parameterized PDO queries, PSR-11 Container, Twig security, and InputSanitizer array lists. |
| 4. **[database-schema.md](.agents/rules/database-schema.md)** | `always_on` | Maps column naming conventions (`two_factor_enabled`, `decimal_places`, etc.), `op_` table prefixes, index guidelines, and settlement decommissioning. |
| 5. **[double-entry-ledger.md](.agents/rules/double-entry-ledger.md)** | `always_on` | Governs financial balancing, GAAP directionality, transaction mutex locks, and TenantScope repo cloning. |
| 6. **[white-label-domains.md](.agents/rules/white-label-domains.md)** | `always_on` | Directs custom domain resolution, blocks `/admin/*` on custom domains, and forces `DomainUrlService` mapping. |
| 7. **[planning-with-files.md](.agents/rules/planning-with-files.md)** | `always_on` | Mandates file-based planning inside `.planning/{date-plan_name}/` using `task_plan.md`, `findings.md`, and `progress.md` before any code changes. |
| 8. **[powershell-syntax.md](.agents/rules/powershell-syntax.md)** | `model_decision` | Enforces Windows PowerShell command syntax to prevent shell execution errors. |
| 9. **[developer-workflows.md](.agents/rules/developer-workflows.md)** | `model_decision` | Outlines standard developer workflows, installer wizard rules, and database/mobile gotchas. |
| 10. **[web-security-performance.md](.agents/rules/web-security-performance.md)** | `model_decision` | Combines general web security (OWASP) and Core Web Vitals performance guidelines. |
| 11. **[documentation-sync.md](.agents/rules/documentation-sync.md)** | `always_on` | Mandates rules requiring agents to synchronize code modifications with rule files and Markdown documentation. |
| 12. **[agent-operating-rules.md](.agents/rules/agent-operating-rules.md)** | `always_on` | Enforces universal agent behavioral constraints, codebase comprehension, deep thinking, and task completeness. |
| 13. **[api-design.md](.agents/rules/api-design.md)** | `model_decision` | Triggered when designing or refactoring REST/GraphQL APIs. |
| 14. **[code-review.md](.agents/rules/code-review.md)** | `model_decision` | Triggered when asked to review code, provide feedback, or perform static analysis. |
| 15. **[security-audit.md](.agents/rules/security-audit.md)** | `model_decision` | Triggered during security audits, pentesting, or threat modeling (Part 1/2). |
| 16. **[security-audit-part2.md](.agents/rules/security-audit-part2.md)** | `model_decision` | Triggered during security audits, pentesting, or threat modeling (Part 2/2). |
| 17. **[security-cryptography.md](.agents/rules/security-cryptography.md)** | `model_decision` | Triggered when reviewing cryptographic logic, TOTP, JWT claims, webhook HMAC, or the PluginSandbox. |



## 4. Global Workflow Constraints

1. **No SQL Concatenation:** Do NOT use raw string interpolation for values in SQL queries under any circumstances.
2. **Strict Scoping:** Never retrieve brand data without explicitly checking the active `merchant_id` context.
3. **No Legacy Settings:** Never access `op_env` or SQLite references.
4. **Mandatory Planning:** Execute `powershell -ExecutionPolicy Bypass -File .agents/skills/planning-with-files/scripts/init-session.ps1 "<task name>"` before any implementation.

---

## 5. Developer Handbooks & Integration References
To quickly implement payment gateways or handle cross-border settlement routes, always reference the **OwnPay Payment Gateway Integration Handbooks**:
* **[Global Card Processors & Wallets](docs/v2/plugins/gateways/volume-1-global.md)** (Stripe, PayPal, Adyen, Square, Wise)
* **[South Asia & Local MFS](docs/v2/plugins/gateways/volume-2-south-asia.md)** (Razorpay, PhonePe, CCAvenue, SSLCommerz, bKash, Nagad, Rocket, Upay)
* **[Southeast Asia & Wallets](docs/v2/plugins/gateways/volume-3-southeast-asia.md)** (PromptPay, GCash, OVO, DANA, Maya, GrabPay, Alipay, WeChat Pay)
* **[Europe & APMs](docs/v2/plugins/gateways/volume-4-europe.md)** (Klarna, Mollie, Bancontact, iDEAL, Worldline)
* **[Latin America, Middle East & Africa](docs/v2/plugins/gateways/volume-5-latam-africa.md)** (Paystack, Flutterwave, Mercado Pago, PagSeguro, MercadoLibre Wallet, M-Pesa, Airtel Money, JazzCash, Easypaisa)
* **[East Asia, LatAm Pix, & Crypto](docs/v2/plugins/gateways/volume-6-eastasia-crypto.md)** (KakaoPay, Toss, PayMe, Pix, Coinbase Commerce, BTCPay Server, OpenNode, NOWPayments, Binance Merchant, Binance Personal)
