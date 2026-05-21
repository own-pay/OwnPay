<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Support\DateHelper;

/**
 * DevicePairingTokenRepository — CRUD for `op_device_pairing_tokens`.
 *
 * Manages short-lived OTP tokens used in the 5-minute device pairing handshake.
 * OTPs are stored as SHA-256 hashes — raw OTP only lives in the QR code.
 */
final class DevicePairingTokenRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_device_pairing_tokens';
    protected array $fillable = [
        'merchant_id', 'otp_hash', 'created_by',
        'expires_at', 'is_used', 'used_at',
    ];

    /**
     * Create a new pairing token.
     */
    public function createToken(string $otpHash, int $createdBy, int $ttlSeconds = 300): string
    {
        $expiresAt = DateHelper::future($ttlSeconds);

        return $this->createScoped([
            'otp_hash'   => $otpHash,
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Validate and consume an OTP hash atomically.
     * Checks: exists, not used, not expired.
     */
    public function validateAndConsume(string $otpHash): ?array
    {
        $mid = $this->requireTenant();
        $now = DateHelper::nowMicro();

        return $this->db->transaction(function () use ($otpHash, $mid, $now): ?array {
            $token = $this->db->fetchOne(
                "SELECT * FROM {$this->table}
                 WHERE otp_hash = :hash AND is_used = 0 AND expires_at > :now
                   AND merchant_id = :mid
                 LIMIT 1
                 FOR UPDATE",
                ['hash' => $otpHash, 'mid' => $mid, 'now' => $now]
            );

            if ($token === null) {
                return null;
            }

            $this->updateScoped((int) $token['id'], [
                'is_used' => 1,
                'used_at' => DateHelper::nowMicro(),
            ]);

            return $token;
        });
    }

    /**
     * Count recent tokens created by an admin (for rate limiting).
     */
    public function countRecentByAdmin(int $createdBy, int $windowSeconds = 300): int
    {
        $since = DateHelper::ago($windowSeconds);

        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM {$this->table}
             WHERE created_by = :admin AND created_at >= :since AND merchant_id = :mid",
            ['admin' => $createdBy, 'since' => $since, 'mid' => $this->requireTenant()]
        );

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Purge expired/used tokens older than specified age.
     * NOTE: Global housekeeping — no tenant scope.
     */
    public function purgeExpired(int $olderThanSeconds = 3600): int
    {
        $cutoff = DateHelper::ago($olderThanSeconds);

        $stmt = $this->db->execute(
            "DELETE FROM {$this->table}
             WHERE (is_used = 1 OR expires_at < NOW()) AND created_at < :cutoff",
            ['cutoff' => $cutoff]
        );

        return $stmt->rowCount();
    }
}
