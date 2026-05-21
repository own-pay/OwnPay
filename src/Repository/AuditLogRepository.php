<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for audit logs (`op_audit_logs` table).
 *
 * Keeps track of user actions, modified entity values (both old and new values),
 * user metadata (IP address, user agent), and brand contexts.
 * Unscoped globally to support superadmin views across multiple brands/tenants.
 */
final class AuditLogRepository extends BaseRepository
{
    protected string $table = 'op_audit_logs';
    protected array $fillable = [
        'merchant_id', 'user_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    /**
     * Records a new audit event in the system log.
     *
     * In accordance with compliance, this operation bypasses tenant scoping
     * to ensure audit records remain universally discoverable by superadmins.
     *
     * @param int|null $merchantId Associated merchant identifier, or null if system-wide.
     * @param int|null $userId Associated user identifier, or null if system-triggered.
     * @param string $action The log action key descriptor.
     * @param string|null $entityType The class or database table name of the target entity.
     * @param int|null $entityId The primary key identifier of the target entity.
     * @param array<string, mixed>|null $oldValues Entity attribute values before execution.
     * @param array<string, mixed>|null $newValues Entity attribute values after execution.
     * @param string|null $ip The client IP address executing the operation.
     * @param string|null $userAgent The client browser user agent header.
     * @return string Last inserted primary key ID of the log record.
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
     * Lists audit log records with sorting and pagination, optionally scoped by merchant ID.
     *
     * Joins user profiles to obtain displayable operator names.
     *
     * @param int|null $merchantId Scoping merchant ID context, or null for all merchants.
     * @param int $limit Maximum records to return.
     * @param int $offset Records offset.
     * @return list<array<string, mixed>> List of audit log records.
     */
    public function listPaginated(?int $merchantId, int $limit, int $offset): array
    {
        $where = $merchantId !== null ? 'WHERE l.merchant_id = :mid' : '';
        $params = $merchantId !== null ? ['mid' => $merchantId] : [];
        $params['lim'] = $limit;
        $params['off'] = $offset;

        return $this->db->fetchAll(
            "SELECT l.*, u.name as user_name
             FROM {$this->table} l
             LEFT JOIN op_merchant_users u ON u.id = l.user_id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT :lim OFFSET :off",
            $params
        );
    }

    /**
     * Counts the total audit log records matching criteria.
     *
     * @param int|null $merchantId Scoping merchant ID context, or null for all merchants.
     * @return int Matching records count.
     */
    public function countFiltered(?int $merchantId): int
    {
        $where = $merchantId !== null ? 'merchant_id = :mid' : '1=1';
        $params = $merchantId !== null ? ['mid' => $merchantId] : [];

        return $this->db->count($this->table, $where, $params);
    }

    /**
     * Retrieves all audit log entries associated with a specific entity.
     *
     * @param string $entityType The entity's structural type name.
     * @param int $entityId The primary key identifier of the target entity.
     * @return list<array<string, mixed>> List of matching audit log entries.
     */
    public function listForEntity(string $entityType, int $entityId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE entity_type = :et AND entity_id = :eid ORDER BY created_at DESC",
            ['et' => $entityType, 'eid' => $entityId]
        );
    }
}
