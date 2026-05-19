<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Repository\AuditLogRepository;
use OwnPay\Service\Admin\AdminSession;

/**
 * Convenience wrapper for audit logging.
 * Captures user context from injected AdminSession.
 */
final class AuditService
{
    private AuditLogRepository $repo;
    private ?AdminSession $session;

    public function __construct(AuditLogRepository $repo, ?AdminSession $session = null)
    {
        $this->repo = $repo;
        $this->session = $session;
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
            $this->session?->activeBrandId() ?? $this->session?->merchantId(),
            $this->session?->userId(),
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
