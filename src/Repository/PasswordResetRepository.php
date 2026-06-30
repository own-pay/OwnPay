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
