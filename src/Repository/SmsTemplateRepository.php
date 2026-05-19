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

    /**
     * Find active templates whose sender_pattern EXACTLY matches the SMS "From" field.
     *
     * Matching rules:
     *   - Case-sensitive exact string match (BINARY compare) — "bKash" ≠ "bkash"
     *   - Scoped to merchant OR global (merchant_id IS NULL)
     *   - Ordered by priority ASC (lower number = higher priority)
     *
     * @param  string $sender   Exact "From" field as received from mobile app
     * @param  int    $brandId  Merchant/brand ID
     * @return array  Matching template rows, empty if sender not whitelisted
     */
    public function findBySender(string $sender, int $brandId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND (merchant_id IS NULL OR merchant_id = :mid)
               AND BINARY sender_pattern = :sender
             ORDER BY priority ASC",
            ['mid' => $brandId, 'sender' => $sender]
        );
    }

    /**
     * Get all distinct active sender_pattern values for a brand.
     * Used as the mobile app sender whitelist.
     *
     * @param int $brandId
     * @return string[]  e.g. ['bKash', 'AD-NAGAD', '01700000000']
     */
    public function getSenderWhitelist(int $brandId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT sender_pattern FROM {$this->table}
             WHERE status = 'active'
               AND (merchant_id IS NULL OR merchant_id = :mid)
               AND sender_pattern != ''",
            ['mid' => $brandId]
        );
        return array_column($rows, 'sender_pattern');
    }

    // ─── Admin methods ───────────────────────────────────────────

    /**
     * List all templates for merchant (admin page).
     */
    public function listForAdmin(int $merchantId, string $orderBy = 'priority ASC, created_at DESC'): array
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
     * Create a new parsing template.
     */
    public function createTemplate(int $merchantId, array $data): string
    {
        return $this->create([
            'merchant_id'    => $merchantId,
            'gateway_slug'   => $data['gateway_slug'] ?? '',
            'sender_pattern' => $data['sender_pattern'] ?? '',
            'amount_regex'   => $data['amount_regex'] ?? '',
            'trx_id_regex'   => $data['trx_id_regex'] ?? '',
            'sender_regex'   => $data['sender_regex'] ?? '',
            'priority'       => (int) ($data['priority'] ?? 10),
            'status'         => $data['status'] ?? 'active',
        ]);
    }

    /**
     * Update parsing template fields.
     */
    public function updateTemplate(int $id, int $merchantId, array $data): void
    {
        $fields = [];
        $params = ['id' => $id, 'mid' => $merchantId];
        $allowed = ['gateway_slug', 'sender_pattern', 'amount_regex', 'trx_id_regex', 'sender_regex', 'priority', 'status'];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }

        if (empty($fields)) {
            return;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = :id AND merchant_id = :mid";
        $this->db->execute($sql, $params);
    }

    /**
     * Delete a template.
     */
    public function deleteTemplate(int $id, int $merchantId): void
    {
        $this->db->execute(
            "DELETE FROM {$this->table} WHERE id = :id AND merchant_id = :mid",
            ['id' => $id, 'mid' => $merchantId]
        );
    }

    /**
     * Count templates for merchant.
     */
    public function countForMerchant(int $merchantId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM {$this->table} WHERE merchant_id = :mid",
            ['mid' => $merchantId]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * List all active templates (any merchant).
     * Used by Mobile ConfigController for filter rules.
     */
    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY priority ASC"
        );
    }
}
