# Data Retention & SMS Body Privacy

This document explains what OwnPay stores about SMS notifications from companion devices,
how long it keeps it, and how to erase the raw content when needed.

## What is retained

Raw SMS bodies submitted by the companion app are stored in `op_sms_parsed.body`. Since
0.3.0 this column is **always encrypted at rest** with AES-256-GCM under the server key
(`ENCRYPTION_KEY`), with a `v1:` version prefix so the scheme can rotate later. The
ciphertext is safe to dump; the plaintext is not recoverable without `ENCRYPTION_KEY`.

The following columns stay in plaintext by design, because the matcher and reports need them:

- `sender`, `received_at`, `created_at`
- `amount`, `currency` (implicit), `trx_id`, `gateway_slug`
- `parsed_sender`, `parsed_balance`, `parsed_type`, `parser_type`, `template_id`, `parse_confidence`
- `match_status`, `transaction_id`

## Migration to encrypted storage

- Fresh installs write ciphertext from day one; nothing to migrate.
- Existing installs run a **resumable, idempotent backfill** during the self-update that
  converts every plaintext row to ciphertext. Progress is kept in `op_migrations` and
  `op_system_settings` (`migration.sms_body_encryption.offset`), so an interrupted run
  resumes where it left off. After the pass completes a **verification count** proves that
  no row retains plaintext; the update log records the result.
- If you need to re-run the backfill manually (e.g. after restoring an old backup):

  ```bash
  php tools/reencrypt-sms-bodies.php --dry-run   # report how many plaintext rows remain
  php tools/reencrypt-sms-bodies.php           # encrypt everything in place
  ```

### Backups are not retroactively encrypted

Backups taken **before** this migration ran contain **plaintext SMS bodies**. They cannot
be edited from within the app. Protect them accordingly: encrypt at rest, restrict access,
expire them, and rotate/destroy anything older than your retention needs. Treat any such
backup as containing bank phone numbers, balances, and transaction references.

## Viewing raw SMS in the admin

The SMS Logs page (`/admin/sms-data`) lists rows with **metadata only** - sender, amount,
TrxID, gateway, status, received time. Raw bodies are never rendered without an explicit
reveal. Revealing a row requires the `sms.view_raw` permission (or Super Admin) and writes
an audit-log entry recording **who** looked, **when**, and **which SMS row** - never the
body itself. Users without `sms.view_raw` see a `Restricted` badge instead of any content.

## Retention purge (opt-in)

Admins can enable a **daily purge** in Settings → Optimization → SMS Body Retention.

- **Default: off.** Raw SMS bodies are kept indefinitely unless an admin opts in.
- **Minimum period: 1 day.** When enabling you must enter a number of days (1 or more).
- **Brand-scoped override** is supported: the All-Brands default applies everywhere unless
  a brand sets its own policy, in which case the brand's policy wins.
- The purge erases **only** the raw columns (`body`, `encrypted_raw`). The row itself -
  amount, TrxID, gateway, match status - stays for ledger audit.
- SMS rows in `admin_review` state are **never** purged.
- Enabling is **irreversible** for the affected rows. A warning is shown in the UI before saving.
- Purging does not touch pre-existing backups or the transaction ledger.

## Audit log retention

Admin audit logs are purged by retention too. The default window is **90 days**, and it is
configurable in Settings → Optimization → Purge Logs & Sessions (30 / 60 / 90 / 180 days).
The purge runs on demand from the optimization tab; it also clears old login-attempt rows,
old webhook delivery logs, old communication logs and dead sessions.

## What is never stored encrypted → what is always plaintext

There is no supported configuration that stores SMS bodies in plaintext once this release is
in place for new rows; the only plaintext SMS bodies that can exist are rows written by
pre-0.3.0 code on systems that have not yet run the backfill.
