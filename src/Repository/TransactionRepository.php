<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;
use OwnPay\Enum\TransactionStatus;
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
        'sender_account', 'reference', 'gateway_trx_id', 'ip_address', 'method',
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
        
        $amtStr = is_numeric($data['amount'] ?? '') ? (string) $data['amount'] : '0.00';
        $feeStr = is_numeric($data['fee'] ?? '') ? (string) $data['fee'] : '0.00';
        /** @var numeric-string $amtStr */
        /** @var numeric-string $feeStr */
        $data['net_amount'] = $data['net_amount'] ?? bcsub($amtStr, $feeStr, 2);
        
        return $this->createScoped($data);
    }

    /**
     * Finds a transaction by its transaction ID code, scoped by active tenant if configured.
     *
     * @param string $trxId Transaction identifier.
     * @return array<string, mixed>|null Database row array, or null if not found.
     */
    public function findByTrxId(string $trxId): ?array
    {
        if ($this->tenantId !== null) {
            return $this->db->fetchOne(
                "SELECT * FROM {$this->table} WHERE trx_id = :t AND merchant_id = :mid LIMIT 1",
                ['t' => $trxId, 'mid' => $this->tenantId]
            );
        }
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE trx_id = :t LIMIT 1",
            ['t' => $trxId]
        );
    }

    /**
     * Finds a transaction by its MFS provider reference transaction ID (gateway reference),
     * scoped by active tenant if configured.
     *
     * @param string $providerTrxId The MFS provider's transaction reference.
     * @return array<string, mixed>|null Database row array, or null if not found.
     */
    public function findByProviderTrxId(string $providerTrxId): ?array
    {
        if ($this->tenantId !== null) {
            return $this->db->fetchOne(
                "SELECT * FROM {$this->table} WHERE provider_trx_id = :p AND merchant_id = :mid LIMIT 1",
                ['p' => $providerTrxId, 'mid' => $this->tenantId]
            );
        }
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE provider_trx_id = :p LIMIT 1",
            ['p' => $providerTrxId]
        );
    }

    /**
     * Marks a transaction as completed and updates the completion timestamp.
     *
     * The status guard enforces the state machine at the data layer: terminal
     * transactions (TransactionStatus::terminal()) are never re-completed, so
     * concurrent callbacks for the same transaction complete it exactly once
     * even when their application-level status pre-checks interleave.
     *
     * @param int $id Primary key identifier.
     * @return int Number of affected rows (0 when the transaction was already terminal).
     */
    public function markCompletedIfNotTerminal(int $id): int
    {
        [$placeholders, $params] = $this->terminalStatusPlaceholders();
        $params['completed_at'] = DateHelper::nowMicro();
        $params['id'] = $id;
        $params['mid'] = $this->requireTenant();

        return $this->db->update(
            "UPDATE {$this->table}
             SET status = 'completed', completed_at = :completed_at
             WHERE id = :id AND merchant_id = :mid AND status NOT IN ({$placeholders})",
            $params
        );
    }

    /**
     * Transitions a transaction to a non-completed status unless it is already terminal.
     *
     * Metadata is merged atomically via JSON_MERGE_PATCH so existing keys
     * (invoice_id, payment_link_id, conversion audit trail) survive - the
     * generated columns idx_invoice_id/idx_payment_link_id are derived from
     * this JSON and would silently detach if it were overwritten.
     *
     * @param int $id Primary key identifier.
     * @param string $status Target transaction status string.
     * @param array<string, mixed> $mergeMeta Optional metadata keys to merge in.
     * @return int Number of affected rows (0 when the transaction was already terminal).
     */
    public function markStatusIfNotTerminal(int $id, string $status, array $mergeMeta = []): int
    {
        [$placeholders, $params] = $this->terminalStatusPlaceholders();
        $params['status'] = $status;
        $params['id'] = $id;
        $params['mid'] = $this->requireTenant();

        $metaSet = '';
        if ($mergeMeta !== []) {
            $metaSet = ", metadata = JSON_MERGE_PATCH(COALESCE(metadata, '{}'), :meta_patch)";
            $params['meta_patch'] = json_encode($mergeMeta);
        }

        return $this->db->update(
            "UPDATE {$this->table}
             SET status = :status{$metaSet}
             WHERE id = :id AND merchant_id = :mid AND status NOT IN ({$placeholders})",
            $params
        );
    }

    /**
     * Builds the bound placeholder list for terminal statuses.
     *
     * @return array{0: string, 1: array<string, string>} Placeholder SQL fragment and bind params.
     */
    private function terminalStatusPlaceholders(): array
    {
        $placeholders = [];
        $params = [];
        foreach (TransactionStatus::terminal() as $i => $status) {
            $key = "term{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $status->value;
        }
        return [implode(', ', $placeholders), $params];
    }

    /**
     * Finds a transaction by its gateway reference ID, scoped by active tenant.
     *
     * @param string $gatewayTrxId The gateway's reference transaction ID.
     * @return array<string, mixed>|null Database row array, or null if not found.
     */
    public function findByGatewayTrxId(string $gatewayTrxId): ?array
    {
        if ($this->tenantId !== null) {
            return $this->db->fetchOne(
                "SELECT * FROM {$this->table} WHERE gateway_trx_id = :gtid AND merchant_id = :mid LIMIT 1",
                ['gtid' => $gatewayTrxId, 'mid' => $this->tenantId]
            );
        }
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE gateway_trx_id = :gtid LIMIT 1",
            ['gtid' => $gatewayTrxId]
        );
    }

    /**
     * Finds the last transaction record pointing to a specific payment intent, scoped by active tenant.
     *
     * @param int $paymentIntentId Unique payment intent ID.
     * @return array<string, mixed>|null The transaction record fields, or null if not found.
     */
    public function findByIntentId(int $paymentIntentId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE payment_intent_id = :pi AND merchant_id = :mid ORDER BY id DESC LIMIT 1",
            ['pi' => $paymentIntentId, 'mid' => $this->requireTenant()]
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
     * @param array{status?: string, gateway?: string, q?: string, date_from?: string, date_to?: string} $filters Filtering criteria.
     * @return int Matching records count.
     */
    public function countFiltered(array $filters): int
    {
        // tenantId === null => global "All Brands" view: aggregate across every brand.
        if ($this->tenantId === null) {
            $where = "1=1";
            $params = [];
        } else {
            $where = "merchant_id = :mid";
            $params = ['mid' => $this->tenantId];
        }

        if (!empty($filters['status'])) {
            $where .= " AND status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['gateway'])) {
            $where .= " AND gateway_slug = :gw";
            $params['gw'] = $filters['gateway'];
        }
        if (!empty($filters['q'])) {
            $where .= " AND (trx_id LIKE :q1 OR customer_id LIKE :q2 OR reference LIKE :q3)";
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND created_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        return $this->db->count($this->table, $where, $params);
    }

    /**
     * Retrieves a single record by primary key restricted within the active tenant context,
     * joining the gateways table to get the display name.
     *
     * @param int|string $id Primary key identifier.
     * @return array<string, mixed>|null Database row array, or null if not found.
     */
    public function findScoped(int|string $id): ?array
    {
        // tenantId === null => global read (superadmin "All Brands"): find regardless of brand.
        if ($this->tenantId === null) {
            return $this->db->fetchOne(
                "SELECT t.*, COALESCE(g.name, t.gateway_slug) as gateway_name
                 FROM {$this->table} t
                 LEFT JOIN op_gateways g ON g.slug = t.gateway_slug
                 WHERE t.id = :id LIMIT 1",
                ['id' => $id]
            );
        }
        return $this->db->fetchOne(
            "SELECT t.*, COALESCE(g.name, t.gateway_slug) as gateway_name
             FROM {$this->table} t
             LEFT JOIN op_gateways g ON g.slug = t.gateway_slug
             WHERE t.id = :id AND t.merchant_id = :mid LIMIT 1",
            ['id' => $id, 'mid' => $this->tenantId]
        );
    }

    /**
     * Lists transactions matching specific filters with sorting and pagination, scoped by active tenant.
     *
     * @param array{status?: string, gateway?: string, q?: string, date_from?: string, date_to?: string} $filters Filtering criteria.
     * @param int $limit Maximum records to return.
     * @param int $offset Records offset.
     * @return array<int, array<string, mixed>> List of matching transaction rows.
     */
    public function listFiltered(array $filters, int $limit, int $offset): array
    {
        // tenantId === null => global "All Brands" view: aggregate across every brand.
        if ($this->tenantId === null) {
            $where = "1=1";
            $params = [];
        } else {
            $where = "t.merchant_id = :mid";
            $params = ['mid' => $this->tenantId];
        }

        if (!empty($filters['status'])) {
            $where .= " AND t.status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['gateway'])) {
            $where .= " AND t.gateway_slug = :gw";
            $params['gw'] = $filters['gateway'];
        }
        if (!empty($filters['q'])) {
            $where .= " AND (t.trx_id LIKE :q1 OR t.customer_id LIKE :q2 OR t.reference LIKE :q3)";
            $params['q1'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND t.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND t.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        return $this->db->fetchAll(
            "SELECT t.*, c.name_enc as customer_name, COALESCE(g.name, t.gateway_slug) as gateway_name
             FROM {$this->table} t 
             LEFT JOIN op_customers c ON c.id = t.customer_id 
             LEFT JOIN op_gateways g ON g.slug = t.gateway_slug
             WHERE {$where} ORDER BY t.created_at DESC LIMIT :lim OFFSET :off",
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

        $row = $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END), 0) as total_revenue,
                COUNT(CASE WHEN status='completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN status='pending' THEN 1 END) as pending_count
             FROM op_transactions
             WHERE 1=1 {$merchantWhere} {$dateFilterSQL}",
            $params
        );

        $revVal = $row['total_revenue'] ?? '0.00';
        $ccVal = $row['completed_count'] ?? 0;
        $pcVal = $row['pending_count'] ?? 0;
        return [
            'total_revenue'   => is_scalar($revVal) ? (string) $revVal : '0.00',
            'completed_count' => is_scalar($ccVal) ? (int) $ccVal : 0,
            'pending_count'   => is_scalar($pcVal) ? (int) $pcVal : 0,
        ];
    }

    /**
     * Retrieves recent transactions with joined customer names.
     *
     * @param bool $isGlobal True if retrieving global (superadmin) transactions, false otherwise.
     * @param int|null $merchantId Specific brand/store identifier (ignored if global).
     * @return array<int, array<string, mixed>> List of recent transaction rows.
     */
    public function getRecentDashboardTransactions(bool $isGlobal, ?int $merchantId): array
    {
        $merchantWhere = $isGlobal ? '' : 'AND t.merchant_id = :mid';
        $params = $isGlobal ? [] : ['mid' => $merchantId];

        return $this->db->fetchAll(
            "SELECT t.*, c.name_enc as customer_name, c.email_enc as customer_email
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
     * @return array<int, array<string, mixed>> Breakdown array list.
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

    // --- Checkout-facing methods (no tenant scope - used by public checkout page) ---

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
        if ($txn !== null && isset($txn['metadata']) && is_string($txn['metadata']) && $txn['metadata'] !== '') {
            $decoded = json_decode($txn['metadata'], true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
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
        if ($txn !== null && isset($txn['metadata']) && is_string($txn['metadata']) && $txn['metadata'] !== '') {
            $decoded = json_decode($txn['metadata'], true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
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

    // --- Report/Export methods (for admin dashboard) ---

    /**
     * Retrieves daily report breakdown by gateway for date range.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param string $from Starting date boundary.
     * @param string $to Ending date boundary.
     * @param string|null $gateway Optional gateway slug filter.
     * @return array<int, array<string, mixed>> Report records list.
     */
    public function getReportData(?int $merchantId, string $from, string $to, ?string $gateway = null): array
    {
        $params = ['from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
        // merchantId === null => global "All Brands" view: aggregate across all brands.
        $merchantWhere = '';
        if ($merchantId !== null) {
            $merchantWhere = 'merchant_id = :mid AND';
            $params['mid'] = $merchantId;
        }
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
             WHERE {$merchantWhere} created_at BETWEEN :from AND :to
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
     * @return array<int, array<string, mixed>> List of used gateway descriptors.
     */
    public function getDistinctGateways(?int $merchantId = null): array
    {
        // null merchantId => global "All Brands" view: distinct gateways across all brands.
        if ($merchantId === null) {
            return $this->db->fetchAll(
                "SELECT DISTINCT gateway_slug as slug, gateway_slug as name
                 FROM {$this->table} WHERE gateway_slug IS NOT NULL AND gateway_slug <> ''"
            );
        }
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
     * @return array<int, array<string, mixed>> Export-ready rows.
     */
    public function getExportData(?int $merchantId, string $from, string $to, ?string $gateway = null): array
    {
        $params = ['from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
        // merchantId === null => global "All Brands" view: export across all brands.
        $merchantWhere = '';
        if ($merchantId !== null) {
            $merchantWhere = 'merchant_id = :mid AND';
            $params['mid'] = $merchantId;
        }
        $gatewayWhere = '';
        if ($gateway !== null && $gateway !== '') {
            $gatewayWhere = 'AND gateway_slug = :gw';
            $params['gw'] = $gateway;
        }

        return $this->db->fetchAll(
            "SELECT id, gateway_slug, currency, amount, status, created_at
             FROM {$this->table}
             WHERE {$merchantWhere} created_at BETWEEN :from AND :to
               {$gatewayWhere}
             ORDER BY created_at DESC",
            $params
        );
    }

    /**
     * Lists distinct currency ISO codes present in transactions under a merchant.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @return array<int, array<string, mixed>> List of used currencies.
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
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) as revenue,
                    COUNT(*) as total,
                    COUNT(CASE WHEN status='pending' THEN 1 END) as pending
             FROM {$this->table} WHERE merchant_id = :mid AND DATE(created_at) = CURDATE()",
            ['mid' => $merchantId]
        );
        $revVal = $row['revenue'] ?? '0.00';
        $totVal = $row['total'] ?? 0;
        $pendVal = $row['pending'] ?? 0;
        return [
            'revenue' => is_scalar($revVal) ? (string) $revVal : '0.00',
            'total'   => is_scalar($totVal) ? (int) $totVal : 0,
            'pending' => is_scalar($pendVal) ? (int) $pendVal : 0,
        ];
    }

    /**
     * Lists recent transactions for the mobile companion dashboard.
     *
     * @param int $merchantId Active brand/store identifier context.
     * @param int $limit Maximum records to retrieve.
     * @return array<int, array<string, mixed>> Recent transaction rows.
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
     * @param string|null $receivedAt Optional timestamp when the SMS was received.
     * @return array<string, mixed>|null Pending transaction row data, or null if no match found.
     */
    public function findPendingMatch(int $merchantId, string $amount, string $gatewaySlug, ?string $receivedAt = null): ?array
    {
        if ($receivedAt !== null) {
            $sqlCount = "SELECT COUNT(*) FROM {$this->table}
                         WHERE merchant_id = :mid AND status = 'pending'
                           AND amount = :amt AND gateway_slug = :gw
                           AND created_at BETWEEN DATE_SUB(:received_at1, INTERVAL 30 MINUTE) AND DATE_ADD(:received_at2, INTERVAL 5 MINUTE)";
            $countVal = $this->db->fetchColumn($sqlCount, [
                'mid' => $merchantId,
                'amt' => $amount,
                'gw'  => $gatewaySlug,
                'received_at1' => $receivedAt,
                'received_at2' => $receivedAt
            ]);
            $count = is_scalar($countVal) ? (int) $countVal : 0;

            if ($count !== 1) {
                return null;
            }

            return $this->db->fetchOne(
                "SELECT * FROM {$this->table}
                 WHERE merchant_id = :mid AND status = 'pending'
                   AND amount = :amt AND gateway_slug = :gw
                   AND created_at BETWEEN DATE_SUB(:received_at3, INTERVAL 30 MINUTE) AND DATE_ADD(:received_at4, INTERVAL 5 MINUTE)
                 LIMIT 1",
                [
                    'mid' => $merchantId,
                    'amt' => $amount,
                    'gw' => $gatewaySlug,
                    'received_at3' => $receivedAt,
                    'received_at4' => $receivedAt
                ]
            );
        }

        return $this->db->fetchOne(
            "SELECT * FROM {$this->table}
             WHERE merchant_id = :mid AND status = 'pending'
               AND amount = :amt AND gateway_slug = :gw
             ORDER BY created_at DESC LIMIT 1",
            ['mid' => $merchantId, 'amt' => $amount, 'gw' => $gatewaySlug]
        );
    }

    /**
     * Searches for a pending transaction matching amount and gateway globally (across all brands) for SMS verification.
     *
     * Used by cron-based SmsVerificationJob.
     *
     * @param string $amount Matching payment amount string.
     * @param string $gatewaySlug Matching gateway adapter slug.
     * @param string|null $receivedAt Optional timestamp when the SMS was received.
     * @return array<string, mixed>|null Pending transaction row data, or null if no match found.
     */
    public function findPendingMatchGlobal(string $amount, string $gatewaySlug, ?string $receivedAt = null): ?array
    {
        if ($receivedAt !== null) {
            $sqlCount = "SELECT COUNT(*) FROM {$this->table}
                         WHERE status = 'pending'
                           AND amount = :amt AND gateway_slug = :gw
                           AND created_at BETWEEN DATE_SUB(:received_at1, INTERVAL 30 MINUTE) AND DATE_ADD(:received_at2, INTERVAL 5 MINUTE)";
            $countVal = $this->db->fetchColumn($sqlCount, [
                'amt' => $amount,
                'gw'  => $gatewaySlug,
                'received_at1' => $receivedAt,
                'received_at2' => $receivedAt
            ]);
            $count = is_scalar($countVal) ? (int) $countVal : 0;

            if ($count !== 1) {
                return null;
            }

            return $this->db->fetchOne(
                "SELECT * FROM {$this->table}
                 WHERE status = 'pending'
                   AND amount = :amt AND gateway_slug = :gw
                   AND created_at BETWEEN DATE_SUB(:received_at3, INTERVAL 30 MINUTE) AND DATE_ADD(:received_at4, INTERVAL 5 MINUTE)
                 LIMIT 1",
                [
                    'amt' => $amount,
                    'gw' => $gatewaySlug,
                    'received_at3' => $receivedAt,
                    'received_at4' => $receivedAt
                ]
            );
        }

        return $this->db->fetchOne(
            "SELECT * FROM {$this->table}
             WHERE status = 'pending'
               AND amount = :amt AND gateway_slug = :gw
             ORDER BY created_at DESC LIMIT 1",
            ['amt' => $amount, 'gw' => $gatewaySlug]
        );
    }
}
