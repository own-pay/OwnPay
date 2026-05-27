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
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND status = 'active' ORDER BY paired_at DESC",
            ['mid' => $this->requireTenant()]
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

