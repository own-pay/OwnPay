<?php
declare(strict_types=1);

namespace OwnPay\Repository;

final class SmsParsedRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_sms_parsed';
    protected array $fillable = [
        'merchant_id', 'device_id', 'sender', 'body', 'amount', 'trx_id',
        'gateway_slug', 'parser_type', 'match_status', 'transaction_id',
        'raw_data', 'received_at',
    ];

    public function findUnmatched(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND match_status = 'pending'
             ORDER BY received_at DESC LIMIT :lim",
            ['mid' => $this->requireTenant(), 'lim' => $limit]
        );
    }

    public function linkToTransaction(int $smsId, int $transactionId): int
    {
        return $this->updateScoped($smsId, [
            'transaction_id' => $transactionId,
            'match_status' => 'matched',
        ]);
    }

    /**
     * List SMS data linked to a transaction.
     */
    public function listForTransaction(int $transactionId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE transaction_id = :tid",
            ['tid' => $transactionId]
        );
    }
}
