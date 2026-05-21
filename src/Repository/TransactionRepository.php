<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;
use OwnPay\Support\DateHelper;

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
     * Generate unique TRX ID: OP-XXXXXXXXXX
     */
    public function generateTrxId(): string
    {
        do {
            $trxId = 'OP-' . strtoupper(bin2hex(random_bytes(5)));
        } while ($this->db->exists($this->table, "trx_id = :t", ['t' => $trxId]));
        return $trxId;
    }

    public function createTransaction(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        $data['trx_id'] = $data['trx_id'] ?? $this->generateTrxId();
        $data['net_amount'] = $data['net_amount'] ?? bcsub((string) $data['amount'], (string) ($data['fee'] ?? '0'), 2);
        return $this->createScoped($data);
    }

    public function findByTrxId(string $trxId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE trx_id = :t AND merchant_id = :mid LIMIT 1",
            ['t' => $trxId, 'mid' => $this->requireTenant()]
        );
    }

    public function markCompleted(int $id): int
    {
        return $this->updateScoped($id, [
            'status' => 'completed',
            'completed_at' => DateHelper::nowMicro(),
        ]);
    }
    /**
     * Find transaction by gateway reference ID (tenant-scoped).
     */
    public function findByGatewayTrxId(string $gatewayTrxId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE gateway_trx_id = :gtid AND merchant_id = :mid LIMIT 1",
            ['gtid' => $gatewayTrxId, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Dashboard stats: total volume + count by status for date range.
     * Uses composite index idx_merchant_created.
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
     * Find active (pending/created) transaction by trx_id with merchant info.
     * Public checkout — no tenant scope needed.
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
     * Find any transaction by trx_id (any status). Public checkout status page.
     */
    public function findAnyByTrxId(string $trxId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE trx_id = :ref LIMIT 1",
            ['ref' => $trxId]
        );
    }

    /**
     * Find transaction awaiting verification by trx_id.
     */
    public function findAwaitingVerification(string $trxId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE trx_id = :ref AND status = 'awaiting_verification' LIMIT 1",
            ['ref' => $trxId]
        );
    }

    /**
     * Cancel a pending/created transaction by trx_id.
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
     * Set gateway + status on a transaction by ID.
     * Scoped by merchant_id to prevent cross-tenant IDOR.
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
     * Update metadata JSON on a transaction.
     * Scoped by merchant_id to prevent cross-tenant IDOR.
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
     * Set status + metadata atomically.
     * Scoped by merchant_id to prevent cross-tenant IDOR.
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
     * Daily report breakdown by gateway for date range.
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
     * Get distinct gateways used by a merchant.
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
     * Export-ready rows for CSV.
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
     * Get distinct currencies for a merchant.
     */
    public function getDistinctCurrencies(int $merchantId): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT currency FROM {$this->table} WHERE merchant_id = :mid",
            ['mid' => $merchantId]
        );
    }

    /**
     * Get today's stats for mobile dashboard.
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
     * Get recent transactions for mobile dashboard.
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
     * Find a pending transaction matching SMS amount/gateway for auto-verification.
     * Used by SmsVerificationJob cron.
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

