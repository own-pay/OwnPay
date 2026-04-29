<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Middleware\BearerAuthMiddleware;
use OwnPay\Repository\RefundRepository;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Service\Payment\LedgerService;
use OwnPay\Service\System\AuditLogger;

/**
 * POST /v1/refunds         — Create a refund
 * GET  /v1/refunds/{id}    — Get a refund by UUID
 */
final class RefundController
{
    private RefundRepository $refunds;
    private TransactionRepository $transactions;
    private LedgerService $ledger;
    private AuditLogger $audit;

    public function __construct()
    {
        $this->refunds = new RefundRepository();
        $this->transactions = new TransactionRepository();
        $this->ledger = new LedgerService();
        $this->audit = new AuditLogger();
    }

    /**
     * POST /v1/refunds
     */
    public function create(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('create_refund');

        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        $transactionId = $body['transaction_id'] ?? null;
        $amount = $body['amount'] ?? null;
        $reason = $body['reason'] ?? '';

        if ($transactionId === null) {
            JsonResponse::error('MISSING_FIELD', 'The "transaction_id" field is required.', 400);
            return;
        }

        if ($amount === null || !is_numeric($amount)) {
            JsonResponse::error('INVALID_AMOUNT', 'The "amount" field is required and must be numeric.', 400);
            return;
        }

        $amount = number_format((float) $amount, 4, '.', '');

        // Find the transaction
        $txn = $this->transactions->findByPublicId($transactionId);
        if ($txn === null || (int) $txn['merchant_id'] !== $merchant['merchant_id']) {
            JsonResponse::error('NOT_FOUND', 'Transaction not found.', 404);
            return;
        }

        if ($txn['status'] !== 'completed') {
            JsonResponse::error('INVALID_STATE', 'Only completed transactions can be refunded.', 422);
            return;
        }

        // Check refund doesn't exceed original amount
        $alreadyRefunded = $this->refunds->totalRefunded((int) $txn['id']);
        $remaining = bcsub($txn['amount'], $alreadyRefunded, 4);

        if (bccomp($amount, $remaining, 4) > 0) {
            JsonResponse::error(
                'REFUND_EXCEEDS_AMOUNT',
                "Refund amount ({$amount}) exceeds remaining refundable amount ({$remaining}).",
                422
            );
            return;
        }

        // Create the refund
        $refundId = $this->refunds->insert([
            'merchant_id' => $merchant['merchant_id'],
            'transaction_id' => $txn['id'],
            'amount' => $amount,
            'currency' => $txn['currency'],
            'reason' => $reason,
            'status' => 'pending',
        ]);

        $refund = $this->refunds->findById($refundId);

        // Post ledger entry
        $this->ledger->postRefundIssued(
            $merchant['merchant_id'],
            $refund['public_id'],
            $amount,
            $txn['currency']
        );

        // Update refund status to completed
        $this->refunds->updateStatus($refundId, 'completed');
        $refund = $this->refunds->findById($refundId);

        // Audit
        $this->audit->log(
            $merchant['merchant_id'],
            'refund.created',
            'refund',
            $refund['public_id'],
            'api_key',
            $merchant['key_prefix'],
            null,
            ['amount' => $amount, 'transaction_id' => $transactionId]
        );

        JsonResponse::created([
            'id' => $refund['public_id'],
            'transaction_id' => $transactionId,
            'amount' => $refund['amount'],
            'currency' => $refund['currency'],
            'reason' => $refund['reason'],
            'status' => $refund['status'],
            'created_at' => $refund['created_at'],
        ]);
    }

    /**
     * GET /v1/refunds/{id}
     */
    public function show(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('view_payment');

        $publicId = $params['id'] ?? '';
        $refund = $this->refunds->findByPublicId($publicId);

        if ($refund === null || (int) $refund['merchant_id'] !== $merchant['merchant_id']) {
            JsonResponse::error('NOT_FOUND', 'Refund not found.', 404);
            return;
        }

        JsonResponse::success([
            'id' => $refund['public_id'],
            'amount' => $refund['amount'],
            'currency' => $refund['currency'],
            'reason' => $refund['reason'],
            'status' => $refund['status'],
            'created_at' => $refund['created_at'],
        ]);
    }
}
