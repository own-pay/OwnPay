<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_transactions — payment execution records.
 *
 * NOTE: This table is PARTITIONED by created_at. The primary key is
 * composite (id, created_at). Cross-partition lookups should use
 * public_id (UUID) via findByPublicId().
 */
class TransactionRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_transactions';

    /**
     * Find transaction by its unique reference.
     */
    public function findByReference(string $reference): ?array
    {
        return $this->findOneWhere(
            '`reference` = :ref',
            ['ref' => $reference]
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

        return $this->findWhere($where, $params, $orderBy, $limit);
    }

    /**
     * Find transaction by payment intent.
     */
    public function findByPaymentIntent(int $paymentIntentId): ?array
    {
        return $this->findOneWhere(
            '`payment_intent_id` = :piid',
            ['piid' => $paymentIntentId]
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

        // Partitioned table — must include created_at in WHERE
        return $this->update(
            $data,
            '`id` = :where_id AND `created_at` = :where_ca',
            ['where_id' => $id, 'where_ca' => $createdAt]
        );
    }
}
