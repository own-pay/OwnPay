<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class SmsTemplateRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_sms_templates';
    protected array $fillable = [
        'merchant_id', 'gateway_slug', 'sender_pattern', 'amount_regex',
        'trx_id_regex', 'sender_regex', 'priority', 'status',
    ];

    /**
     * Get all active templates ordered by priority (for SMS parser).
     * Global (NULL merchant_id) templates included alongside merchant-specific ones.
     */
    public function listActiveForMerchant(?int $merchantId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE status = 'active' AND (merchant_id IS NULL OR merchant_id = :mid)
             ORDER BY priority ASC",
            ['mid' => $merchantId]
        );
    }

    public function findBySender(string $sender): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE status = 'active' AND :sender REGEXP sender_pattern
             ORDER BY priority ASC",
            ['sender' => $sender]
        );
    }

    // ─── Admin methods ───────────────────────────────────────────

    /**
     * List all templates for merchant (admin page).
     */
    public function listForAdmin(int $merchantId, string $orderBy = 'priority DESC, created_at DESC'): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid ORDER BY {$orderBy}",
            ['mid' => $merchantId]
        );
    }

    /**
     * Find template scoped to merchant.
     */
    public function findForAdmin(int $id, int $merchantId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE id = :id AND merchant_id = :mid LIMIT 1",
            ['id' => $id, 'mid' => $merchantId]
        );
    }

    /**
     * Update template body + enabled status.
     */
    public function updateTemplate(int $id, int $merchantId, string $body, bool $enabled): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET body = :body, enabled = :en WHERE id = :id AND merchant_id = :mid",
            ['body' => $body, 'en' => $enabled ? 1 : 0, 'id' => $id, 'mid' => $merchantId]
        );
    }
}
