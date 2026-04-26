<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Middleware\BearerAuthMiddleware;
use OwnPay\Repository\CustomerRepository;

/**
 * GET /v1/customers         — List customers (paginated)
 * GET /v1/customers/{id}    — Get a single customer by UUID
 */
final class CustomerController
{
    private CustomerRepository $customers;

    public function __construct()
    {
        $this->customers = new CustomerRepository();
    }

    /**
     * GET /v1/customers
     */
    public function index(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('view_customer');

        $pagination = JsonResponse::paginationParams();

        $rows = $this->customers->findByMerchant(
            $merchant['merchant_id'],
            $pagination['per_page']
        );

        $total = $this->customers->count(
            '`merchant_id` = :mid AND `status` = :status',
            ['mid' => $merchant['merchant_id'], 'status' => 'active']
        );

        $items = array_map(fn(array $row) => $this->formatCustomer($row), $rows);

        JsonResponse::paginated($items, $pagination['page'], $pagination['per_page'], $total);
    }

    /**
     * GET /v1/customers/{id}
     */
    public function show(array $params): void
    {
        $merchant = (new BearerAuthMiddleware())->guard('view_customer');

        $publicId = $params['id'] ?? '';
        $customer = $this->customers->findByPublicId($publicId);

        if ($customer === null || (int) $customer['merchant_id'] !== $merchant['merchant_id']) {
            JsonResponse::error('NOT_FOUND', 'Customer not found.', 404);
            return;
        }

        JsonResponse::success($this->formatCustomer($customer));
    }

    /**
     * Format a customer row for API response.
     */
    private function formatCustomer(array $row): array
    {
        return [
            'id' => $row['public_id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'status' => $row['status'],
            'metadata' => json_decode($row['metadata'] ?? '{}', true),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
