<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for customer records (`op_customers` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Handles customer PII (Personally Identifiable Information) data
 * and resolves records using blind indexing/hashed keys (SHA-256) to
 * avoid decrypting fields during lookup.
 */
final class CustomerRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_customers';
    protected array $fillable = [
        'merchant_id', 'uuid', 'name_enc', 'email_enc', 'email_hash',
        'phone_enc', 'phone_hash', 'metadata',
    ];

    /**
     * Finds a customer by their email address hash under the active tenant context.
     *
     * Enables timing-safe and decryption-free customer lookups.
     *
     * @param string $hash SHA-256 hash of the customer's email address.
     * @return array<string, mixed>|null Customer database record, or null if not found.
     */
    public function findByEmailHash(string $hash): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE email_hash = :h AND merchant_id = :mid LIMIT 1",
            ['h' => $hash, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Finds a customer by their phone number hash under the active tenant context.
     *
     * Enables timing-safe and decryption-free customer lookups.
     *
     * @param string $hash SHA-256 hash of the customer's phone number.
     * @return array<string, mixed>|null Customer database record, or null if not found.
     */
    public function findByPhoneHash(string $hash): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE phone_hash = :h AND merchant_id = :mid LIMIT 1",
            ['h' => $hash, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Lists customers with transaction statistics, sorting, and pagination.
     *
     * Performs blind-indexed email hash search if query is provided.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param string $query Optional search query (matches against email hash).
     * @param int $page Page number (1-indexed).
     * @param int $perPage Maximum items per page.
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int} Pagination envelope.
     */
    public function paginateWithStats(?int $merchantId, string $query, int $page, int $perPage): array
    {
        // merchantId === null => global "All Brands" view: aggregate across all brands.
        $conds = [];
        $params = [];
        if ($merchantId !== null) {
            $conds[] = "c.merchant_id = :mid";
            $params['mid'] = $merchantId;
        }
        if ($query !== '') {
            // Email hash lookup only - cannot search encrypted name/phone by plaintext
            $emailHash = hash('sha256', strtolower(trim($query)));
            $conds[] = "c.email_hash = :q";
            $params['q'] = $emailHash;
        }
        $where = $conds !== [] ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $row = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM op_customers c {$where}", $params);
        $cntVal = is_array($row) ? ($row['cnt'] ?? 0) : 0;
        $total = is_scalar($cntVal) ? (int) $cntVal : 0;
        
        $offset = ($page - 1) * $perPage;
        $params['lim'] = $perPage;
        $params['off'] = $offset;

        $items = $this->db->fetchAll(
            "SELECT c.*, COUNT(t.id) as txn_count, COALESCE(SUM(CASE WHEN t.status='completed' THEN t.amount ELSE 0 END),0) as total_spent, t.currency
             FROM op_customers c
             LEFT JOIN op_transactions t ON t.customer_id = c.id
             {$where}
             GROUP BY c.id, t.currency
             ORDER BY c.created_at DESC
             LIMIT :lim OFFSET :off",
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

    /**
     * Lists recent transactions associated with a specific customer.
     *
     * @param int $customerId Primary key identifier of the customer.
     * @param int $merchantId Scoping merchant ID context.
     * @param int $limit Maximum records to return.
     * @return array<int, array<string, mixed>> List of matching transaction records.
     */
    public function getRecentTransactions(int $customerId, int $merchantId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM op_transactions WHERE customer_id = :cid AND merchant_id = :mid ORDER BY created_at DESC LIMIT :lim", 
            ['cid' => $customerId, 'mid' => $merchantId, 'lim' => $limit]
        );
    }

    /**
     * Counts customer records for dashboard metrics.
     *
     * Bypasses tenant scope when merchant ID is null to support superadmin global view.
     *
     * @param int|null $merchantId Scoping merchant ID context, or null for all merchants.
     * @return int Total customers count.
     */
    public function countForDashboard(?int $merchantId): int
    {
        if ($merchantId === null) {
            return $this->db->count($this->table, '1=1');
        }
        return $this->db->count($this->table, 'merchant_id = :mid', ['mid' => $merchantId]);
    }
}
