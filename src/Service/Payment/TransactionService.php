<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Repository\TransactionRepository;

/**
 * Service managing transaction states and audit trails.
 *
 * Provides capabilities to create, complete, fail, cancel transactions, calculate stats,
 * and search transactions using local or gateway identifiers. Fires system events and records audit logs.
 */
final class TransactionService
{
    /**
     * @var TransactionRepository Repository accessing core transactions.
     */
    private TransactionRepository $transactions;

    /**
     * @var EventManager Event dispatcher for action/filter hooks.
     */
    private EventManager $events;

    /**
     * @var AuditLogRepository Repository logging admin/merchant actions.
     */
    private AuditLogRepository $audit;

    /**
     * TransactionService constructor.
     *
     * @param TransactionRepository $transactions Repository for transactions.
     * @param EventManager $events System event dispatcher.
     * @param AuditLogRepository $audit System audit log recorder.
     */
    public function __construct(
        TransactionRepository $transactions,
        EventManager $events,
        AuditLogRepository $audit
    ) {
        $this->transactions = $transactions;
        $this->events = $events;
        $this->audit = $audit;
    }

    /**
     * Creates a new transaction record for a merchant brand.
     *
     * Runs filters before inserting, triggers the creation event action, and records the event in the audit trail.
     *
     * @param int $merchantId The ID of the merchant/brand.
     * @param array<string, mixed> $data The transaction initialization fields.
     * @return array<string, mixed> The newly created transaction record fields.
     */
    public function create(int $merchantId, array $data): array
    {
        $res = $this->events->applyFilter('payment.transaction.before_create', $data, $merchantId);
        $data = is_array($res) ? $res : $data;

        $repo = $this->transactions->forTenant($merchantId);
        $id = $repo->createTransaction($data);
        $transaction = $repo->findScoped((int) $id);
        if ($transaction === null) {
            throw new \RuntimeException('Failed to retrieve newly created transaction.');
        }

        $this->events->doAction('payment.transaction.created', $transaction);

        $this->audit->record(
            $merchantId,
            null,
            'transaction.created',
            'transaction',
            (int) $id,
            null,
            ['trx_id' => $transaction['trx_id'], 'amount' => $transaction['amount']]
        );

        return $transaction;
    }

    /**
     * Marks a transaction as completed.
     *
     * Updates status to completed, triggers completion event hooks, and appends a record to the audit trail.
     *
     * @param int $transactionId The unique ID of the transaction.
     * @param int $merchantId The ID of the merchant/brand.
     * @return array<string, mixed> The completed transaction record fields.
     */
    public function complete(int $transactionId, int $merchantId): array
    {
        $repo = $this->transactions->forTenant($merchantId);
        $affected = $repo->markCompletedIfNotTerminal($transactionId);
        $transaction = $repo->findScoped($transactionId);
        if ($transaction === null) {
            throw new \RuntimeException('Failed to retrieve completed transaction.');
        }

        if ($affected === 0) {
            return $transaction;
        }

        $this->events->doAction('payment.transaction.completed', $transaction);

        $this->audit->record(
            $merchantId,
            null,
            'transaction.completed',
            'transaction',
            $transactionId,
            ['status' => 'pending'],
            ['status' => 'completed']
        );

        return $transaction;
    }

    /**
     * Marks a transaction as failed with a failure reason.
     *
     * @param int $transactionId The unique ID of the transaction.
     * @param int $merchantId The ID of the merchant/brand.
     * @param string|null $reason Optional description of the failure reason.
     * @return array<string, mixed> The failed transaction record fields.
     */
    public function fail(int $transactionId, int $merchantId, ?string $reason = null): array
    {
        $repo = $this->transactions->forTenant($merchantId);
        $affected = $repo->markStatusIfNotTerminal(
            $transactionId,
            'failed',
            $reason !== null ? ['failure_reason' => $reason] : []
        );
        $transaction = $repo->findScoped($transactionId);
        if ($transaction === null) {
            throw new \RuntimeException('Failed to retrieve failed transaction.');
        }

        if ($affected === 0) {
            return $transaction;
        }

        $this->events->doAction('payment.transaction.failed', $transaction);

        return $transaction;
    }

    /**
     * Cancels an active pending transaction.
     *
     * @param int $transactionId The unique ID of the transaction.
     * @param int $merchantId The ID of the merchant/brand.
     * @return array<string, mixed> The cancelled transaction record fields.
     */
    public function cancel(int $transactionId, int $merchantId): array
    {
        $repo = $this->transactions->forTenant($merchantId);
        $affected = $repo->markStatusIfNotTerminal($transactionId, 'cancelled');
        $transaction = $repo->findScoped($transactionId);
        if ($transaction === null) {
            throw new \RuntimeException('Failed to retrieve cancelled transaction.');
        }

        if ($affected === 0) {
            return $transaction;
        }

        $this->events->doAction('payment.transaction.cancelled', $transaction);

        return $transaction;
    }

    /**
     * Retrieves aggregated metrics and charts for a specific merchant and date range.
     *
     * @param int $merchantId The unique ID of the merchant.
     * @param string $from The start date filter (YYYY-MM-DD).
     * @param string $to The end date filter (YYYY-MM-DD).
     * @return array<string, mixed> Statistical indicators (totals, volume, status distributions).
     */
    public function stats(int $merchantId, string $from, string $to): array
    {
        return $this->transactions->forTenant($merchantId)->stats($from, $to);
    }

    /**
     * Finds a single transaction by its unique local transaction identifier.
     *
     * @param int $merchantId The unique ID of the merchant/brand.
     * @param string $trxId The unique transaction ID (trx_id).
     * @return array<string, mixed>|null The transaction record fields, or null if not found.
     */
    public function findByTrxId(int $merchantId, string $trxId): ?array
    {
        return $this->transactions->forTenant($merchantId)->findByTrxId($trxId);
    }

    /**
     * Finds a single transaction by its MFS provider transaction identifier.
     *
     * @param int $merchantId The unique ID of the merchant/brand.
     * @param string $providerTrxId The MFS provider's transaction ID reference.
     * @return array<string, mixed>|null The transaction record fields, or null if not found.
     */
    public function findByProviderTrxId(int $merchantId, string $providerTrxId): ?array
    {
        return $this->transactions->forTenant($merchantId)->findByProviderTrxId($providerTrxId);
    }

    /**
     * Finds a transaction using the external gateway reference identifier.
     *
     * This is useful during callback/IPN handling when the local merchant reference is missing or unavailable.
     *
     * @param int $merchantId The unique ID of the merchant/brand.
     * @param string $gatewayTrxId The transaction ID supplied by the external gateway or bank.
     * @return array<string, mixed>|null The transaction record fields, or null if not found.
     */
    public function findByGatewayTrxId(int $merchantId, string $gatewayTrxId): ?array
    {
        return $this->transactions->forTenant($merchantId)->findByGatewayTrxId($gatewayTrxId);
    }
}
