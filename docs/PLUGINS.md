# Plugin Trust Model & Registry

OwnPay plugins are **full-trust PHP**. A plugin runs with the same rights as the application code
itself: it can read the database, call any service, make network requests, and act as the
authenticated owner of the installation. Permission gating (see below) is a **guardrail against
careless plugins, not a security boundary**. Do not load a plugin you would not run as code on your
server.

## Trust tiers

| Tier | Meaning | How it's established | Default behavior |
| --- | --- | --- | --- |
| Official | Built by OwnPay (core release or signed by the registry owner key) | Package is signed by the registry owner key embedded in `src/Plugin/PluginPackageVerifier.php` `TRUSTED_KEYS` (`ownpay-plugin-registry-v1`) | Activates normally |
| Verified community | Reviewed + signed by the registry (`plugins.ownpay.org`) | Signed by the registry owner key with manifest `tier: "verified"` | Activates normally |
| Unverified | Manual upload or unknown origin, no valid signature | Absence of a valid `signature.sig` | Activates, with a visible warning |

**Unverified plugins are allowed to be installed and activated by default** so that manual
self-hosted installs are not blocked. You can flip this off with an env flag:

```
ALLOW_UNVERIFIED_PLUGINS=false
```

When `ALLOW_UNVERIFIED_PLUGINS=false`, activating an Unverified plugin fails with an error naming
the required registry signature.

## Permission declaration

Every plugin declares the permissions it needs in `manifest.json`:

```json
{
  "name": "My Plugin",
  "slug": "my-plugin",
  "version": "1.2.0",
  "permissions": ["read_sms", "read_ledger"],
  ...
}
```

Known permissions today: `read_sms`, `read_ledger`. Unknown slugs are ignored.
Hooks that carry sensitive payloads are only dispatched to plugins that declared the matching
permission. A plugin that did not declare `read_sms` never receives `mobile.sms.matched`,
`sms.received.before` or `sms.received.after`. A plugin that did not declare `read_ledger` never
receives `ledger.entry.created`.

This is a convenience - it does not stop a plugin that simply implements its own attack.

## Activation warning

When an administrator activates an **Unverified** plugin, the confirmation dialog lists every
permission the plugin requests and labels the plugin **Unverified**. There is no per-permission
tick-box; the warning is a single, explicit acknowledgment of the risks. Official and Verified
plugins activate without this warning.

## Registry key status (0.3.0 bridge release)

`src/Plugin/PluginPackageVerifier.php` now embeds the registry public key, `ownpay-plugin-registry-v1`
(RSA 4096-bit, generated offline by the owner; the public key lives in the repo at
`tools/ownpay-plugin-registry-pub.pem`). The private signing key is held offline by the owner.

Rotation rule: produce the next keypair offline, add the new PEM under a new key id in
`TRUSTED_KEYS`, and keep the old entry until every installed instance has upgraded past 0.3.0
(or drop it once 0.3.0 is retired). Never reuse a retired key id.

## Signature verification

A signed registry package contains, besides the code:

```json
{
  "slug": "bkash-api",
  "version": "1.2.0",
  "issued_at": "2026-11-17T00:00:00+00:00",
  "key_id": "ownpay-plugin-registry-v1",
  "package_sha256": "<hex>",
  "tier": "verified",
  "permissions": ["read_sms"]
}
```

`InstallerService` verifies: the signature, the package hash, the `key_id`, and that the new
version is not a downgrade. A package failing any check is rejected before extraction.

## Revocation

OwnPay instances poll `https://plugins.ownpay.org/revocations.json` once a day
(`PluginRevocationCheckJob`). The feed is a small JSON document:

```json
{ "revoked": ["evil-plugin", "compromised-gateway"] }
```

If the registry revokes a plugin, every installation with it **active** will have it
deactivated within 24h (verified by slug; the feed itself is not authenticated but a faulty or
attacker-supplied list can only cause false-positive **deactivations**, not code execution).

The feed fails closed: if it cannot be fetched or parsed, nothing changes.

## Building and signing packages (for the owner)

```bash
php tools/sign-plugin-package.php <plugin-dir> --version=1.2.0 --slug=my-plugin --tier=verified
```

The directory name must equal the slug, and the directory must contain `manifest.json` (version,
permissions) and the plugin's source. The tool hashes every file except `manifest.json` and
`signature.sig`, writes the manifest in place, signs it with the private key, and writes
`signature.sig` alongside. Re-zip the directory as `<slug>.zip` for the registry.

This tool is for the registry owner who holds the private key (offline). Do not commit the
private key. The owner reviews submissions in the registry, signs the approved ones with this
tool, and `manifest.json` + `signature.sig` inside the package are what official/verified
installations verify at install time. Verify with a dry run against a scratch package before
publishing: `new PluginPackageVerifier()->verifyPackage($dir)` must return the expected
slug/version/tier.

Pre-screening submissions:

```bash
php tools/scan-plugin-package.php path/to/package.zip
```

The scanner reports `.env` reads, direct use of core classes (bypassing EventManager), outbound
network calls and obfuscation (packed base64/eval chains). Flagged packages get human review
before signing.

## Environment variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `ALLOW_UNVERIFIED_PLUGINS` | `true` | Set to `false` to refuse to activate Unverified plugins |

## What OwnPay does NOT promise

- No sandboxing or process isolation for plugins (ever).
- No per-permission consent dialogs beyond the single Unverified-activation warning.
- Plugins are not "vetted" for correctness; only Official/Verified packages carry any registry
  review, and even that is a human review, not a guarantee.
