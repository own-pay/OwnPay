---
trigger: always_on
---

# Database Schema & Naming Rules

## Table Prefixes

- All MySQL database tables MUST be prefixed with `op_` (e.g., `op_merchants`, `op_transactions`).

## Column Naming Conventions

The database schema defines precise, non-standard column names that MUST be adhered to strictly. AI agents must check these conventions and NEVER use incorrect variants:

| Table | Correct Database Column | Incorrect Columns (DO NOT USE) |
|---|---|---|
| `op_merchant_users` | `two_factor_enabled` | `totp_enabled`, `two_fa_enabled` |
| `op_merchant_users` | `totp_secret_enc` | `totp_secret`, `totp_secret_encrypted` |
| `op_currencies` | `decimal_places` | `decimals`, `decimal` |
| `op_exchange_rates` | `base_currency` | `from_currency`, `currency_from` |
| `op_exchange_rates` | `target_currency` | `to_currency`, `currency_to` |
| `op_sms_parsed` | `device_id` | `device_uuid`, `paired_device_id` |
| `op_sms_parsed` | `match_status` | `status` (when querying/filtering parsed SMS matches) |
| `op_ledger_entries` | `type` | `entry_type` |
| `op_ledger_accounts` | `type` | `account_type` |

## Indexing & Stored Generated Columns

- JSON extraction is slow for querying hot database fields. Hot transaction details (such as `invoice_id` or `payment_link_id` in `op_transactions`) MUST be mapped to **Stored Generated Columns** (e.g. `GENERATED ALWAYS AS (...) STORED`).
- Create and use matching indices (such as `idx_invoice_id` and `idx_payment_link_id`) to accelerate query lookups.

## Platform Scope & Auth Tables

- `op_merchants.is_platform` (`TINYINT(1) NOT NULL DEFAULT 0`, migration `013_add_platform_owner.sql`):
  marks the single reserved "All Brands" platform-owner row. Resolve it via
  `BrandContext::getPlatformId()` - the id varies per database, so NEVER hard-code it. Exclude
  `is_platform = 1` from brand listings / the brand switcher.
- `op_password_resets` (migration `015_create_password_resets.sql`): single-use, time-limited
  self-service password-reset tokens for `op_merchant_users`. Store ONLY the SHA-256 `token_hash`
  (CHAR(64), `UNIQUE`) - never the plaintext token. Columns: `user_id` (FK → `op_merchant_users`,
  `ON DELETE CASCADE`), `expires_at`, `used_at` (NULL until consumed), `created_at`. Freshness checks
  use the DB clock (`expires_at > NOW(6)`).

## Decommissioned Tables & Systems

- The settlement payout tables `op_settlements` and `op_settlement_items` are **decommissioned and purged**. Never query or bind them.
- The SQLite legacy database settings table `op_env` is **decommissioned and purged**. All settings must persist via `op_system_settings`.
