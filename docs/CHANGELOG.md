# Changelog

All notable changes to OwnPay are documented in this file.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).  
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.3.0] - Unreleased

### Changed

- OwnPay now requires PHP **8.4 or later**; support for PHP 8.3 hosts is dropped. The
  installer enforces the floor, and `UpdateService`/`ZipUpdateService` refuse any update
  package whose manifest `min_php` is higher than the host running it - so a running 8.3
  install is told to upgrade PHP first instead of being bricked by a 0.3.0 update.

### Security

- SMS bodies submitted by the companion app (`op_sms_parsed.body`) are now encrypted at rest
  with AES-256-GCM under the server key instead of being stored as plaintext. Self-updates
  and fresh installs migrate existing plaintext rows in-place; the migration runs in
  resumable batches and verifies that no plaintext body remains afterwards. Existing
  database backups are NOT retroactively encrypted - they may contain plaintext copies and
  must be protected and rotated accordingly.
- Raw SMS bodies are now gated behind a dedicated `sms.view_raw` permission. The SMS Logs
  page shows sender, amount, TrxID, gateway, status and received time - enough for auditing -
  without the raw content. A per-row "Reveal" action requires the new permission (or Super
  Admin) and writes an audit-log entry recording who looked, when, and which SMS row
  (never the message body itself).
- Update integrity: 0.3.0 ships a new package signature scheme (`ownpay-update-v2`) in which
  the release manifest lists every file and its SHA-256 digest; every file is re-verified
  before extraction and any extra or altered file rejects the package outright. Packages
  containing executable content now require v2; the old scheme is accepted only for
  non-code packages. Package versions older than the installed version are rejected, and
  0.3.0 is the bridge release: signed with the v1 scheme and carrying the v2 verifier and
  everything needed for post-0.3.0 releases to be v2-only.
- Update packages can no longer pass signature verification when openssl verification is
  unavailable; the update aborts instead of silently applying the package.
- Webhook calls to the unified handler are now rate-limited by default (previously relied
  solely on the optional IP allowlist), and a failed merchant-resolution attempt is
  indistinguishable from a signature mismatch - removing a free existence-oracle for
  transaction references.
- Refresh tokens can no longer be rotated by clients that are not in `active` status, refresh
  token reuse is now rejected atomically (the first legitimate rotation wins), and access
  tokens issued at pairing and refresh are now issued with the 15-minute TTL the API claims.
- Log sanitizer now additionally redacts SMS body/encrypted payload fields so SMS content can
  never land in log streams by accident.

### Fixed

- Admin SMS Logs page no longer leaks other tenants' raw bodies to every authenticated admin;
  the listing query never selects the raw body column.
- Refresh-token rotation now validates the token type strictly, closing a path where an
  access token could be exchanged for a fresh 30-day refresh token.

### Added (mobile companion app)

- Android companion app now targets Android 17 (API 37) and ships with the latest stable Flutter/Dart.
- SMS capture buffer is encrypted at rest with a non-extractable Android Keystore key (AES-256-GCM).
  Pre-upgrade plaintext buffers are adopted transparently on first launch after the update.
- Sender whitelist is now synced to the native layer: non-whitelisted senders are dropped before
  ever touching the encrypted buffer. Capture-time drop counts are surfaced in the settings UI.
- TLS pinning for self-signed servers: pairing QR can carry an SPKI fingerprint; the app shows it
  before pairing, stores it pinned to that host, and refuses certs from any other host. If the
  server's cert changes, the app refuses the connection; the settings screen offers
  "Reset connection & re-pair" (keeps queue, audit log and settings) instead of a full wipe.
- Settings screen now warns visibly when the native layer discarded SMS (overflow or whitelist drop).
- Release builds sign with a real upload keystore; a missing keystore.properties now fails fast
  with an actionable error instead of shipping a debug-signed artifact.

### Fixed (mobile companion app)

- SMS buffer at-rest plaintext removed; capture-time overflow evictions now surface a visible counter
  on the settings screen instead of disappearing silently.


### Added

- SMS body retention controls: opt-in daily purge (default off, minimum 1 day) that permanently
  erases raw SMS body columns older than the configured window. The row and its ledger-audit
  columns (amount, TrxID, gateway, match result) are kept. SMS rows awaiting manual review are
  never purged. The setting is overridable per brand, and a warning is shown before saving.
- Update signing v2: the release manifest now carries a per-file SHA-256 digest map, an
  `issued_at` timestamp, the package name and the signing key id. Packages with any file
  missing from the manifest or whose digests do not match are rejected before extraction.
  The v2 signing flow ships with a dedicated sign tool (`tools/sign-update-package.php`).
- `min_php` is now part of the update manifest; self-update refuses to download or apply
  an update whose `min_php` exceeds the host PHP version before any download, backup or
  state change occurs.
- Plugins now carry a trust tier (Official / Verified community / Unverified), declared
  in `manifest.json` together with permission requests. Hooks that carry sensitive data
  (`mobile.sms.matched`, `sms.received.before`, `sms.received.after`, `ledger.entry.created`)
  are dispatched only to plugins that declared the matching permission (`read_sms`,
  `read_ledger`). Activating an Unverified plugin shows a full warning dialog listing every
  permission. The `ALLOW_UNVERIFIED_PLUGINS` env flag (default `true`) can refuse activation
  of Unverified plugins entirely.
- Plugin registry: installed OwnPay instances poll `plugins.ownpay.org/revocations.json` once
  a day. A plugin deactivated by the registry is deactivated locally on the next check.
  The check fails closed (unreachable feed → no change; invalid feed → no change).
- Plugin registry signature verification (`ownpay-plugin-registry-v1`, RSA 4096-bit) is
  embedded and wired end to end; sign packages with `tools/sign-plugin-package.php` on an
  offline machine. Rotation and retirement rules are documented in `docs/PLUGINS.md`.
- The app update channel now trusts `ownpay-update-v2-1` (RSA 4096-bit, held offline by the
  owner) alongside the legacy `ownpay-update-v1` key. Executable packages must carry v2;
  the v1 entry remains only for the 0.3.0 bridge's content-only packages. The v2 public key
  is embedded at the release-cut level and verified round-trip with an offline throwaway
  keypair before the real key was embedded.
- `docs/PLUGINS.md` is the honest trust-model reference: plugins are full-trust PHP;
  permission gating is a guardrail, not a sandbox.
- `docs/MOBILE.md` documents the companion app security model.

---

## [0.2.0] - 2026-07-07

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

## [0.1.0] - 2026-06-30

> **Initial public release.** OwnPay is a self-hosted, enterprise-grade, open-source payment
> gateway platform built on PHP 8.4++. A single owner operates the full system; multiple
> brands (stores) run under isolated custom domains - each with its own gateways, ledger,
> customers, and visual identity.

[0.2.0]: https://github.com/own-pay/ownpay/releases/tag/v0.2.0
