<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use OwnPay\Core\Database;

/**
 * PairedDeviceRepository — CRUD for `op_paired_devices`.
 *
 * Uses direct PDO queries for the new mobile companion tables.
 * Does NOT extend BaseRepository (which is designed for legacy db_prefix tables).
 */
final class PairedDeviceRepository
{
    private const TABLE = 'op_paired_devices';

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    /**
     * Insert a new paired device record.
     *
     * @return int The inserted row ID
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO " . self::TABLE . " (
            device_uuid, brand_id, device_name, fingerprint_hash,
            aes_key_encrypted, refresh_token_hash, refresh_token_expires_at,
            jwt_secret, platform, app_version, last_seen_at, created_at, updated_at
        ) VALUES (
            :device_uuid, :brand_id, :device_name, :fingerprint_hash,
            :aes_key_encrypted, :refresh_token_hash, :refresh_token_expires_at,
            :jwt_secret, :platform, :app_version, NOW(), NOW(), NOW()
        )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':device_uuid'               => $data['device_uuid'],
            ':brand_id'                  => $data['brand_id'],
            ':device_name'               => $data['device_name'],
            ':fingerprint_hash'          => $data['fingerprint_hash'],
            ':aes_key_encrypted'         => $data['aes_key_encrypted'],
            ':refresh_token_hash'        => $data['refresh_token_hash'],
            ':refresh_token_expires_at'  => $data['refresh_token_expires_at'],
            ':jwt_secret'                => $data['jwt_secret'],
            ':platform'                  => $data['platform'] ?? 'android',
            ':app_version'               => $data['app_version'] ?? '',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Find a device by its UUID.
     */
    public function findByUuid(string $deviceUuid): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::TABLE . " WHERE device_uuid = :uuid LIMIT 1"
        );
        $stmt->execute([':uuid' => $deviceUuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Find a device by its refresh token hash.
     */
    public function findByRefreshTokenHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::TABLE . "
             WHERE refresh_token_hash = :hash
               AND revoked_at IS NULL
               AND refresh_token_expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Find a device by fingerprint hash for a given brand.
     */
    public function findByFingerprintHash(string $hash, int $brandId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::TABLE . "
             WHERE fingerprint_hash = :hash AND brand_id = :brand_id AND revoked_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([':hash' => $hash, ':brand_id' => $brandId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List all paired devices for a brand.
     *
     * @return array{devices: array, total: int}
     */
    public function listByBrand(int $brandId, int $limit = 20, int $offset = 0): array
    {
        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM " . self::TABLE . " WHERE brand_id = :bid"
        );
        $countStmt->execute([':bid' => $brandId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT id, device_uuid, device_name, platform, app_version,
                    last_seen_at, revoked_at, created_at
             FROM " . self::TABLE . "
             WHERE brand_id = :bid
             ORDER BY created_at DESC
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':bid', $brandId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'devices' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'total'   => $total,
        ];
    }

    /**
     * Update the refresh token for a device (used during token refresh).
     */
    public function updateRefreshToken(string $deviceUuid, string $newHash, string $expiresAt): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE " . self::TABLE . "
             SET refresh_token_hash = :hash,
                 refresh_token_expires_at = :expires,
                 updated_at = NOW()
             WHERE device_uuid = :uuid AND revoked_at IS NULL"
        );
        return $stmt->execute([
            ':hash'    => $newHash,
            ':expires' => $expiresAt,
            ':uuid'    => $deviceUuid,
        ]);
    }

    /**
     * Update last_seen_at timestamp (called on each authenticated API request).
     */
    public function touchLastSeen(string $deviceUuid): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE " . self::TABLE . " SET last_seen_at = NOW() WHERE device_uuid = :uuid"
        );
        $stmt->execute([':uuid' => $deviceUuid]);
    }

    /**
     * Revoke a device (admin action). Sets revoked_at, invalidating all tokens.
     */
    public function revoke(string $deviceUuid): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE " . self::TABLE . " SET revoked_at = NOW(), updated_at = NOW() WHERE device_uuid = :uuid"
        );
        return $stmt->execute([':uuid' => $deviceUuid]);
    }

    /**
     * Check if a device is active (exists, not revoked).
     */
    public function isActive(string $deviceUuid): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM " . self::TABLE . "
             WHERE device_uuid = :uuid AND revoked_at IS NULL"
        );
        $stmt->execute([':uuid' => $deviceUuid]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Hard delete a device record.
     */
    public function delete(string $deviceUuid): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM " . self::TABLE . " WHERE device_uuid = :uuid"
        );
        return $stmt->execute([':uuid' => $deviceUuid]);
    }
}
