# Security Policy

OwnPay processes real financial transactions and stores sensitive data. We take security seriously and deeply appreciate the work of security researchers and the community in keeping OwnPay and its users safe.

---

## Supported Versions

OwnPay is currently in **Public Beta**. Security fixes are provided for the latest released version.

| Version | Supported |
|:---|:---:|
| `0.1.x` (current beta) | ✅ |
| `< 0.1.0` (pre-release) | ❌ |

Always run the latest tagged release. Once `1.0` ships, this table will track the stable release line.

---

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues, discussions, or pull requests.**

Instead, report privately through either of these channels:

- **Email:** **[security@ownpay.org](mailto:security@ownpay.org)** *(preferred)*
- **GitHub Security Advisories:** [Report a vulnerability](https://github.com/own-pay/OwnPay/security/advisories/new) (private to maintainers)

To help us triage quickly, please include as much of the following as you can:

- A description of the vulnerability and its potential impact.
- The component, file, or endpoint affected (and version/commit).
- Step-by-step reproduction instructions or a proof-of-concept.
- Any logs, screenshots, or payloads that demonstrate the issue.
- Your suggested remediation, if you have one.

> If you need to share sensitive details, mention it in your first email and we will arrange an encrypted channel.

---

## Our Commitment

When you report a vulnerability responsibly, we commit to:

| Stage | Target |
|:---|:---|
| **Acknowledgement** | Within **48 hours** |
| **Initial assessment & triage** | Within **5 business days** |
| **Status updates** | At least every **7 days** until resolved |
| **Fix & disclosure** | Coordinated with you, prioritized by severity |

We will keep you informed throughout, credit you in the release notes and security advisory (unless you prefer to remain anonymous), and let you know when a fix is published.

---

## Coordinated Disclosure

We follow a **coordinated disclosure** model:

1. You report the issue privately.
2. We confirm, assess severity, and develop a fix.
3. We release the fix and publish a security advisory.
4. Public disclosure happens **after** users have had a reasonable window to update.

Please give us a reasonable opportunity to address the issue before any public disclosure. We will never take legal action against researchers who act in good faith and follow this policy.

---

## Scope

**In scope:** the OwnPay core platform in this repository — including the kernel, controllers, middleware, repositories, services, API layers, gateway bridge, plugin sandbox, and the self-update mechanism.

**Out of scope / report to the relevant party instead:**

- Vulnerabilities in third-party payment gateways or their SDKs.
- Issues caused by misconfiguration of a self-hosted instance (e.g. exposed `.env`, missing HTTPS, weak server credentials, debug mode left on in production).
- Vulnerabilities in dependencies that already have a public CVE and an available patch — please simply update.
- Social engineering, physical attacks, or denial-of-service via raw traffic volume.

---

## Security Best Practices for Operators

If you self-host OwnPay, you are responsible for the security of your server. At minimum:

- Run the **latest release** and apply updates promptly.
- Serve **only** the `public/` directory; keep `.env`, `storage/`, and source out of the web root.
- Enforce **HTTPS** everywhere and set `APP_DEBUG=false` in production.
- Use strong, unique database and admin credentials; keep `APP_KEY`, `ENCRYPTION_KEY`, and `JWT_SECRET` secret and backed up.
- Keep PHP and your database patched.
- Take regular, tested backups.

---

Thank you for helping keep OwnPay and its community secure. 🛡️

---

❤️ Built by the **Community**, for the **Community**.
