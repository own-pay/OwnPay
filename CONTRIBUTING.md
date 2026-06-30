# Contributing to OwnPay

First off — **thank you** for considering a contribution to OwnPay! 🎉 Whether it's a bug report, a new payment gateway, a translation, a documentation fix, or a feature, every contribution helps make self-hosted, open-source payment infrastructure better for everyone.

This guide explains how to get involved effectively.

---

## 📜 Code of Conduct

This project and everyone participating in it is governed by our [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **[ping@ownpay.org](mailto:ping@ownpay.org)**.

---

## 🧭 Ways to Contribute

- **🐛 Report bugs** — open a clear, reproducible [issue](https://github.com/own-pay/OwnPay/issues).
- **💡 Suggest features** — start a [discussion](https://github.com/own-pay/OwnPay/discussions) or feature request.
- **🔌 Build a gateway/plugin** — add a payment gateway or add-on (see below).
- **🌍 Translate** — add or improve a language catalog (see [TRANSLATIONS.md](docs/TRANSLATIONS.md)).
- **📖 Improve docs** — fix typos, clarify guides, expand examples.
- **🧹 Improve code** — fix bugs, add tests, refactor with care.

> Not sure where to start? Look for issues labelled **`good first issue`** or **`help wanted`**.

---

## 🚀 Getting Set Up

1. **Fork** the repository and clone your fork.
2. Follow **[LOCAL_SETUP.md](docs/LOCAL_SETUP.md)** to get a local instance running (~2 minutes).
3. Create a feature branch from `dev` (the integration branch PRs target):
   ```bash
   git checkout dev
   git checkout -b feat/short-description
   ```

**Requirements:** PHP 8.3+ (`bcmath`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `curl`), Composer 2, MySQL 8 / MariaDB 10.4+.

---

## 🔁 Development Workflow

1. Make your changes in a focused branch (one logical change per PR).
2. Add or update tests for any behavior you change.
3. Run the full local check suite (this is what CI runs):
   ```bash
   composer test       # PHPUnit — all tests must pass
   composer analyse    # PHPStan — must stay clean at level 9
   composer lint       # Twig + JS + CSS linting
   ```
4. Commit with a clear message and a **DCO sign-off** (see below).
5. Push to your fork and open a Pull Request against `dev`.

> ✅ **PRs must pass `composer test`, `composer analyse`, and `composer lint` before review.** Green checks get reviewed faster.

---

## 🎯 Coding Standards

OwnPay maintains a high quality bar. Please match the existing code and these rules:

- **Strict types everywhere.** Every PHP file starts with `declare(strict_types=1);` as its first statement (no BOM).
- **PHPStan level 9 must stay green.** Don't suppress errors with baselines, `@phpstan-ignore`, or needless casts — fix the root cause.
- **Money is always bcmath strings, never floats.** Financial correctness is non-negotiable.
- **Tenant scoping is mandatory.** Never run a scoped query without `forTenant()` / `*Scoped()`; use `forAllTenants()` only for deliberate owner-level views.
- **Build URLs via `DomainUrlService`** (`buildCheckoutUrl()`, `buildCallbackUrl()`) — never hardcode a host (white-label domains depend on this).
- **CSRF tokens via `SecurityHelpers::csrfToken()`** — never read `$_SESSION` directly.
- **Prepared statements only.** No string-interpolated SQL, ever.
- **Escape output.** Twig autoescaping stays on; never `|raw` untrusted data.
- **Keep it lean.** No new heavyweight dependencies without discussion; prefer the existing first-party utilities.
- **Comments explain *why*, not *what*.** Document non-obvious decisions.

For the full feature inventory, see the **[Feature Reference](docs/FEATURES.md)**. For the bigger picture, read the **[Architecture Guide](docs/ARCHITECTURE.md)**.

---

## 🔌 Contributing a Payment Gateway or Plugin

Gateways and add-ons are plugins under `modules/`:

1. Create `modules/gateways/<slug>/` with a `manifest.json` (metadata, capabilities, CSP origins, icon).
2. Add an adapter class implementing `OwnPay\Gateway\GatewayAdapterInterface` (use the `GatewayDefaults` trait for no-op defaults).
3. Declare `supportedCurrencies()` accurately (return `[]` for "any currency").
4. Keep the plugin within the sandbox rules — no `exec`, `shell_exec`, `eval`, raw PDO, etc.
5. Test the full flow (initiate → callback → verify → refund) locally.

See the gateway developer guide on **[Gateway Developer Guide](https://learn.ownpay.org/docs/gateway-development-guide)** for the full contract and examples.

---

## 🌍 Contributing Translations

OwnPay has full i18n for both the admin panel and customer checkout. To add or update a language, follow **[docs/TRANSLATIONS.md](docs/TRANSLATIONS.md)** — create a dot-notation JSON catalog (keep `:placeholders` intact) and place core languages in `config/languages/`.

---

## ✍️ Commit Messages & DCO Sign-Off

We use the **[Developer Certificate of Origin](https://developercertificate.org/)** (DCO). By signing off, you certify you have the right to submit the contribution under the project's license.

Add a sign-off line to every commit by using the `-s` flag:

```bash
git commit -s -m "feat(gateway): add Acme Pay adapter"
```

This appends:

```
Signed-off-by: Your Name <your.email@example.com>
```

Please write clear messages. We encourage [Conventional Commits](https://www.conventionalcommits.org/) style (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`), though it isn't strictly enforced.

---

## 📥 Pull Request Guidelines

- Keep PRs focused — one logical change each. Split large work into reviewable pieces.
- Fill out the PR description: **what** changed, **why**, and **how to test** it.
- Link the related issue (e.g. `Closes #123`).
- Update documentation and tests alongside code.
- Be responsive to review feedback — we review with care because this is payment software.
- Ensure all CI checks are green.

---

## ⚖️ Licensing of Contributions

OwnPay is licensed under the **[GNU AGPL-3.0](LICENSE)**. By submitting a contribution, you agree that your work is licensed under the **same AGPL-3.0** license as the project (inbound = outbound), and you certify its origin via your **DCO sign-off**. No separate CLA is required.

---

## 🙏 Thank You

Every issue, PR, translation, and idea moves OwnPay forward. We're building this in the open, for the community — and we're glad you're here.

Questions about contributing? Reach out at **[ping@ownpay.org](mailto:ping@ownpay.org)** or open a [discussion](https://github.com/own-pay/OwnPay/discussions).

---

❤️ Built by the **Community**, for the **Community**.
