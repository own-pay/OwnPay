# OwnPay Update Server — Quick Start

OwnPay features an interactive PHP CLI tool to package, sign, and manifest release updates dynamically.

---

## The PHP CLI Builder (`cli/build-update.php`)

The interactive release packaging script `cli/build-update.php` performs the following steps:
1. **Key Verification**: Ensures update signing key pair (`update_private_key.pem` / `update_public_key.pem`) exists. Prompts to generate a new 2048-bit RSA pair if missing.
2. **Composer Alignment**: Prompts to execute `composer update` to guarantee all vendor packages are up-to-date.
3. **Interactive Metadata Collection**: Collects release version, target channel (`stable`/`beta`), breaking change warnings, PHP version requirements, and changelogs.
4. **Zipping & Isolation**: Bundles files into a ZIP archive, automatically excluding heavy development caches (such as Flutter `mobile_app/` build outputs, tests, system keys, and private parameters).
5. **SHA-256 Integrity Checks**: Computes SHA-256 file hashes.
6. **RSA Cryptographic Signing**: Digitally signs the ZIP archive using `update_private_key.pem` and writes the base64 signature directly to `update/manifest.json`.
7. **Static Dashboard Generation**: Compiles an interactive HTML index page listing all releases dynamically.

---

## Step-by-Step Guide

### Step 1: Set up Your Local Environment & Keys
Ensure you have OpenSSL configured. On the first run, the tool will offer to generate a cryptographic key pair:
```bash
php cli/build-update.php
```

Make sure to copy the output public key and embed it into `src/Update/UpdateService.php` as the `UPDATE_PUBLIC_KEY` constant.

> [!WARNING]
> Keep `update_private_key.pem` strictly private! Never commit it to git or package it in releases.

### Step 2: Build the Release
Run the CLI tool and follow the interactive prompts:
```bash
php cli/build-update.php
```

This will produce the update structure inside the local `update/` directory:
```
update/
├── manifest.json
├── index.html
└── releases/
    └── 0.2.1/
        ├── ownpay-0.2.1.zip
        ├── checksum.sha256
        ├── signature.sig
        └── changelog.md
```

### Step 3: Deploy to the Update Server
Upload the entire contents of the generated `update/` directory directly to your update server's web root (e.g., `/var/www/update.ownpay.org/`).
Uploading new versions does not destroy or overwrite previous releases; they will remain available under `releases/` and registered inside `manifest.json`.

```bash
# Example deployment copy
scp -r update/* user@update.ownpay.org:/var/www/update.ownpay.org/
```

### Step 4: Verify
Verify that the update server is delivering the signed release manifest correctly:
```bash
curl -s https://update.ownpay.org/manifest.json | python3 -m json.tool
```

The manifest will contain the version codes, download links, SHA-256 hashes, and base64 RSA signature parameters used by the client for cryptographic verification.
