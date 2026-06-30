<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Support\DateHelper;

/**
 * Repository layer for device pairing tokens (`op_device_pairing_tokens` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages short-lived OTP tokens used in the 5-minute device pairing handshake.
 * OTPs are stored as SHA-256 hashes - raw OTP only lives in the QR code.
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
     * Creates a new pairing token under the active tenant context.
     *
     * @param string $otpHash SHA-256 hash of the generated one-time password token.
     * @param int $createdBy User ID of the administrator generating the pairing token.
     * @param int $ttlSeconds Token lifetime duration in seconds (default is 300).
     * @return string Last inserted primary key ID of the token record.
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
     * Validates and consumes an OTP hash atomically.
     *
     * Performs a SELECT FOR UPDATE to block concurrent handshakes.
     *
     * @param string $otpHash SHA-256 hash of the generated one-time password token.
     * @return array<string, mixed>|null Token database record, or null if validation fails.
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

            $idVal = $token['id'] ?? 0;
            $idInt = is_scalar($idVal) ? (int) $idVal : 0;
            $this->updateScoped($idInt, [
                'is_used' => 1,
                'used_at' => DateHelper::nowMicro(),
            ]);

            return $token;
        });
    }

    /**
     * Counts recent tokens created by an admin (for rate limiting).
     *
     * @param int $createdBy User ID of the administrator context.
     * @param int $windowSeconds Expiration boundary window in seconds (default is 300).
     * @return int Recent tokens count.
     */
    public function countRecentByAdmin(int $createdBy, int $windowSeconds = 300): int
    {
        $since = DateHelper::ago($windowSeconds);

        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM {$this->table}
             WHERE created_by = :admin AND created_at >= :since AND merchant_id = :mid",
            ['admin' => $createdBy, 'since' => $since, 'mid' => $this->requireTenant()]
        );

        $cntVal = is_array($row) ? ($row['cnt'] ?? 0) : 0;
        return is_scalar($cntVal) ? (int) $cntVal : 0;
    }

    /**
     * Purges expired or consumed tokens older than specified age.
     *
     * Global housekeeper task; intentionally unscoped.
     *
     * @param int $olderThanSeconds Cutoff age threshold in seconds (default is 3600).
     * @return int Number of deleted records.
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
