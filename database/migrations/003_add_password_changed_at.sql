-- SEC-4: Track password-changed epoch so existing sessions, JWTs, and API keys
-- issued before a password reset can be invalidated. The column is nullable so
-- existing rows (created before this migration) are treated as "unknown epoch"
-- by the auth layer — the comparison logic in AuthSessionService / JwtAuthMiddleware
-- treats a NULL password_changed_at as "do not enforce", preserving backward
-- compatibility for sessions already active at deploy time.

ALTER TABLE `op_merchant_users`
  ADD COLUMN `password_changed_at` DATETIME(6) NULL DEFAULT NULL AFTER `password_hash`;
