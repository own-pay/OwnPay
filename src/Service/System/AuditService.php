<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Repository\AuditLogRepository;
use OwnPay\Service\Admin\AdminSession;

/**
 * Service providing session-aware wrappers for audit log collection.
 *
 * Automatically captures details (such as current user ID, active merchant brand, IP address,
 * and user agent) from the session and HTTP request scopes, routing data directly to the AuditLogRepository.
 */
final class AuditService
{
    /**
     * @var AuditLogRepository The repository storing audit logs.
     */
    private AuditLogRepository $repo;

    /**
     * @var AdminSession|null Wrapper around active session information.
     */
    private ?AdminSession $session;

    /**
     * AuditService constructor.
     *
     * @param AuditLogRepository $repo The repository for recording log items.
     * @param AdminSession|null $session Session instance, resolving user and merchant contexts.
     */
    public function __construct(AuditLogRepository $repo, ?AdminSession $session = null)
    {
        $this->repo = $repo;
        $this->session = $session;
    }

    /**
     * Logs an audit record with session parameters.
     *
     * Falls back to resolving the brand/merchant context and resolves user attribution.
     *
     * @param string $action The action identifier (e.g. 'settings.update').
     * @param string|null $entityType The entity class being edited.
     * @param int|null $entityId The unique database identifier of the entity.
     * @param array<string, mixed>|null $oldValues Entity state before the action.
     * @param array<string, mixed>|null $newValues Entity state after the action.
     * @return void
     */
    public function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $ipVal = $_SERVER['REMOTE_ADDR'] ?? null;
        $ip = is_scalar($ipVal) ? (string) $ipVal : null;
        $uaVal = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $ua = is_scalar($uaVal) ? (string) $uaVal : null;

        $this->repo->record(
            $this->session?->activeBrandId() ?? $this->session?->merchantId(),
            $this->session?->userId(),
            $action,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            $ip,
            $ua
        );
    }

    /**
     * Checks all logged events for database tampering.
     *
     * @return array<int, array<string, mixed>> List of corrupted audit log rows.
     */
    public function verifyIntegrity(): array
    {
        return $this->repo->verifyIntegrity();
    }

    /**
     * Backports HMAC signatures to old pre-existing logs.
     *
     * @return int Count of signed pre-existing logs.
     */
    public function signExistingLogs(): int
    {
        return $this->repo->signExistingLogs();
    }
}
