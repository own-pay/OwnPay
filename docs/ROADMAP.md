# OwnPay Roadmap

This roadmap shows where OwnPay is headed on the path from **Public Beta v0.1.0** to a stable **1.0** and beyond. It's a living document — priorities shift with community feedback, and dates are intentionally avoided in favor of a quality-driven "ship when it's ready" approach.

> Have an idea or a priority you care about? Open a [discussion](https://github.com/own-pay/OwnPay/discussions) or [feature request](https://github.com/own-pay/OwnPay/issues). The roadmap is shaped by the people who run OwnPay.

**Legend:** ✅ Shipped &nbsp;·&nbsp; 🚧 In Progress &nbsp;·&nbsp; 🔭 Planned

---

## ✅ Shipped — Public Beta v0.1.0

The foundation is live and self-hostable today:

- ✅ Multi-brand (multi-store) architecture with full tenant isolation
- ✅ 120+ payment gateway integrations (plugin-based)
- ✅ Hosted checkout, payment links, invoices & payment intents
- ✅ Refunds with atomic, balance-validated reversals
- ✅ Double-entry ledger engine (bcmath precision)
- ✅ White-label custom-domain checkout + per-brand theming
- ✅ Sandboxed plugin/add-on system with a domain hook engine
- ✅ REST API (merchant / mobile / admin) + webhooks
- ✅ Mobile companion app foundation (device pairing, JWT, SMS verification)
- ✅ Full internationalization (admin panel + customer checkout)
- ✅ Staff RBAC — granular roles & permissions
- ✅ Signed, atomic, rollback-safe self-update engine
- ✅ Security hardening — AES-256-GCM PII, Argon2id, CSRF, CSP, rate limiting, SSRF guards
- ✅ Quality gates — PHPStan level 9 + automated test suite

---

## 🚧 Now — Stabilizing the Beta (road to 1.0)

Our focus during the beta period:

- 🚧 Real-world feedback loop — triaging issues from beta self-hosters
- 🚧 Public **live demo** at [demo.ownpay.org](https://demo.ownpay.org)
- 🚧 Expanded api documentation at [docs.ownpay.org](https://docs.ownpay.org)
- 🚧 Guides & tutorials at [learn.ownpay.org](https://learn.ownpay.org)
- 🚧 Hardening, edge-case validation, and performance tuning
- 🚧 Broader gateway coverage and gateway-specific test coverage

---

## 🔭 Next — Toward 1.0 Stable

- 🔭 **1.0 stable release** once the quality bar and feedback goals are met
- 🔭 **Plugin marketplace** at [plugin.ownpay.org](https://plugin.ownpay.org) — discover & install gateways and add-ons
- 🔭 **Mobile companion app** general availability
- 🔭 One-click / scripted **deployment tooling** (VPS provisioning, container images)
- 🔭 Enhanced reporting, analytics & reconciliation dashboards
- 🔭 Expanded webhook event catalog and developer SDKs

---

## 🌅 Later — Beyond 1.0

Longer-term directions we're excited about (subject to community demand):

- 🌅 Marketplace ecosystem with third-party plugin publishing
- 🌅 Additional regional payment methods & MFS coverage
- 🌅 Advanced fraud/risk tooling and configurable rules
- 🌅 Broader governance — a distributed maintainer team (see [GOVERNANCE.md](GOVERNANCE.md))

---

## A Note on Timing

OwnPay handles real money. We do **not** commit to calendar dates — features ship when they meet our security and quality bar, not before. Star and watch the repository to be notified the moment new releases drop, and follow [blog.ownpay.org](https://blog.ownpay.org) for release notes.

> This document reflects current intentions, not promises. Anything here may change as we learn from the community.

---

❤️ Built by the **Community**, for the **Community**.
