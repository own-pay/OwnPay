<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Event\EventManager;
use OwnPay\Repository\AuditLogRepository;
use OwnPay\Repository\TransactionRepository;

/**
 * Transaction service â€” create, complete, fail, cancel transactions.
 *
 * Fires: payment.transaction.before_create, .created, .completed, .failed, .cancelled
 */
final class TransactionService
{
    private TransactionRepository $transactions;
    private EventManager $events;
    private AuditLogRepository $audit;

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
     * Create a new transaction.
     */
    public function create(int $merchantId, array $data): array
    {
        // Pre-create filter â€” plugins can modify data
        $data = $this->events->applyFilter('payment.transaction.before_create', $data, $merchantId);

        $repo = $this->transactions->forTenant($merchantId);
        $id = $repo->createTransaction($data);
        $transaction = $repo->findScoped((int) $id);

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
     * Mark transaction as completed.
     */
    public function complete(int $transactionId, int $merchantId): array
    {
        $repo = $this->transactions->forTenant($merchantId);
        $repo->markCompleted($transactionId);
        $transaction = $repo->findScoped($transactionId);

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
     * Mark transaction as failed.
     */
    public function fail(int $transactionId, int $merchantId, ?string $reason = null): array
    {
        $repo = $this->transactions->forTenant($merchantId);
        $updateData = ['status' => 'failed'];
        if ($reason !== null) {
            $updateData['metadata'] = json_encode(['failure_reason' => $reason]);
        }
        $repo->updateScoped($transactionId, $updateData);
        $transaction = $repo->findScoped($transactionId);

        $this->events->doAction('payment.transaction.failed', $transaction);

        return $transaction;
    }

    /**
     * Cancel transaction.
     */
    public function cancel(int $transactionId, int $merchantId): array
    {
        $repo = $this->transactions->forTenant($merchantId);
        $repo->updateScoped($transactionId, ['status' => 'cancelled']);
        $transaction = $repo->findScoped($transactionId);

        $this->events->doAction('payment.transaction.cancelled', $transaction);

        return $transaction;
    }

    /**
     * Get dashboard stats for date range.
     */
    public function stats(int $merchantId, string $from, string $to): array
    {
        return $this->transactions->forTenant($merchantId)->stats($from, $to);
    }

    /**
     * Find by TRX ID.
     */
    public function findByTrxId(int $merchantId, string $trxId): ?array
    {
        return $this->transactions->forTenant($merchantId)->findByTrxId($trxId);
    }
}
