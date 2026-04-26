<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_transactions — payment execution records.
 *
 * NOTE: This table is PARTITIONED by created_at. The primary key is
 * composite (id, created_at). Cross-partition lookups should use
 * public_id (UUID) via findByPublicId().
 */
class TransactionRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_transactions';

    /**
     * Find transaction by its unique reference.
     */
    public function findByReference(string $reference): ?array
    {
        $tc = $this->tenantCondition();
        return $this->findOneWhere(
            '`reference` = :ref' . $tc,
            array_merge(['ref' => $reference], $this->tenantParams())
        );
    }

    /**
     * Find transactions for a merchant with optional status filter.
     */
    public function findByMerchant(
        int $merchantId,
        ?string $status = null,
        int $limit = 50,
        string $orderBy = 'created_at DESC'
    ): array {
        $where = '`merchant_id` = :mid';
        $params = ['mid' => $merchantId];

        if ($status !== null) {
            $where .= ' AND `status` = :status';
            $params['status'] = $status;
        }

        $tc = $this->tenantCondition();
        $where .= $tc;
        $params = array_merge($params, $this->tenantParams());

        return $this->findWhere($where, $params, $orderBy, $limit);
    }

    /**
     * Find transaction by payment intent.
     */
    public function findByPaymentIntent(int $paymentIntentId): ?array
    {
        $tc = $this->tenantCondition();
        return $this->findOneWhere(
            '`payment_intent_id` = :piid' . $tc,
            array_merge(['piid' => $paymentIntentId], $this->tenantParams())
        );
    }

    /**
     * Update transaction status with gateway response.
     */
    public function updateStatus(
        int $id,
        string $createdAt,
        string $newStatus,
        ?array $gatewayResponse = null
    ): int {
        $data = ['status' => $newStatus];
        if ($gatewayResponse !== null) {
            $data['gateway_response'] = json_encode($gatewayResponse);
        }

        $tc = $this->tenantCondition();
        // Partitioned table — must include created_at in WHERE
        return $this->update(
            $data,
            '`id` = :where_id AND `created_at` = :where_ca' . $tc,
            array_merge(['where_id' => $id, 'where_ca' => $createdAt], $this->tenantParams())
        );
    }
}
