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
     * Record audit event. Never use tenant scope — audit logs cross-tenant for superadmin.
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
}
