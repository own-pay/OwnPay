<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Repository\AuditLogRepository;

/**
 * Convenience wrapper for audit logging.
 * Auto-captures user context from session.
 */
final class AuditService
{
    private AuditLogRepository $repo;

    public function __construct(AuditLogRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Log an audit event using current session context.
     */
    public function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $this->repo->record(
            $_SESSION['active_brand_id'] ?? $_SESSION['auth_merchant_id'] ?? null,
            $_SESSION['auth_user_id'] ?? null,
            $action,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );
    }
}
