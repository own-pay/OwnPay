<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for paired devices (`op_paired_devices` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages mobile device pairings, JWT tokens fingerprints, AES keys for secure communication,
 * and device statuses.
 *
 * @package OwnPay\Repository
 */
class PairedDeviceRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_paired_devices';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'merchant_id', 'device_id', 'device_name', 'platform',
        'jwt_fingerprint', 'aes_key_encrypted', 'last_heartbeat', 'status',
    ];

    /**
     * Seconds since the last heartbeat within which an active device is considered "online".
     */
    public const ONLINE_THRESHOLD_SECONDS = 180;

    /**
     * Finds a device record by its unique device UUID string.
     *
     * Overrides BaseRepository::findByUuid because this table has a `device_id` column 
     * instead of a standard `uuid` column.
     *
     * @param string $uuid Unique device identifier.
     * @return array<string, mixed>|null The device record, or null if not found.
     */
    public function findByUuid(string $uuid): ?array
    {
        if ($this->tenantId !== null) {
            return $this->db->fetchOne(
                "SELECT * FROM {$this->table} WHERE device_id = :did AND merchant_id = :mid LIMIT 1",
                ['did' => $uuid, 'mid' => $this->tenantId]
            );
        }
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE device_id = :did LIMIT 1",
            ['did' => $uuid]
        );
    }

    /**
     * Finds a device record by device ID under the active tenant context,
     * falling back to the global pool if not found.
     *
     * @param string $deviceId Unique device ID.
     * @return array<string, mixed>|null The device record, or null if not found.
     */
    public function findByDeviceId(string $deviceId): ?array
    {
        if ($this->tenantId !== null) {
            $device = $this->db->fetchOne(
                "SELECT * FROM {$this->table} WHERE device_id = :did AND merchant_id = :mid LIMIT 1",
                ['did' => $deviceId, 'mid' => $this->tenantId]
            );
            if ($device !== null) {
                return $device;
            }
        }
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE device_id = :did AND merchant_id IS NULL LIMIT 1",
            ['did' => $deviceId]
        );
    }

    /**
     * Finds a device record by its JWT fingerprint hash,
     * falling back to the global pool if not found.
     *
     * @param string $hash The JWT fingerprint hash.
     * @param int $merchantId The merchant ID.
     * @return array<string, mixed>|null The device record, or null if not found.
     */
    public function findByFingerprintHash(string $hash, int $merchantId): ?array
    {
        $device = $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE jwt_fingerprint = :hash AND merchant_id = :mid LIMIT 1",
            ['hash' => $hash, 'mid' => $merchantId]
        );
        if ($device !== null) {
            return $device;
        }
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE jwt_fingerprint = :hash AND merchant_id IS NULL LIMIT 1",
            ['hash' => $hash]
        );
    }

    /**
     * Updates the last heartbeat timestamp for a device.
     *
     * @param string $deviceId Unique device ID.
     * @return void
     */
    public function updateHeartbeat(string $deviceId): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET last_heartbeat = NOW() WHERE device_id = :did",
            ['did' => $deviceId]
        );
    }

    /**
     * Revokes device pairing by changing its status.
     *
     * @param int $id The primary key ID of the paired device.
     * @return int Number of affected rows.
     */
    public function revoke(int $id): int
    {
        return $this->updateScoped($id, ['status' => 'revoked']);
    }

    /**
     * Lists active paired devices under the active tenant context.
     *
     * @return array<int, array<string, mixed>> List of active paired devices.
     */
    public function listActive(): array
    {
        if ($this->tenantId === null) {
            return $this->db->fetchAll(
                "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY paired_at DESC"
            );
        }
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND status = 'active' ORDER BY paired_at DESC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Returns the most recently paired ACTIVE device whose paired_at is at or after the given
     * baseline timestamp - used to detect a device that just completed pairing.
     *
     * @param string $since Baseline timestamp (DB datetime, e.g. the OTP-generation time).
     * @return array<string, mixed>|null The newest matching device, or null if none.
     */
    public function findNewestActiveSince(string $since): ?array
    {
        if ($this->tenantId === null) {
            return $this->db->fetchOne(
                "SELECT * FROM {$this->table} WHERE status = 'active' AND paired_at >= :since ORDER BY paired_at DESC LIMIT 1",
                ['since' => $since]
            );
        }
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND status = 'active' AND paired_at >= :since ORDER BY paired_at DESC LIMIT 1",
            ['mid' => $this->requireTenant(), 'since' => $since]
        );
    }

    /**
     * Lists every device for the active tenant (any status) with a derived `online` flag.
     *
     * `online` is computed on the DB clock - an active device whose last heartbeat falls within
     * ONLINE_THRESHOLD_SECONDS. Revoked/inactive devices are always offline (frozen), so the admin
     * live view can show them without their status ever flipping back to connected.
     *
     * @return array<int, array<string, mixed>> Devices, each with an additional integer `online` column.
     */
    public function listWithLiveStatus(): array
    {
        $threshold = (int) self::ONLINE_THRESHOLD_SECONDS;
        $online = "(status = 'active' AND last_heartbeat IS NOT NULL "
            . "AND last_heartbeat >= DATE_SUB(NOW(6), INTERVAL {$threshold} SECOND)) AS online";

        if ($this->tenantId === null) {
            return $this->db->fetchAll(
                "SELECT *, {$online} FROM {$this->table} ORDER BY paired_at DESC"
            );
        }
        return $this->db->fetchAll(
            "SELECT *, {$online} FROM {$this->table} WHERE merchant_id = :mid ORDER BY paired_at DESC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Lists the devices a specific BRAND should see - its own devices PLUS the global "All Brands"
     * (platform) devices, which serve every brand - each with the derived `online` flag. This is why a
     * device paired under All Brands also appears under each brand (issue #3). The platform id varies per
     * database, so the caller resolves it via BrandContext::getPlatformId() and passes it in.
     *
     * @param int $brandId    The brand's own merchant id.
     * @param int $platformId The reserved All-Brands (platform) merchant id.
     * @return array<int, array<string, mixed>> Devices, each with an additional integer `online` column.
     */
    public function listWithLiveStatusForBrand(int $brandId, int $platformId): array
    {
        $threshold = (int) self::ONLINE_THRESHOLD_SECONDS;
        $online = "(status = 'active' AND last_heartbeat IS NOT NULL "
            . "AND last_heartbeat >= DATE_SUB(NOW(6), INTERVAL {$threshold} SECOND)) AS online";

        return $this->db->fetchAll(
            "SELECT *, {$online} FROM {$this->table} WHERE merchant_id = :mid OR merchant_id = :pid ORDER BY paired_at DESC",
            ['mid' => $brandId, 'pid' => $platformId]
        );
    }

    /**
     * Lists all paired devices associated with the merchant.
     *
     * @return array<int, array<string, mixed>> List of all paired devices.
     */
    public function listAllForMerchant(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid ORDER BY paired_at DESC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Checks if a device is active.
     *
     * @param string $deviceId Unique device ID.
     * @return bool True if active, false otherwise.
     */
    public function isActive(string $deviceId): bool
    {
        $device = $this->findByUuid($deviceId);
        return $device !== null && ($device['status'] ?? '') === 'active';
    }
}

