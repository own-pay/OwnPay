<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class AuditLogRepository extends BaseRepository
{
    protected string $table = 'op_audit_logs';
    protected array $fillable = [
        'merchant_id', 'user_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    /**
     * Record audit event. Never use tenant scope â€” audit logs cross-tenant for superadmin.
     */
    public function record(
        ?int $merchantId,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): string {
        return $this->create([
            'merchant_id' => $merchantId,
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => $oldValues !== null ? json_encode($oldValues) : null,
            'new_values'  => $newValues !== null ? json_encode($newValues) : null,
            'ip_address'  => $ip,
            'user_agent'  => $userAgent ? mb_substr($userAgent, 0, 500) : null,
        ]);
    }

    /**
     * Paginated activity listing with user names.
     * @param ?int $merchantId null = global (superadmin)
     */
    public function listPaginated(?int $merchantId, int $limit, int $offset): array
    {
        $where = $merchantId !== null ? 'WHERE l.merchant_id = :mid' : '';
        $params = $merchantId !== null ? ['mid' => $merchantId] : [];

        return $this->db->fetchAll(
            "SELECT l.*, u.name as user_name
             FROM {$this->table} l
             LEFT JOIN op_merchant_users u ON u.id = l.user_id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    /**
     * Count audit logs.
     * @param ?int $merchantId null = global (superadmin)
     */
    public function countFiltered(?int $merchantId): int
    {
        $where = $merchantId !== null ? 'merchant_id = :mid' : '1=1';
        $params = $merchantId !== null ? ['mid' => $merchantId] : [];

        return $this->db->count($this->table, $where, $params);
    }

    /**
     * List audit log entries for a specific entity.
     */
    public function listForEntity(string $entityType, int $entityId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE entity_type = :et AND entity_id = :eid ORDER BY created_at DESC",
            ['et' => $entityType, 'eid' => $entityId]
        );
    }
}
