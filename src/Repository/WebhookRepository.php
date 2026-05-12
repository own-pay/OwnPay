<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class WebhookRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_webhooks';
    protected array $fillable = ['merchant_id', 'url', 'secret', 'events', 'status'];

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
