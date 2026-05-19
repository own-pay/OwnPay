# Update Server File Structure

This documents the exact file tree needed on `update.ownpay.org`.
The client expects these endpoints to exist and return specific JSON schemas.

## Directory Tree

```
update.ownpay.org/
├── manifest.json              ← Main manifest (SystemUpdateJob + UpdateService unified)
├── releases/
│   ├── 0.1.0/
│   │   ├── ownpay-0.1.0.zip  ← Full release package
│   │   ├── checksum.sha256    ← SHA-256 hash of the ZIP
│   │   ├── signature.sig      ← Ed25519 signature (future)
│   │   ├── changelog.md       ← Human-readable changelog
│   │   └── migrations/
│   │       └── (none for initial release)
│   ├── 0.2.0/
│   │   ├── ownpay-0.2.0.zip
│   │   ├── checksum.sha256
│   │   ├── signature.sig
│   │   ├── changelog.md
│   │   └── migrations/
│   │       ├── 001_add_new_table.sql
│   │       └── 002_alter_column.sql
│   └── 0.3.0-beta/
│       ├── ownpay-0.3.0-beta.zip
│       ├── checksum.sha256
│       ├── signature.sig
│       ├── changelog.md
│       └── migrations/
│           └── 001_beta_feature.sql
├── keys/
│   └── ownpay-release.pub     ← Public key for signature verification
└── api/
    └── v1/
        └── check.php          ← Optional dynamic endpoint (can be static JSON)
```

## File Specifications

### `manifest.json` — Primary Endpoint

**URL**: `https://update.ownpay.org/manifest.json`
**Method**: GET
**Query Params**: `?v={current_version}` (optional, for analytics)

This is the SINGLE source of truth. Both `UpdateService::check()` and `SystemUpdateJob::run()` will use this file.

```json
{
    "schema_version": 1,
    "generated_at": "2026-05-20T00:00:00Z",
    "channels": {
        "stable": {
            "latest_version_name": "0.2.0",
            "latest_version_code": "0.2.0",
            "min_php_version": "8.2.0",
            "min_ownpay_version": "0.1.0",
            "download_url": "https://update.ownpay.org/releases/0.2.0/ownpay-0.2.0.zip",
            "checksum_url": "https://update.ownpay.org/releases/0.2.0/checksum.sha256",
            "checksum_sha256": "a1b2c3d4e5f6...64hex...",
            "signature_url": "https://update.ownpay.org/releases/0.2.0/signature.sig",
            "changelog_url": "https://update.ownpay.org/releases/0.2.0/changelog.md",
            "changelog": "## 0.2.0 — 2026-05-20\n\n- Added feature X\n- Fixed bug Y\n- Improved performance Z",
            "release_date": "2026-05-20",
            "size_bytes": 5242880,
            "migrations": [
                "001_add_new_table.sql",
                "002_alter_column.sql"
            ],
            "breaking_changes": false
        },
        "beta": {
            "latest_version_name": "0.3.0-beta",
            "latest_version_code": "0.3.0",
            "min_php_version": "8.2.0",
            "min_ownpay_version": "0.2.0",
            "download_url": "https://update.ownpay.org/releases/0.3.0-beta/ownpay-0.3.0-beta.zip",
            "checksum_url": "https://update.ownpay.org/releases/0.3.0-beta/checksum.sha256",
            "checksum_sha256": "f6e5d4c3b2a1...64hex...",
            "signature_url": "https://update.ownpay.org/releases/0.3.0-beta/signature.sig",
            "changelog_url": "https://update.ownpay.org/releases/0.3.0-beta/changelog.md",
            "changelog": "## 0.3.0-beta — 2026-05-20\n\n- Experimental feature A\n- Preview of feature B",
            "release_date": "2026-05-20",
            "size_bytes": 5500000,
            "migrations": [
                "001_beta_feature.sql"
            ],
            "breaking_changes": true,
            "breaking_notes": "Requires migration from 0.2.0"
        }
    },
    "announcements": [
        {
            "id": "ann-001",
            "type": "info",
            "title": "OwnPay 0.2.0 Released",
            "message": "New stable release with improved gateway support.",
            "url": "https://ownpay.org/blog/0.2.0-release",
            "expires_at": "2026-06-20"
        }
    ],
    "public_key_url": "https://update.ownpay.org/keys/ownpay-release.pub"
}
```

