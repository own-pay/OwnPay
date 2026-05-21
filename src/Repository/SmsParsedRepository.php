<?php
declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository class responsible for database operations, persistence, and querying
 * of parsed SMS data entries within the 'op_sms_parsed' table.
 *
 * Provides transactional queries scoped under tenant context (via TenantScope)
 * to prevent unauthorized cross-brand access to SMS parsed data operations.
 */
final class SmsParsedRepository extends BaseRepository
{
    use TenantScope;

    /**
     * The database table name associated with this repository.
     *
     * @var string
     */
    protected string $table = 'op_sms_parsed';

    /**
     * The list of columns that are safe to be bulk-filled on insertion or update.
     *
     * @var array<int, string>
     */
    protected array $fillable = [
        'merchant_id', 'device_id', 'sender', 'body', 'amount', 'trx_id',
        'gateway_slug', 'parser_type', 'match_status', 'transaction_id',
        'raw_data', 'received_at',
    ];

    /**
     * Retrieves a list of unmatched SMS entries that are pending transaction verification.
     *
     * @param int $limit Maximum number of unmatched SMS records to return. Defaults to 50.
     * @return array<int, array<string, mixed>> List of unmatched SMS entries.
     * @throws \RuntimeException If the active tenant context cannot be resolved.
     */
    public function findUnmatched(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND match_status = 'pending'
             ORDER BY received_at DESC LIMIT :lim",
            ['mid' => $this->requireTenant(), 'lim' => $limit]
        );
    }

    /**
     * Associates a specific parsed SMS entry with a validated transaction and updates its status.
     *
     * @param int $smsId The internal primary identifier of the SMS record.
     * @param int $transactionId The internal primary identifier of the transaction record.
     * @return int The number of affected database rows.
     * @throws \RuntimeException If the active tenant context cannot be resolved.
     */
    public function linkToTransaction(int $smsId, int $transactionId): int
    {
        return $this->updateScoped($smsId, [
            'transaction_id' => $transactionId,
            'match_status' => 'matched',
        ]);
    }

    /**
     * Lists all SMS data structures linked directly to a specific transaction.
     *
     * @param int $transactionId The internal primary identifier of the transaction.
     * @return array<int, array<string, mixed>> List of matching SMS records.
     */
    public function listForTransaction(int $transactionId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE transaction_id = :tid",
            ['tid' => $transactionId]
        );
    }

    /**
     * Retrieves unmatched SMS entries (alias for findUnmatched).
     * Typically invoked by asynchronous cron background tasks (e.g. SmsVerificationJob).
     *
     * @param int $limit Maximum number of unmatched SMS records to return. Defaults to 50.
     * @return array<int, array<string, mixed>> List of unmatched SMS entries.
     * @throws \RuntimeException If the active tenant context cannot be resolved.
     */
    public function getUnmatched(int $limit = 50): array
    {
        return $this->findUnmatched($limit);
    }
}
