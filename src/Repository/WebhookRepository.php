<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository class responsible for database operations, persistence, and lookup
 * of merchant webhook configuration records within the 'op_webhooks' table.
 *
 * Provides transactional queries scoped under tenant context (via TenantScope)
 * to prevent unauthorized cross-brand access to webhook endpoint settings.
 */
final class WebhookRepository extends BaseRepository
{
    use TenantScope;

    /**
     * The database table name associated with this repository.
     *
     * @var string
     */
    protected string $table = 'op_webhooks';

    /**
     * The list of columns that are safe to be bulk-filled on insertion or update.
     *
     * @var array<int, string>
     */
    protected array $fillable = ['merchant_id', 'url', 'secret', 'events', 'status'];

    /**
     * Retrieves all active webhook configurations subscribed to a specific event type.
     *
     * Scoped to the active tenant context. Uses MySQL JSON JSON_CONTAINS to verify subscription.
     *
     * @param string $eventType The type code of the event being triggered (e.g. 'payment.completed').
     * @return array<int, array<string, mixed>> List of matching active webhook records.
     * @throws \RuntimeException If the active tenant context cannot be resolved.
     */
    public function listActiveForEvent(string $eventType): array
    {
        $mid = $this->requireTenant();
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE merchant_id = :mid AND status = 'active'
             AND JSON_CONTAINS(events, :evt)",
            ['mid' => $mid, 'evt' => json_encode($eventType)]
        );
    }
}
