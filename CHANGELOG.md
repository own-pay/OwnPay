# Changelog

All notable changes to OwnPay are documented in this file.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).  
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.2.0] — 2026-07-07

### Added

- Custom themes can now register their own rendering engine (in addition to the built-in template engine), and a brand-scoped Appearance page lets each brand pick its own theme independently.
- Themes going through activation are now scanned for dangerous code patterns before being allowed to run, and unsafe themes are blocked with a clear warning instead of being silently activated.
- Plugins can declare other plugins they depend on; activating one now checks that its dependencies are installed and active first, instead of failing later in a confusing way.
- Checkout pages gained two new customization points: gateways can add their own markup next to their name in the gateway list, and merchants can inject extra form fields into checkout that are submitted along with the payment.
- The admin panel gained a pluggable rendering-engine system and a stylesheet/script "enqueue" system so themes and admin pages can register their own assets cleanly instead of hard-coding tags into templates.
- Required settings fields declared by a plugin are now validated on the server when saved, not just in the browser, closing a gap where a scripted request could bypass the "required" marking entirely.
- API keys can now be temporarily locked (suspended) without fully revoking them, and the admin can see every key's status - active, locked, or revoked - in one list.
- The Developer Hub page was reorganized: Endpoint Reference and Authentication were merged into the API Keys tab, and webhook-related settings were separated out for clarity.
- The Domains page was redesigned with a card-based layout, a tabbed management panel per domain, and a guided 3-step wizard for adding new domains, replacing the old flat table and static instructions.
- Manual (offline) payment methods gained a dedicated payment/account number field, and the customer-facing popup for manual payments was redesigned with the gateway logo, a QR code, and a one-tap copy-amount button.
- Every schema change now ships with a matching upgrade file, so existing installations pick up new database columns automatically on update instead of needing a fresh install.
- After a successful system update, old log files are now automatically cleaned up as part of routine housekeeping.

### Changed

- The setup wizard was rebuilt as a dedicated full-screen experience with a cleaner step tracker, resumable progress, and the option to skip optional steps.
- Finishing or dismissing the setup wizard now always lands the admin on the All Brands view instead of a single brand.
- Logging in now correctly lands superadmins (and any staff granted All-Brands access) on the global All Brands view, instead of always dropping them into a single brand's dashboard.
- Gateway selection on the checkout page now behaves consistently across all payment method tabs, and gateway logos in the list are larger and easier to read.

### Fixed

- Manual gateway checkout popups no longer show a blank $0.00 amount and now correctly display the brand name in the footer.
- Four payment gateway adapters no longer treat an unreachable webhook confirmation as a completed payment ("fail open"); they now correctly wait for a real confirmation.
- Checkout no longer allows two rapid submissions of the same payment to both go through, and returning to a checkout page via the browser back button no longer leaves it in a broken state.
- Fixed a bug where a gateway's resolved currency, amount, and fee could be wrong when processing a payment via a payment intent.
- Invoices no longer show placeholder text in the generated PDF, no longer crash when an invoice has zero line items, and correctly record the paid date.
- Payment links can now be edited from the All-Brands view, no longer return a server error on a duplicate link name, and now properly validate their input.
- Fixed the setup wizard's copy-to-clipboard buttons (OTP and account number) failing silently in some browser contexts.
- A theme's first-time activation no longer incorrectly reports "failed to activate" even though it actually succeeded.
- Removing a domain, toggling a domain's manage panel, and the domain wizard's UI state all had bugs that are now fixed.
- Fixed several PHPStan static-analysis errors representing genuine logic bugs, including an incorrect argument order in the application logger's setup and unreachable validation code.
- Fixed an overly broad version-control ignore rule that was unintentionally also hiding a real source code folder.

### Security

- Payment gateway credentials (API keys, secrets, passwords) are now encrypted at rest instead of being stored in plaintext, and are no longer echoed back into the settings form.
- Closed a gap in the webhook pipeline where gateway identity wasn't fully verified and a server-side request forgery (SSRF) vector was possible.
- Invoice PDF downloads now sanitize the output filename to prevent path traversal.
- The brand Appearance (theme) page now correctly requires the plugin view/manage permission instead of being open to anyone with admin access.

---

## [0.1.0] — 2026-06-30

> **Initial public release.** OwnPay is a self-hosted, enterprise-grade, open-source payment
> gateway platform built on PHP 8.3+. A single owner operates the full system; multiple
> brands (stores) run under isolated custom domains — each with its own gateways, ledger,
> customers, and visual identity.

[0.2.0]: https://github.com/own-pay/ownpay/releases/tag/v0.2.0
