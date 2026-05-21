<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Event\EventManager;
use OwnPay\Repository\AuditLogRepository;

/**
 * Audit trail logger designed for compliance and transparency.
 *
 * Implements structured compliance-grade logging of auditable system and user actions,
 * capturing before/after snapshots for change tracking, actor attribution, and remote IPs
 * in accordance with PCI-DSS guidelines.
 */
final class AuditLogger
{
    /**
     * @var AuditLogRepository The repository storing audit log entries.
     */
    private AuditLogRepository $repo;

    /**
     * @var EventManager|null System event dispatcher.
     */
    private ?EventManager $events;

    /**
     * AuditLogger constructor.
     *
     * @param AuditLogRepository $repo Repository storing log records.
     * @param EventManager|null $events Optional system-wide event dispatcher.
     */
    public function __construct(AuditLogRepository $repo, ?EventManager $events = null)
    {
        $this->repo = $repo;
        $this->events = $events;
    }

    /**
     * Records an auditable event.
     *
     * Saves action descriptors, target entities, and request metadata, then triggers the
     * `audit.log.created` action hook.
     *
     * @param int $merchantId The tenant brand/merchant ID.
     * @param int|null $userId The unique ID of the acting user (null implies system process).
     * @param string $action The action performed (e.g. 'user.login', 'transaction.completed').
     * @param string $entityType The entity class modified (e.g. 'user', 'transaction').
     * @param int|string $entityId The unique ID of the modified entity.
     * @param array<string, mixed>|null $before Key-value state prior to the action execution.
     * @param array<string, mixed>|null $after Key-value state post-execution.
     * @param string|null $ip The client IP address triggering the request.
     * @return void
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
     * Helper to log user actions with custom detail strings.
     *
     * @param int $merchantId The tenant brand/merchant ID.
     * @param int $userId The unique ID of the user performing the action.
     * @param string $action The action performed.
     * @param string $detail Optional additional descriptive context.
     * @return void
     */
    public function userAction(int $merchantId, int $userId, string $action, string $detail = ''): void
    {
        $this->log($merchantId, $userId, $action, 'user', $userId, null, ['detail' => $detail]);
    }

    /**
     * Helper to log system/automated actions.
     *
     * @param int $merchantId The tenant brand/merchant ID.
     * @param string $action The action performed.
     * @param string $detail Optional additional descriptive context.
     * @return void
     */
    public function systemAction(int $merchantId, string $action, string $detail = ''): void
    {
        $this->log($merchantId, null, $action, 'system', 0, null, ['detail' => $detail]);
    }
}
