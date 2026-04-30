<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class SmsTemplateRepository extends BaseRepository
{
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
}