### `releases/{version}/checksum.sha256`

Plain text file. One line: the SHA-256 hex digest of the ZIP file.

```
a1b2c3d4e5f67890abcdef1234567890abcdef1234567890abcdef1234567890  ownpay-0.2.0.zip
```

Generate with:
```bash
sha256sum ownpay-0.2.0.zip > checksum.sha256
```

### `releases/{version}/changelog.md`

Standard markdown changelog:

```markdown
## 0.2.0 — 2026-05-20

### Added
- New payment gateway: Stripe integration
- Customer export API endpoint
- Admin update management panel

### Fixed
- Customer duplicate detection (409 Conflict)
- Gateway error message passthrough
- Health API authentication

### Changed
- Health endpoint now requires Bearer auth
- Update check unified to single manifest.json

### Security
- Package signature verification for updates
- Argon2id password hashing enforced
```

### `releases/{version}/ownpay-0.2.0.zip`

The ZIP package structure must match OwnPay's root directory:

```
ownpay-0.2.0.zip
├── src/                    ← Updated PHP source
│   ├── Controller/
│   ├── Service/
│   ├── Repository/
│   └── ...
├── config/                 ← Updated config files
│   ├── app.php             ← version bumped to 0.2.0
│   ├── services.php
│   ├── routes/
│   └── ...
├── templates/              ← Updated Twig templates
├── public/                 ← Updated public assets
│   ├── assets/
│   └── index.php
├── database/
│   ├── schema.sql          ← Full current schema
│   └── migrations/
│       ├── 001_add_new_table.sql
│       └── 002_alter_column.sql
└── modules/                ← Updated plugin modules
    ├── gateways/
    ├── addons/
    └── themes/
```

**IMPORTANT**: The ZIP must NOT contain:
- `.env` (would overwrite user's config)
- `storage/` (would wipe logs, sessions, backups)
- `vendor/` (user runs `composer install` separately)
- `.git/` or `.github/`
- `tests/` (not needed in production)

### `releases/{version}/migrations/*.sql`

Sequential SQL migration files. Each contains idempotent DDL:

```sql
-- Migration: 001_add_new_table.sql
-- Version: 0.2.0
-- Date: 2026-05-20

CREATE TABLE IF NOT EXISTS `op_new_feature` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `merchant_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_merchant` (`merchant_id`),
    CONSTRAINT `fk_nf_merchant` FOREIGN KEY (`merchant_id`)
        REFERENCES `op_merchants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `keys/ownpay-release.pub`

Ed25519 public key for package signature verification (future implementation):

```
-----BEGIN PUBLIC KEY-----
MCowBQYDK2VwAyEA... (base64 encoded Ed25519 public key)
-----END PUBLIC KEY-----
```

---

## Server Requirements

| Requirement | Details |
|-------------|---------|
| **Web Server** | Nginx or Apache, static file serving |
| **HTTPS** | Required (TLS 1.2+) |
| **CORS** | `Access-Control-Allow-Origin: *` on manifest.json |
| **Content-Type** | `application/json` for .json, `application/zip` for .zip |
| **CDN** | Recommended for release ZIP files |
| **Cache** | manifest.json: `max-age=3600`, releases: `max-age=86400` |

## Nginx Config Example

```nginx
server {
    listen 443 ssl http2;
    server_name update.ownpay.org;

    root /var/www/update.ownpay.org;
    index manifest.json;

    ssl_certificate /etc/ssl/update.ownpay.org/fullchain.pem;
    ssl_certificate_key /etc/ssl/update.ownpay.org/privkey.pem;

    # CORS for all origins (update client runs from any domain)
    add_header Access-Control-Allow-Origin "*" always;
    add_header Access-Control-Allow-Methods "GET, HEAD, OPTIONS" always;

    # Cache manifest (1 hour)
    location = /manifest.json {
        add_header Cache-Control "public, max-age=3600";
        add_header Content-Type "application/json";
    }

    # Cache release files (1 day)
    location /releases/ {
        add_header Cache-Control "public, max-age=86400";
    }

    # Deny directory listing
    autoindex off;

    # Deny dotfiles
    location ~ /\. {
        deny all;
    }
}
```
