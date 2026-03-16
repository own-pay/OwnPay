<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_audit_logs — immutable audit trail.
 *
 * NOTE: This table is PARTITIONED by created_at and is
 * intentionally immutable — no update() or delete() methods.
 */
class AuditLogRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_audit_logs';

    protected function hasUpdatedAt(): bool
    {
        return false; // Audit logs are immutable — no updated_at
    }

    /**
     * Insert an audit log entry. This is the ONLY write operation.
     */
    public function log(
        ?int $merchantId,
        string $action,
        string $entityType,
        string $entityId,
        string $actorType,
        string $actorId,
        ?array $oldPayload = null,
        ?array $newPayload = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        $now = gmdate('Y-m-d H:i:s.u');

        $this->db->execute(
            "INSERT INTO `{$this->table}`
             (`merchant_id`, `action`, `entity_type`, `entity_id`,
              `actor_type`, `actor_id`, `old_payload`, `new_payload`,
              `request_id`, `ip_address`, `user_agent`, `created_at`)
             VALUES (:mid, :act, :et, :eid, :at, :aid,
                     :old, :new, :rid, :ip, :ua, :ca)",
            [
                'mid' => $merchantId,
                'act' => $action,
                'et' => $entityType,
                'eid' => $entityId,
                'at' => $actorType,
                'aid' => $actorId,
                'old' => $oldPayload !== null ? json_encode($oldPayload) : null,
                'new' => $newPayload !== null ? json_encode($newPayload) : null,
                'rid' => $requestId,
                'ip' => $ipAddress,
                'ua' => $userAgent,
                'ca' => $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find audit trail for a specific entity.
     */
    public function findByEntity(string $entityType, string $entityId, int $limit = 50): array
    {
        return $this->findWhere(
            '`entity_type` = :et AND `entity_id` = :eid',
            ['et' => $entityType, 'eid' => $entityId],
            'created_at DESC',
            $limit
        );
    }

    /**
     * Find audit trail for a merchant.
     */
    public function findByMerchant(int $merchantId, int $limit = 100): array
    {
        return $this->findWhere(
            '`merchant_id` = :mid',
            ['mid' => $merchantId],
            'created_at DESC',
            $limit
        );
    }

    // ─── Disabled mutations ──────────────────────────────────────────

    public function updateById(int $id, array $data): int
    {
        throw new \LogicException('Audit logs are immutable — updates are not allowed.');
    }

    public function delete(int $id): int
    {
        throw new \LogicException('Audit logs are immutable — deletes are not allowed.');
    }

    public function softDelete(int $id): int
    {
        throw new \LogicException('Audit logs are immutable — deletes are not allowed.');
    }
}
