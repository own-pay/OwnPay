<?php

declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;

/**
 * Repository layer for customer transaction refunds (`op_refunds` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages refund generation and total refunded totals tracking.
 *
 * @package OwnPay\Repository
 */
final class RefundRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_refunds';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'merchant_id', 'transaction_id', 'uuid', 'amount', 'reason',
        'status', 'processed_at',
    ];

    /**
     * Creates a new refund record.
     *
     * Automatically generates a UUIDv4 identifier.
     *
     * @param array<string, mixed> $data Raw refund input fields.
     * @return string The primary key ID of the newly created refund.
     */
    public function createRefund(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        return $this->createScoped($data);
    }

    /**
     * Calculates the total amount refunded for a given transaction.
     *
     * @param int $transactionId Primary key ID of the transaction.
     * @param int $merchantId The merchant brand ID.
     * @return string The total refunded amount as a decimal string.
     */
    public function getTotalRefundedAmount(int $transactionId, int $merchantId): string
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM `op_refunds`
             WHERE transaction_id = :txid AND merchant_id = :mid AND status IN ('pending', 'completed')",
            ['txid' => $transactionId, 'mid' => $merchantId]
        );
        $total = $row['total'] ?? '0.00';
        return is_scalar($total) ? (string) $total : '0.00';
    }

    /**
     * Counts the total refunds matching specific filters under the active tenant.
     *
     * @param array{status?: string, trx_id?: string, transaction_id?: int|string, date_from?: string, date_to?: string} $filters Filtering criteria.
     * @return int Matching records count.
     */
    public function countFiltered(array $filters): int
    {
        if ($this->tenantId === null) {
            $where = "1=1";
            $params = [];
        } else {
            $where = "r.merchant_id = :mid";
            $params = ['mid' => $this->tenantId];
        }

        if (!empty($filters['status'])) {
            $where .= " AND r.status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['transaction_id'])) {
            $where .= " AND r.transaction_id = :txn_id";
            $params['txn_id'] = $filters['transaction_id'];
        }
        if (!empty($filters['trx_id'])) {
            $where .= " AND t.trx_id = :trx_id";
            $params['trx_id'] = $filters['trx_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND r.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND r.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $sql = "SELECT COUNT(*) FROM `{$this->table}` r 
                LEFT JOIN op_transactions t ON t.id = r.transaction_id 
                WHERE {$where}";
        $val = $this->db->fetchColumn($sql, $params);
        return is_scalar($val) ? (int) $val : 0;
    }

    /**
     * Lists refunds matching specific filters with sorting and pagination, scoped by active tenant.
     *
     * @param array{status?: string, trx_id?: string, transaction_id?: int|string, date_from?: string, date_to?: string} $filters Filtering criteria.
     * @param int $limit Maximum records to return.
     * @param int $offset Records offset.
     * @return array<int, array<string, mixed>> List of matching refund rows.
     */
    public function listFiltered(array $filters, int $limit, int $offset): array
    {
        if ($this->tenantId === null) {
            $where = "1=1";
            $params = [];
        } else {
            $where = "r.merchant_id = :mid";
            $params = ['mid' => $this->tenantId];
        }

        if (!empty($filters['status'])) {
            $where .= " AND r.status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['transaction_id'])) {
            $where .= " AND r.transaction_id = :txn_id";
            $params['txn_id'] = $filters['transaction_id'];
        }
        if (!empty($filters['trx_id'])) {
            $where .= " AND t.trx_id = :trx_id";
            $params['trx_id'] = $filters['trx_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND r.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND r.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        return $this->db->fetchAll(
            "SELECT r.*, t.trx_id as trx_id, t.gateway_trx_id as gateway_trx_id 
             FROM `{$this->table}` r 
             LEFT JOIN op_transactions t ON t.id = r.transaction_id
             WHERE {$where} ORDER BY r.created_at DESC LIMIT :lim OFFSET :off",
            array_merge($params, ['lim' => $limit, 'off' => $offset])
        );
    }

    /**
     * Retrieves a single record by primary key restricted within the active tenant context,
     * joining the transactions table to get trx_id and gateway_trx_id.
     *
     * @param int|string $id Primary key identifier.
     * @return array<string, mixed>|null Database row array, or null if not found.
     */
    public function findScoped(int|string $id): ?array
    {
        if ($this->tenantId === null) {
            return $this->db->fetchOne(
                "SELECT r.*, t.trx_id as trx_id, t.gateway_trx_id as gateway_trx_id
                 FROM `{$this->table}` r
                 LEFT JOIN op_transactions t ON t.id = r.transaction_id
                 WHERE r.id = :id LIMIT 1",
                ['id' => $id]
            );
        }
        return $this->db->fetchOne(
            "SELECT r.*, t.trx_id as trx_id, t.gateway_trx_id as gateway_trx_id
             FROM `{$this->table}` r
             LEFT JOIN op_transactions t ON t.id = r.transaction_id
             WHERE r.id = :id AND r.merchant_id = :mid LIMIT 1",
            ['id' => $id, 'mid' => $this->tenantId]
        );
    }
}

