# OwnPay Update Signing (ownpay-update-v2)

This document describes how OwnPay release packages are built, signed and verified,
and what infrastructure each party is responsible for.

## Threat model

An update package is executable code that lands on every installation that applies
it. The signing scheme exists so that no one (not a stolen admin session, not a
compromised release mirror, not a MITM on the admin's laptop) can cause OwnPay to
install code that was not released by the maintainer.

The scheme covers:

- **Transport authenticity** (both paths): the update manifest (`update.ownpay.org/manifest.json`)
  carries per-release `signature`, verified over the downloaded archive before extraction;
  `checksum_sha256` and a host allow-list pin the payload to GitHub/GitHub-Releases/update.ownpay.org.
- **Content chain** (v2): every file in the package is recorded in `version.json` with its
  SHA-256 digest, together with `scheme`, `package`, `issued_at`, `key_id` and `version`.
  The manifest itself is RSA-SHA256-signed. At install time, the applier re-hashes every file:
  any missing, extra or altered file rejects the package.
- **Anti-rollback**: a package whose version is older than the installed version is rejected.
- **Key rotation**: the manifest names `key_id`; the verifier accepts only known keys.
  Rotation = add the new public key as a new `key_id`, ship it, then sign with the new key.

## The two schemes

### v1 (legacy, accepted only for content that is not executable)

`signature.sig` = RSA-SHA256 over `sha256(version.json)`. The payload files are not
authenticated. Accepted only for packages with no executable content (assets, docs).

### ownpay-update-v2 (current; mandatory for executable content)

`version.json` carries:

```json
{
  "scheme": "ownpay-update-v2",
  "version": "0.4.0",
  "package": "ownpay",
  "issued_at": "2026-11-17T10:00:00+00:00",
  "key_id": "ownpay-update-v2-1",
  "files": {
    "src/Kernel.php": "<64 hex>",
    "config/app.php": "<64 hex>"
  }
}
```

`signature.sig` = RSA-SHA256 over `sha256(version.json bytes)`.

Verification order (both appliers: `UpdateService` network updates and `ZipUpdateService`
admin-uploaded ZIPs):

1. Structural checks: unsafe paths, symlinks, allowed directories.
2. Anti-rollback against `Version::CURRENT`.
3. `version.json` parsed; `scheme` detected.
4. Executable content ⇒ `scheme` MUST be `ownpay-update-v2`; `v1` is rejected for such packages.
5. v2: `package` must be `ownpay`, `key_id` must be trusted, every file must be listed with a
   matching digest, and no file may be missing from the manifest.
6. RSA signature over the manifest must verify against the named key.

## The 0.3.0 bridge release

0.3.0 is signed with the v1 scheme and ships:

- the v2 verifier (`src/Update/UpdatePackageVerifier.php`),
- the trust store with both keys registered: `ownpay-update-v1` (legacy, content-only
  packages) and `ownpay-update-v2-1` (v2 signing key),
- the v2 sign tool (`tools/sign-update-package.php`),
- the PHP 8.4 floor (`min_php` in the release manifest).

From the release after 0.3.0, all releases are v2. v1 remains accepted
only for content-only (non-executable) packages.

## Key ceremony (offline; never on a developer laptop)

The signing key is the root of the whole update path. It never touches a machine that
brows the internet or checks out this repository's working copy with credentials.

1. On the offline machine:

   ```bash
   # 4096-bit RSA, encrypted at rest is optional; unencrypted PEM for the CI import path
   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out ownpay-update-v2-1.pem
   chmod 600 ownpay-update-v2-1.pem
   openssl pkey -in ownpay-update-v2-1.pem -pubout -out ownpay-update-v2-1.pub.pem
   ```

2. Verify: `openssl pkey -in ownpay-update-v2-1.pem -text -noout | head` (expect `Private-Key: (4096 bit)`).
3. Send ONLY `ownpay-update-v2-1.pub.pem` to the maintainer. Never send the private key.
4. Import the private key into CI as the release-signing secret (e.g. GitHub Actions `OWNPAY_UPDATE_PRIVATE_KEY`,
   written from a vault or a hardware token), or keep it on the offline machine if releases are signed there.
5. The public key is embedded in the trust store in `src/Update/UpdatePackageVerifier.php`
   (`TRUSTED_KEYS`) under the `ownpay-update-v2-1` key id (embedded since 0.3.0).
6. From the next release on, CI signs with the new key; installs verify against the new key id.

If you prefer an HSM/token, do the same; only step 1 changes (generate inside the token).

## Rotation

1. Generate `ownpay-update-v2-<N+1>` offline using the same steps.
2. PR adds its public key as a new entry; nothing is removed yet.
3. The next release is signed with the new key; installs accept both during the overlap window.
4. After two releases, remove the retired key id from the trust store (or keep it marked
   "legacy, verify-only" if you need to support very old installs).

## Signing a release

1. Build the payload (paths limited to `src/ config/ templates/ public/ database/ modules/`).
2. Move the unsigned zip to the signing machine.
3. Sign:

   ```bash
   php tools/sign-update-package.php ownpay-0.4.0.zip \
       --version=0.4.0 --key-id=ownpay-update-v2-1
   ```

4. Publish the release archive; compute `sha256` of the signed zip and put it in the release
   manifest (`checksum_sha256`), along with `signature` (whole-zip RSA signature for transport
   authenticity, produced by the same pipeline) and `min_php: "8.4.0"`.
5. Confirm a test install of the previous release accepts the 0.4.0 update path end to end
   (staging first), including the rollback path.

## Emergency kill-switch

If a bad package is discovered after publication:

- Sign nothing further with the compromised key id; do not revoke the key in a hurry
  (revoking bricks installs that must update past it).
- Publish the fix as a new release and call it out in the release notes and `#security`.
- For the plugin registry the equivalent mechanism is the revocation list (see
  `docs/PLUGINS.md`); for core updates, use the `update.zip.before`/`update.failed` hooks
  and the emergency disable flag in operations tooling.

## What this does NOT cover

- Compromises of the vendor's own CI (the private key lives there for automated releases).
  Protect it with branch protection, signed tags and required reviewers.
- Client-side TOFU in the mobile app toward the server; see `docs/MOBILE.md`.
- The integrity of the *update.ownpay.org* manifest itself; it is hosted content pinned by
  domain allow-list + whole-zip signature + the v2 content chain.
- Plaintext data in pre-update backups (see `docs/DATA_RETENTION.md`).
