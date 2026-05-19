# OwnPay Update Server — Quick Start

## Step 1: Set up the server

```bash
# Create directory structure on your server
mkdir -p /var/www/update.ownpay.org/releases/0.1.0
mkdir -p /var/www/update.ownpay.org/keys
```

## Step 2: Create your first release

```bash
# From OwnPay project root
bash docs/update_system/build_release.sh 0.1.0

# Copy files to server
scp build/output/ownpay-0.1.0.zip   server:/var/www/update.ownpay.org/releases/0.1.0/
scp build/output/checksum.sha256     server:/var/www/update.ownpay.org/releases/0.1.0/
```

## Step 3: Add release metadata

Create `releases/0.1.0/release.json`:
```json
{
    "release_date": "2026-05-01",
    "min_php_version": "8.2.0",
    "min_ownpay_version": null,
    "breaking_changes": false
}
```

Create `releases/0.1.0/changelog.md`:
```markdown
## 0.1.0 — Genesis

- Initial release
- Multi-brand payment gateway
- Admin panel with RBAC
```

## Step 4: Generate manifest

```bash
# Copy the generator to server
scp docs/update_system/generate_manifest.php server:/var/www/update.ownpay.org/

# Generate
ssh server "cd /var/www/update.ownpay.org && php generate_manifest.php"
```

## Step 5: Configure Nginx

Use the config from `SERVER_STRUCTURE.md`. Key points:
- HTTPS required
- CORS headers for `manifest.json`
- Cache headers set

## Step 6: Verify

```bash
# Test from any machine
curl -s https://update.ownpay.org/manifest.json | python3 -m json.tool
```

## Publishing a New Release

```bash
# 1. Build
bash docs/update_system/build_release.sh 0.2.0

# 2. Upload
scp build/output/ownpay-0.2.0.zip server:/var/www/update.ownpay.org/releases/0.2.0/
scp build/output/checksum.sha256  server:/var/www/update.ownpay.org/releases/0.2.0/

# 3. Add metadata
# Create releases/0.2.0/release.json and changelog.md

# 4. Add migrations (if any)
mkdir releases/0.2.0/migrations/
cp database/migrations/new_*.sql releases/0.2.0/migrations/

# 5. Regenerate manifest
php generate_manifest.php
```

## Files in this directory

| File | Purpose |
|------|---------|
| `ARCHITECTURE.md` | Full system audit with bugs found |
| `SERVER_STRUCTURE.md` | Exact file tree + JSON schemas + Nginx config |
| `sample_manifest.json` | Working manifest.json template |
| `sample_release.json` | Per-release metadata template |
| `generate_manifest.php` | Auto-generates manifest.json from releases/ |
| `build_release.sh` | Creates release ZIP from project source |
| `QUICKSTART.md` | This file |
