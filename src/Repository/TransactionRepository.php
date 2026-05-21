<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;
use OwnPay\Support\DateHelper;

/**
 * Repository layer for transactions (`op_transactions` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Implements transaction lifecycle updates, dashboard metrics retrieval,
 * reporting extracts, and SMS-to-transaction auto-matching.
 * 
 * Note: Database Generated Indexing Columns: The `op_transactions` table contains 
 * STORED generated columns `invoice_id` and `payment_link_id` extracted from JSON 
 * metadata to accelerate query operations with direct index keys `idx_invoice_id` 
 * and `idx_payment_link_id`.
 */
final class TransactionRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_transactions';
    protected array $fillable = [
        'merchant_id', 'uuid', 'trx_id', 'payment_intent_id', 'customer_id',
        'gateway_slug', 'amount', 'fee', 'net_amount', 'currency',
        'sender_account', 'reference', 'gateway_trx_id', 'method',
        'status', 'metadata', 'completed_at',
    ];

    /**
     * Generates a unique transaction identifier with the prefix 'OP-'.
     *
     * @return string Unique transaction identifier.
     */
    public function generateTrxId(): string
    {
        do {
            $trxId = 'OP-' . strtoupper(bin2hex(random_bytes(5)));
        } while ($this->db->exists($this->table, "trx_id = :t", ['t' => $trxId]));
        return $trxId;
    }

    /**
     * Creates a new transaction with a unique transaction ID and UUID.
     *
     * @param array<string, mixed> $data Transaction parameters.
     * @return string Last inserted primary key ID.
     */
    public function createTransaction(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        $data['trx_id'] = $data['trx_id'] ?? $this->generateTrxId();
        $data['net_amount'] = $data['net_amount'] ?? bcsub((string) $data['amount'], (string) ($data['fee'] ?? '0'), 2);
        return $this->createScoped($data);
    }

    /**
     * Finds a transaction by its transaction ID code, scoped by active tenant.
     *
     * @param string $trxId Transaction identifier.
     * @return array<string, mixed>|null Database row array, or null if not found.
     */
    public function findByTrxId(string $trxId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE trx_id = :t AND merchant_id = :mid LIMIT 1",
            ['t' => $trxId, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Marks a transaction as completed and updates the completion timestamp.
     *
     * @param int $id Primary key identifier.
     * @return int Number of affected rows.
     */
    public function markCompleted(int $id): int
    {
        return $this->updateScoped($id, [
            'status' => 'completed',
            'completed_at' => DateHelper::nowMicro(),
        ]);
    }

    /**
     * Finds a transaction by its gateway reference ID, scoped by active tenant.
     *
     * @param string $gatewayTrxId The gateway's reference transaction ID.
     * @return array<string, mixed>|null Database row array, or null if not found.
     */
    public function findByGatewayTrxId(string $gatewayTrxId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE gateway_trx_id = :gtid AND merchant_id = :mid LIMIT 1",
            ['gtid' => $gatewayTrxId, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Computes dashboard statistics (volume, fees, counts) for a date range.
     *
     * Uses the composite index `idx_merchant_created` for optimal query execution.
     *
     * @param string $from Starting datetime boundary.
     * @param string $to Ending datetime boundary.
     * @return array<string, mixed> Aggregated statistics array.
     */
    public function stats(string $from, string $to): array
    {
        $mid = $this->requireTenant();
        return $this->db->fetchOne(
            "SELECT
                COUNT(*) as total_count,
                COALESCE(SUM(amount), 0) as total_volume,
                COALESCE(SUM(fee), 0) as total_fees,
                COALESCE(SUM(net_amount), 0) as total_net,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            FROM {$this->table}
            WHERE merchant_id = :mid AND created_at BETWEEN :from AND :to",
            ['mid' => $mid, 'from' => $from, 'to' => $to]
        ) ?? [];
    }

    /**
     * Counts the total transactions matching specific filters under the active tenant.
     *
     * @param array{status?: string, gateway?: string, q?: string} $filters Filtering criteria.
     * @return int Matching records count.
     */
    public function countFiltered(array $filters): int
    {
        $where = "merchant_id = :mid";
        $params = ['mid' => $this->requireTenant()];

        if (!empty($filters['status'])) {
            $where .= " AND status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['gateway'])) {
            $where .= " AND gateway_slug = :gw";
            $params['gw'] = $filters['gateway'];
        }
        if (!empty($filters['q'])) {
            $where .= " AND (trx_id LIKE :q OR customer_id LIKE :q OR reference LIKE :q)";
            $params['q'] = '%' . $filters['q'] . '%';
        }

        return $this->db->count($this->table, $where, $params);
    }

    /**
     * Lists transactions matching specific filters with sorting and pagination, scoped by active tenant.
     *
     * @param array{status?: string, gateway?: string, q?: string} $filters Filtering criteria.
     * @param int $limit Maximum records to return.
     * @param int $offset Records offset.
     * @return list<array<string, mixed>> List of matching transaction rows.
     */
    public function listFiltered(array $filters, int $limit, int $offset): array
    {
        $where = "merchant_id = :mid";
        $params = ['mid' => $this->requireTenant()];

        if (!empty($filters['status'])) {
            $where .= " AND status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['gateway'])) {
            $where .= " AND gateway_slug = :gw";
            $params['gw'] = $filters['gateway'];
        }
        if (!empty($filters['q'])) {
            $where .= " AND (trx_id LIKE :q OR customer_id LIKE :q OR reference LIKE :q)";
            $params['q'] = '%' . $filters['q'] . '%';
        }

        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY created_at DESC LIMIT :lim OFFSET :off",
            array_merge($params, ['lim' => $limit, 'off' => $offset])
        );
    }

    /**
     * Retrieves aggregated transaction metrics for global or brand-specific dashboards.
     *
     * @param bool $isGlobal True if retrieving global (superadmin) statistics, false otherwise.
     * @param int|null $merchantId Specific brand/store identifier (ignored if global).
     * @param string $dateFilterSQL Raw SQL snippet for date boundaries.
     * @return array{total_revenue: string, completed_count: int, pending_count: int} Aggregated dashboard statistics.
     */
    public function getDashboardStats(bool $isGlobal, ?int $merchantId, string $dateFilterSQL): array
    {
        $merchantWhere = $isGlobal ? '' : 'AND merchant_id = :mid';
        $params = $isGlobal ? [] : ['mid' => $merchantId];

        return $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END), 0) as total_revenue,
                COUNT(CASE WHEN status='completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN status='pending' THEN 1 END) as pending_count
             FROM op_transactions
             WHERE 1=1 {$merchantWhere} {$dateFilterSQL}",
            $params
        );
    }

    /**
     * Retrieves recent transactions with joined customer names.
     *
     * @param bool $isGlobal True if retrieving global (superadmin) transactions, false otherwise.
     * @param int|null $merchantId Specific brand/store identifier (ignored if global).
     * @return list<array<string, mixed>> List of recent transaction rows.
     */
    public function getRecentDashboardTransactions(bool $isGlobal, ?int $merchantId): array
    {
        $merchantWhere = $isGlobal ? '' : 'AND t.merchant_id = :mid';
        $params = $isGlobal ? [] : ['mid' => $merchantId];

        return $this->db->fetchAll(
            "SELECT t.*, c.name_enc as customer_name
             FROM op_transactions t
             LEFT JOIN op_customers c ON c.id = t.customer_id
             WHERE 1=1 {$merchantWhere}
             ORDER BY t.created_at DESC LIMIT 10",
            $params
        );
    }

    /**
     * Computes a global breakdown of revenue and transaction count per brand/merchant.
     *
     * Used exclusively in superadmin dashboards.
     *
     * @return list<array{id: int, name: string, slug: string, revenue: string, txn_count: int}> Breakdown array list.
     */
    public function getGlobalBrandBreakdown(): array
    {
        return $this->db->fetchAll(
            "SELECT m.id, m.name, m.slug,
                COALESCE(SUM(CASE WHEN t.status='completed' THEN t.amount ELSE 0 END), 0) as revenue,
                COUNT(t.id) as txn_count
             FROM op_merchants m
             LEFT JOIN op_transactions t ON t.merchant_id = m.id
             GROUP BY m.id, m.name, m.slug
             ORDER BY revenue DESC"
        );
    }

    // ─── Checkout-facing methods (no tenant scope — used by public checkout page) ───

    /**
     * Finds a pending or created transaction with associated merchant information.
     *
     * Public checkout endpoint helper; intentionally unscoped.
     *
     * @param string $trxId Unique transaction identifier.
     * @return array<string, mixed>|null Transaction and merchant row data, or null if not found.
     */
    public function findActiveForCheckout(string $trxId): ?array
    {
        return $this->db->fetchOne(
            "SELECT t.*, m.name as merchant_name, m.id as merchant_id
             FROM {$this->table} t
             JOIN op_merchants m ON m.id = t.merchant_id
             WHERE t.trx_id = :ref AND t.status IN ('pending','created')
             LIMIT 1",
            ['ref' => $trxId]
        );
    }

    /**
     * Finds any transaction by its transaction ID code across all tenants.
     *
     * Public checkout status endpoint helper; intentionally unscoped.
     *
     * @param string $trxId Unique transaction identifier.
     * @return array<string, mixed>|null Transaction row data, or null if not found.
     */
    public function findAnyByTrxId(string $trxId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE trx_id = :ref LIMIT 1",
            ['ref' => $trxId]
        );
    }

    /**
     * Finds a transaction currently in 'awaiting_verification' status by transaction ID code.
     *
     * Public checkout callback helper; intentionally unscoped.
     *
     * @param string $trxId Unique transaction identifier.
     * @return array<string, mixed>|null Transaction row data, or null if not found.
     */
    public function findAwaitingVerification(string $trxId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE trx_id = :ref AND status = 'awaiting_verification' LIMIT 1",
            ['ref' => $trxId]
        );
    }

    /**
     * Cancels a pending or created transaction by transaction ID code.
     *
     * Public checkout callback helper; intentionally unscoped.
     *
     * @param string $trxId Unique transaction identifier.
     * @return void
     */
    public function cancelByTrxId(string $trxId): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET status = 'cancelled', updated_at = NOW()
             WHERE trx_id = :ref AND status IN ('pending','created')",
            ['ref' => $trxId]
        );
    }

    /**
     * Updates the gateway slug and status for a transaction.
     *
     * Scoped optionally by merchant ID to prevent cross-tenant IDOR attacks.
     *
     * @param int $id Primary key identifier.
     * @param string $gateway Gateway adapter slug name.
     * @param string $status Target transaction status string.
     * @param int $merchantId Scoping merchant ID (0 to bypass scoping).
     * @return void
     */
    public function setGatewayAndStatus(int $id, string $gateway, string $status, int $merchantId = 0): void
    {
        if ($merchantId > 0) {
            $this->db->execute(
                "UPDATE {$this->table} SET gateway_slug = :gw, status = :st, updated_at = NOW() WHERE id = :id AND merchant_id = :mid",
                ['gw' => $gateway, 'st' => $status, 'id' => $id, 'mid' => $merchantId]
            );
        } else {
            $this->db->execute(
                "UPDATE {$this->table} SET gateway_slug = :gw, status = :st, updated_at = NOW() WHERE id = :id",
                ['gw' => $gateway, 'st' => $status, 'id' => $id]
            );
        }
    }

    /**
     * Merges and updates JSON metadata on a transaction record.
     *
     * Scoped optionally by merchant ID to prevent cross-tenant IDOR attacks.
     *
     * @param int $id Primary key identifier.
     * @param array<string, mixed> $metadata New key-value pairs to merge into metadata.
     * @param int $merchantId Scoping merchant ID (0 to bypass scoping).
     * @return void
     */
    public function updateMetadata(int $id, array $metadata, int $merchantId = 0): void
    {
        $mid = $merchantId > 0 ? $merchantId : $this->tenantId;
        $txn = null;
        if ($mid !== null && $mid > 0) {
            $txn = $this->db->fetchOne(
                "SELECT metadata FROM {$this->table} WHERE id = :id AND merchant_id = :mid LIMIT 1",
                ['id' => $id, 'mid' => $mid]
            );
        } else {
            $txn = $this->db->fetchOne(
                "SELECT metadata FROM {$this->table} WHERE id = :id LIMIT 1",
                ['id' => $id]
            );
        }

        $existing = [];
        if ($txn !== null && !empty($txn['metadata'])) {
            $existing = json_decode($txn['metadata'], true) ?: [];
        }

        $merged = array_merge($existing, $metadata);

        if ($merchantId > 0) {
            $this->db->execute(
                "UPDATE {$this->table} SET metadata = :meta, updated_at = NOW() WHERE id = :id AND merchant_id = :mid",
                ['meta' => json_encode($merged), 'id' => $id, 'mid' => $merchantId]
            );
        } else {
            $this->db->execute(
                "UPDATE {$this->table} SET metadata = :meta, updated_at = NOW() WHERE id = :id",
                ['meta' => json_encode($merged), 'id' => $id]
            );
        }
    }

    /**
     * Updates transaction status and merges metadata atomically.
     *
     * Scoped optionally by merchant ID to prevent cross-tenant IDOR attacks.
     *
     * @param int $id Primary key identifier.
     * @param string $status Target transaction status string.
     * @param array<string, mixed> $metadata New key-value pairs to merge into metadata.
     * @param int $merchantId Scoping merchant ID (0 to bypass scoping).
     * @return void
     */
    public function setStatusWithMeta(int $id, string $status, array $metadata, int $merchantId = 0): void
    {
        $mid = $merchantId > 0 ? $merchantId : $this->tenantId;
        $txn = null;
        if ($mid !== null && $mid > 0) {
            $txn = $this->db->fetchOne(
                "SELECT metadata FROM {$this->table} WHERE id = :id AND merchant_id = :mid LIMIT 1",
                ['id' => $id, 'mid' => $mid]
            );
        } else {
            $txn = $this->db->fetchOne(
                "SELECT metadata FROM {$this->table} WHERE id = :id LIMIT 1",
                ['id' => $id]
            );
        }

        $existing = [];
        if ($txn !== null && !empty($txn['metadata'])) {
            $existing = json_decode($txn['metadata'], true) ?: [];
        }

        $merged = array_merge($existing, $metadata);

        if ($merchantId > 0) {
            $this->db->execute(
                "UPDATE {$this->table} SET status = :st, metadata = :meta, updated_at = NOW() WHERE id = :id AND merchant_id = :mid",
                ['st' => $status, 'meta' => json_encode($merged), 'id' => $id, 'mid' => $merchantId]
            );
        } else {
            $this->db->execute(
                "UPDATE {$this->table} SET status = :st, metadata = :meta, updated_at = NOW() WHERE id = :id",
                ['st' => $status, 'meta' => json_encode($merged), 'id' => $id]
            );
        }
    }

    // ─── Report/Export methods (for admin dashboard) ───

    /**
     * Retrieves daily report breakdown by gateway for date range.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param string $from Starting date boundary.
     * @param string $to Ending date boundary.
     * @param string|null $gateway Optional gateway slug filter.
     * @return list<array{date: string, gateway_slug: string, txn_count: int, revenue: string, refunds: string, failed_count: int}> Report records list.
     */
    public function getReportData(int $merchantId, string $from, string $to, ?string $gateway = null): array
    {
        $params = ['from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59', 'mid' => $merchantId];
        $gatewayWhere = '';
        if ($gateway !== null && $gateway !== '') {
            $gatewayWhere = 'AND gateway_slug = :gw';
            $params['gw'] = $gateway;
        }

        return $this->db->fetchAll(
            "SELECT
                DATE(created_at) as date,
                gateway_slug,
                COUNT(*) as txn_count,
                SUM(CASE WHEN status='completed' THEN amount ELSE 0 END) as revenue,
                SUM(CASE WHEN status='refunded' THEN amount ELSE 0 END) as refunds,
                COUNT(CASE WHEN status='failed' THEN 1 END) as failed_count
             FROM {$this->table}
             WHERE merchant_id = :mid
               AND created_at BETWEEN :from AND :to
               {$gatewayWhere}
             GROUP BY DATE(created_at), gateway_slug
             ORDER BY date DESC",
            $params
        );
    }

    /**
     * Lists distinct gateway slug names used in transactions under a merchant.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @return list<array{slug: string, name: string}> List of used gateway descriptors.
     */
    public function getDistinctGateways(int $merchantId): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT gateway_slug as slug, gateway_slug as name
             FROM {$this->table} WHERE merchant_id = :mid",
            ['mid' => $merchantId]
        );
    }

    /**
     * Retrieves transactions ready to be exported for reporting.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param string $from Starting date boundary.
     * @param string $to Ending date boundary.
     * @param string|null $gateway Optional gateway slug filter.
     * @return list<array{id: int, gateway_slug: string, currency: string, amount: string, status: string, created_at: string}> Export-ready rows.
     */
    public function getExportData(int $merchantId, string $from, string $to, ?string $gateway = null): array
    {
        $params = ['from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59', 'mid' => $merchantId];
        $gatewayWhere = '';
        if ($gateway !== null && $gateway !== '') {
            $gatewayWhere = 'AND gateway_slug = :gw';
            $params['gw'] = $gateway;
        }

        return $this->db->fetchAll(
            "SELECT id, gateway_slug, currency, amount, status, created_at
             FROM {$this->table}
             WHERE merchant_id = :mid
               AND created_at BETWEEN :from AND :to
               {$gatewayWhere}
             ORDER BY created_at DESC",
            $params
        );
    }

    /**
     * Lists distinct currency ISO codes present in transactions under a merchant.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @return list<array{currency: string}> List of used currencies.
     */
    public function getDistinctCurrencies(int $merchantId): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT currency FROM {$this->table} WHERE merchant_id = :mid",
            ['mid' => $merchantId]
        );
    }

    /**
     * Retrieves today's completed revenue, total count, and pending count metrics.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @return array{revenue: string|int|float, total: int, pending: int} Aggregated today's metrics.
     */
    public function getTodayStats(int $merchantId): array
    {
        return $this->db->fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) as revenue,
                    COUNT(*) as total,
                    COUNT(CASE WHEN status='pending' THEN 1 END) as pending
             FROM {$this->table} WHERE merchant_id = :mid AND DATE(created_at) = CURDATE()",
            ['mid' => $merchantId]
        ) ?? ['revenue' => 0, 'total' => 0, 'pending' => 0];
    }

    /**
     * Lists recent transactions for the mobile companion dashboard.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param int $limit Maximum records to retrieve.
     * @return list<array{trx_id: string, amount: string, currency: string, status: string, gateway: string, created_at: string}> Recent transaction rows.
     */
    public function getRecentTransactions(int $merchantId, int $limit = 5): array
    {
        return $this->db->fetchAll(
            "SELECT trx_id, amount, currency, status, gateway_slug as gateway, created_at
             FROM {$this->table} WHERE merchant_id = :mid ORDER BY created_at DESC LIMIT :lim",
            ['mid' => $merchantId, 'lim' => $limit]
        );
    }

    /**
     * Searches for a pending transaction matching amount and gateway for SMS verification.
     *
     * Used by cron-based SmsVerificationJob.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param string $amount Matching payment amount string.
     * @param string $gatewaySlug Matching gateway adapter slug.
     * @return array<string, mixed>|null Pending transaction row data, or null if no match found.
     */
    public function findPendingMatch(int $merchantId, string $amount, string $gatewaySlug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table}
             WHERE merchant_id = :mid AND status = 'pending'
               AND amount = :amt AND gateway_slug = :gw
             ORDER BY created_at DESC LIMIT 1",
            ['mid' => $merchantId, 'amt' => $amount, 'gw' => $gatewaySlug]
        );
    }
}

