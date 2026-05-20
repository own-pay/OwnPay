<?php
declare(strict_types=1);

namespace OwnPay\Repository;

use Ramsey\Uuid\Uuid;

final class RefundRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_refunds';
    protected array $fillable = [
        'merchant_id', 'transaction_id', 'uuid', 'amount', 'reason',
        'status', 'processed_at',
    ];

    public function createRefund(array $data): string
    {
        $data['uuid'] = Uuid::uuid4()->toString();
        return $this->createScoped($data);
    }

    public function getTotalRefundedAmount(int $transactionId, int $merchantId): string
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM `op_refunds`
             WHERE transaction_id = :txid AND merchant_id = :mid AND status IN ('pending', 'completed')",
            ['txid' => $transactionId, 'mid' => $merchantId]
        );
        return $row['total'] ?? '0.00';
    }
}
