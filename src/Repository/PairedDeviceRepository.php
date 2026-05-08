<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class PairedDeviceRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_paired_devices';
    protected array $fillable = [
        'merchant_id', 'device_id', 'device_name', 'platform',
        'jwt_fingerprint', 'last_heartbeat', 'status',
    ];

    public function findByDeviceId(string $deviceId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE device_id = :did AND merchant_id = :mid LIMIT 1",
            ['did' => $deviceId, 'mid' => $this->requireTenant()]
        );
    }

    public function updateHeartbeat(string $deviceId): void
    {
        $this->db->update(
            "UPDATE {$this->table} SET last_heartbeat = NOW() WHERE device_id = :did",
            ['did' => $deviceId]
        );
    }

    public function revoke(int $id): int
    {
        return $this->updateScoped($id, ['status' => 'revoked']);
    }

    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND status = 'active' ORDER BY created_at DESC",
            ['mid' => $this->requireTenant()]
        );
    }
}
