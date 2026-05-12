<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Event\EventManager;
use OwnPay\Repository\AuditLogRepository;

/**
 * Audit logger â€” structured audit trail for compliance.
 *
 * Fires: audit.log.created
 * Per PCI-DSS: immutable logs, actor attribution, before/after snapshots.
 */
final class AuditLogger
{
    private AuditLogRepository $repo;
    private ?EventManager $events;

    public function __construct(AuditLogRepository $repo, ?EventManager $events = null)
    {
        $this->repo = $repo;
        $this->events = $events;
    }

    /**
     * Log an auditable event.
     *
     * @param int         $merchantId  Tenant ID
     * @param int|null    $userId      Actor user ID (null = system)
     * @param string      $action      Action performed (e.g. 'user.login', 'transaction.created')
     * @param string      $entityType  Entity type (e.g. 'user', 'transaction', 'gateway')
     * @param int|string  $entityId    Entity ID
     * @param array|null  $before      State before change (null for create)
     * @param array|null  $after       State after change (null for delete)
     * @param string|null $ip          IP address
     */
    public function log(
        int $merchantId,
        ?int $userId,
        string $action,
        string $entityType,
        int|string $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null
    ): void {
        $entry = [
            'merchant_id' => $merchantId,
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'before_data' => $before !== null ? json_encode($before) : null,
            'after_data'  => $after !== null ? json_encode($after) : null,
            'ip_address'  => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        $id = $this->repo->record(
            $merchantId,
            $userId,
            $action,
            $entityType,
            (int) $entityId,
            $before,
            $after,
            $ip
        );

        $this->events?->doAction('audit.log.created', $entry);
    }

    /**
     * Log user action (convenience).
     */
    public function userAction(int $merchantId, int $userId, string $action, string $detail = ''): void
    {
        $this->log($merchantId, $userId, $action, 'user', $userId, null, ['detail' => $detail]);
    }

    /**
     * Log system action (no user).
     */
    public function systemAction(int $merchantId, string $action, string $detail = ''): void
    {
        $this->log($merchantId, null, $action, 'system', 0, null, ['detail' => $detail]);
    }
}
