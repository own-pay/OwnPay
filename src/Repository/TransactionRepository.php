<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;

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
            'completed_at' => date('Y-m-d H:i:s.u'),
        ]);
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
}
