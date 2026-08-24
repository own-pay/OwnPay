<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for single-use, time-limited password-reset tokens (`op_password_resets`).
 *
 * Only a SHA-256 hash of the emailed token is stored, so a database read never yields a usable
 * reset link. Tokens are single-use (used_at) and expire (expires_at). All time comparisons use
 * the database clock (NOW(6)) to avoid PHP/DB clock skew.
 *
 * @package OwnPay\Repository
 */
final class PasswordResetRepository extends BaseRepository
{
    /**
     * @var string Database table name.
     */
    protected string $table = 'op_password_resets';

    /**
     * @var int Token lifetime in seconds (1 hour). Inlined into SQL as a trusted integer constant.
     */
    public const TTL_SECONDS = 3600;

    /**
     * Stores a new reset token hash for a user, expiring TTL_SECONDS from now.
     *
     * @param int $userId The target user's primary key ID.
     * @param string $tokenHash SHA-256 hex hash of the emailed plaintext token.
     * @return void
     */
    public function createToken(int $userId, string $tokenHash): void
    {
        $ttl = self::TTL_SECONDS; // trusted constant - safe to inline (placeholders are unreliable inside INTERVAL)
        $this->db->execute(
            "INSERT INTO {$this->table} (user_id, token_hash, expires_at)
             VALUES (:uid, :hash, DATE_ADD(NOW(6), INTERVAL {$ttl} SECOND))",
            ['uid' => $userId, 'hash' => $tokenHash]
        );
    }

    /**
     * Finds a usable token record by its hash: unused and not yet expired.
     *
     * @param string $tokenHash SHA-256 hex hash to look up.
     * @return array<string, mixed>|null The token record, or null if none is valid.
     */
    public function findValidByHash(string $tokenHash): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table}
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW(6)
             LIMIT 1",
            ['hash' => $tokenHash]
        );
    }

    /**
     * Marks a single token record as used (consumes it).
     *
     * @param int $id The token record primary key ID.
     * @return void
     */
    public function markUsed(int $id): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET used_at = NOW(6) WHERE id = :id AND used_at IS NULL",
            ['id' => $id]
        );
    }

    /**
     * Atomically claims a valid token by its hash, returning the record
     * when this call wins the concurrency race.
     *
     * This is the single point of concurrency control for the password-reset
     * flow. The atomic `UPDATE ... WHERE used_at IS NULL` is the arbiter:
     * under InnoDB's default REPEATABLE READ isolation, two concurrent
     * UPDATEs targeting the same row are serialised by the row-level X lock.
     * The first transaction's UPDATE sets `used_at` and commits; the second
     * transaction's UPDATE then re-evaluates the `WHERE used_at IS NULL`
     * predicate, finds it false, and matches 0 rows. The caller treats
     * `null` as "token already consumed / expired / unknown" and refuses
     * the reset.
     *
     * Replaces the prior `findValidByHash()` + later `markUsed()` pattern,
     * which left a read-then-write window in which two parallel requests
     * could both pass the validity check, both change the password, and
     * both send a "password changed" notification from a single token
     * issuance.
     *
     * Must be called inside a transaction so that a subsequent failure
     * (e.g. password hash update) rolls back the claim and leaves the
     * token available for a retry.
     *
     * @param string $tokenHash SHA-256 hex hash of the emailed plaintext token.
     * @return array<string, mixed>|null The claimed token record, or null when
     *     the token was already consumed, expired, or never existed.
     */
    public function claimByHash(string $tokenHash): ?array
    {
        $record = $this->db->fetchOne(
            "SELECT * FROM {$this->table}
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW(6)
             LIMIT 1",
            ['hash' => $tokenHash]
        );
        if ($record === null) {
            return null;
        }

        $id = is_scalar($record['id'] ?? null) ? (int) $record['id'] : 0;
        if ($id <= 0) {
            return null;
        }

        // Atomic claim - this is the concurrency arbiter. If a parallel
        // request has already claimed this row, our UPDATE matches 0 rows
        // and we treat the token as consumed.
        $stmt = $this->db->execute(
            "UPDATE {$this->table} SET used_at = NOW(6)
             WHERE id = :id AND used_at IS NULL AND expires_at > NOW(6)",
            ['id' => $id]
        );
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        return $record;
    }

    /**
     * Releases a previously-claimed token, restoring it to the usable pool.
     *
     * Used by the rollback path of the reset flow when a claimed token
     * could not be followed through to a successful password change (e.g.
     * the password update itself failed). Without this, a transient DB
     * error after the claim would burn the token and force the user to
     * request a fresh reset link.
     *
     * @param int $id The token record primary key ID.
     * @return void
     */
    public function releaseClaim(int $id): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET used_at = NULL WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Invalidates all of a user's outstanding (unused) tokens.
     *
     * Called when a new reset is requested (one active link at a time) and again after a successful
     * reset (so a second leaked link cannot be reused).
     *
     * @param int $userId The user's primary key ID.
     * @return void
     */
    public function invalidateForUser(int $userId): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET used_at = NOW(6) WHERE user_id = :uid AND used_at IS NULL",
            ['uid' => $userId]
        );
    }
}
