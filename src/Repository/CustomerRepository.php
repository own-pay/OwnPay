<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class CustomerRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_customers';
    protected array $fillable = [
        'merchant_id', 'uuid', 'name_enc', 'email_enc', 'email_hash',
        'phone_enc', 'phone_hash', 'metadata',
    ];

    /**
     * Find by email hash (for lookup without decryption).
     */
    public function findByEmailHash(string $hash): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE email_hash = :h AND merchant_id = :mid LIMIT 1",
            ['h' => $hash, 'mid' => $this->requireTenant()]
        );
    }

    public function paginateWithStats(int $merchantId, string $query, int $page, int $perPage): array
    {
        $where = "WHERE c.merchant_id = :mid";
        $params = ['mid' => $merchantId];
        if ($query !== '') {
            // Email hash lookup only — cannot search encrypted name/phone by plaintext
            $emailHash = hash('sha256', strtolower(trim($query)));
            $where .= " AND c.email_hash = :q";
            $params['q'] = $emailHash;
        }

        $total = (int) ($this->db->fetchOne("SELECT COUNT(*) as cnt FROM op_customers c {$where}", $params)['cnt'] ?? 0);
        
        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT c.*, COUNT(t.id) as txn_count, COALESCE(SUM(CASE WHEN t.status='completed' THEN t.amount ELSE 0 END),0) as total_spent, t.currency
             FROM op_customers c
             LEFT JOIN op_transactions t ON t.customer_id = c.id
             {$where}
             GROUP BY c.id
             ORDER BY c.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }

    public function getRecentTransactions(int $customerId, int $merchantId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM op_transactions WHERE customer_id = :cid AND merchant_id = :mid ORDER BY created_at DESC LIMIT {$limit}", 
            ['cid' => $customerId, 'mid' => $merchantId]
        );
    }

    /**
     * Count customers for dashboard (global or merchant-scoped).
     * @param ?int $merchantId null = global (superadmin)
     */
    public function countForDashboard(?int $merchantId): int
    {
        if ($merchantId === null) {
            return $this->db->count($this->table, '1=1');
        }
        return $this->db->count($this->table, 'merchant_id = :mid', ['mid' => $merchantId]);
    }
}
