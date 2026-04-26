<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;

/**
 * DevicePairingTokenRepository — CRUD for `op_device_pairing_tokens`.
 *
 * Manages short-lived OTP tokens used in the 5-minute device pairing handshake.
 * OTPs are stored as SHA-256 hashes — raw OTP only lives in the QR code.
 */
final class DevicePairingTokenRepository
{
    private const TABLE = 'op_device_pairing_tokens';

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    /**
     * Create a new pairing token.
     *
     * @param string $otpHash   SHA-256 hash of the OTP
     * @param int    $brandId   Brand ID
     * @param int    $createdBy Admin user ID
     * @param int    $ttlSeconds Token lifetime (default: 300 = 5 min)
     * @return int The inserted row ID
     */
    public function create(string $otpHash, int $brandId, int $createdBy, int $ttlSeconds = 300): int
    {
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        $stmt = $this->pdo->prepare(
            "INSERT INTO " . self::TABLE . " (otp_hash, brand_id, created_by, expires_at, created_at)
             VALUES (:otp_hash, :brand_id, :created_by, :expires_at, NOW())"
        );
        $stmt->execute([
            ':otp_hash'   => $otpHash,
            ':brand_id'   => $brandId,
            ':created_by' => $createdBy,
            ':expires_at' => $expiresAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Validate and consume an OTP hash.
     *
     * Checks: exists, not used, not expired.
     * If valid, marks as used atomically.
     *
     * @return array|null Token record if valid, null if invalid/expired/used
     */
    public function validateAndConsume(string $otpHash): ?array
    {
        // Atomic: find + mark used in a transaction
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM " . self::TABLE . "
                 WHERE otp_hash = :hash AND is_used = 0 AND expires_at > NOW()
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([':hash' => $otpHash]);
            $token = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$token) {
                $this->pdo->rollBack();
                return null;
            }

            // Mark as consumed
            $upd = $this->pdo->prepare(
                "UPDATE " . self::TABLE . " SET is_used = 1, used_at = NOW() WHERE id = :id"
            );
            $upd->execute([':id' => $token['id']]);

            $this->pdo->commit();
            return $token;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Count recent tokens created by an admin (for rate limiting).
     *
     * @param int $createdBy Admin user ID
     * @param int $windowSeconds Time window (default: 300 = 5 min)
     * @return int Number of tokens created in the window
     */
    public function countRecentByAdmin(int $createdBy, int $windowSeconds = 300): int
    {
        $since = date('Y-m-d H:i:s', time() - $windowSeconds);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM " . self::TABLE . "
             WHERE created_by = :admin AND created_at >= :since"
        );
        $stmt->execute([':admin' => $createdBy, ':since' => $since]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Purge expired/used tokens older than the specified age.
     *
     * @param int $olderThanSeconds Default: 3600 (1 hour)
     * @return int Number of rows deleted
     */
    public function purgeExpired(int $olderThanSeconds = 3600): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $olderThanSeconds);

        $stmt = $this->pdo->prepare(
            "DELETE FROM " . self::TABLE . "
             WHERE (is_used = 1 OR expires_at < NOW()) AND created_at < :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }
}
