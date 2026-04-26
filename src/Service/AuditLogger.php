<?php

declare(strict_types=1);

namespace OwnPay\Service;

use OwnPay\Repository\AuditLogRepository;

/**
 * AuditLogger — immutable event trail for security and compliance.
 *
 * Every mutation in the system should be logged here:
 * - Who performed the action (actorType + actorId)
 * - What changed (entityType + entityId)
 * - Before/after state (oldPayload + newPayload)
 * - Request context (IP, user agent, request ID)
 */
final class AuditLogger
{
    private AuditLogRepository $repo;

    public function __construct(?AuditLogRepository $repo = null)
    {
        $this->repo = $repo ?? new AuditLogRepository();
    }

    /**
     * Log an audit event.
     *
     * @param int|null $merchantId  Null for system-level events
     * @param string   $action      e.g. 'api_key.created', 'transaction.status_changed'
     * @param string   $entityType  e.g. 'transaction', 'merchant', 'api_key'
     * @param string   $entityId    Public ID (UUID) of the entity
     * @param string   $actorType   'admin', 'api_key', 'system', 'webhook'
     * @param string   $actorId     Identifier of the actor
     * @param array|null $oldPayload Previous state (for updates)
     * @param array|null $newPayload New state (for creates/updates)
     *
     * @return int Auto-increment ID of the log entry
     */
    public function log(
        ?int $merchantId,
        string $action,
        string $entityType,
        string $entityId,
        string $actorType = 'system',
        string $actorId = 'unknown',
        ?array $oldPayload = null,
        ?array $newPayload = null
    ): int {
        // Auto-detect request context
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500)
            : null;

        return $this->repo->log(
            $merchantId,
            $action,
            $entityType,
            $entityId,
            $actorType,
            $actorId,
            $oldPayload,
            $newPayload,
            $requestId,
            $ipAddress,
            $userAgent
        );
    }

    /**
     * Get audit trail for a specific entity.
     */
    public function trailForEntity(string $entityType, string $entityId, int $limit = 50): array
    {
        return $this->repo->findByEntity($entityType, $entityId, $limit);
    }

    /**
     * Get audit trail for a merchant.
     */
    public function trailForMerchant(int $merchantId, int $limit = 100): array
    {
        return $this->repo->findByMerchant($merchantId, $limit);
    }
}
