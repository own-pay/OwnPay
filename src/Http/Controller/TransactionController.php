<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Middleware\BearerAuthMiddleware;
use OwnPay\Repository\TransactionRepository;

/**
 * GET /v1/transactions         — List transactions (paginated)
 * GET /v1/transactions/{id}    — Get a single transaction by UUID
 */
final class TransactionController
{
    private TransactionRepository $transactions;

    public function __construct()
    {
        $this->transactions = new TransactionRepository();
    }

    /**
     * GET /v1/transactions
     */
    public function index(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('view_payment');

        $pagination = JsonResponse::paginationParams();
        $status = JsonResponse::queryParam('status');

        $rows = $this->transactions->findByMerchant(
            $merchant['merchant_id'],
            $status,
            $pagination['per_page']
        );

        $total = $this->transactions->count(
            '`merchant_id` = :mid' . ($status ? ' AND `status` = :status' : ''),
            array_filter([
                'mid' => $merchant['merchant_id'],
                'status' => $status,
            ])
        );

        $items = array_map(fn(array $row) => $this->formatTransaction($row), $rows);

        JsonResponse::paginated($items, $pagination['page'], $pagination['per_page'], $total);
    }

    /**
     * GET /v1/transactions/{id}
     */
    public function show(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('view_payment');

        $publicId = $params['id'] ?? '';
        $txn = $this->transactions->findByPublicId($publicId);

        if ($txn === null) {
            JsonResponse::error('NOT_FOUND', 'Transaction not found.', 404);
            return;
        }

        // Scope check: merchant can only see own transactions
        if ((int) $txn['merchant_id'] !== $merchant['merchant_id']) {
            JsonResponse::error('NOT_FOUND', 'Transaction not found.', 404);
            return;
        }

        JsonResponse::success($this->formatTransaction($txn));
    }

    /**
     * Format a transaction row for API response.
     */
    private function formatTransaction(array $row): array
    {
        return [
            'id' => $row['public_id'],
            'reference' => $row['reference'],
            'amount' => $row['amount'],
            'currency' => $row['currency'],
            'status' => $row['status'],
            'gateway_response' => json_decode($row['gateway_response'] ?? '{}', true),
            'customer_info' => json_decode($row['customer_info'] ?? '{}', true),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
