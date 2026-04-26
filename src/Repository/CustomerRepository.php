<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_customers — customer records per merchant.
 */
class CustomerRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_customers';

    /**
     * Find customer by email within a merchant scope.
     */
    public function findByEmail(int $merchantId, string $email): ?array
    {
        $tc = $this->tenantCondition();
        return $this->findOneWhere(
            '`merchant_id` = :mid AND `email` = :email' . $tc,
            array_merge(['mid' => $merchantId, 'email' => $email], $this->tenantParams())
        );
    }

    /**
     * Find or create a customer by email.
     */
    public function findOrCreate(
        int $merchantId,
        string $email,
        ?string $name = null,
        ?string $phone = null
    ): array {
        $existing = $this->findByEmail($merchantId, $email);
        if ($existing !== null) {
            return $existing;
        }

        $id = $this->insert([
            'merchant_id' => $merchantId,
            'email' => $email,
            'name' => $name,
            'phone' => $phone,
            'status' => 'active',
        ]);

        return $this->findById($id);
    }

    /**
     * Find active customers for a merchant.
     */
    public function findByMerchant(int $merchantId, int $limit = 50): array
    {
        $tc = $this->tenantCondition();
        return $this->findWhere(
            '`merchant_id` = :mid AND `status` = :status' . $tc,
            array_merge(['mid' => $merchantId, 'status' => 'active'], $this->tenantParams()),
            'created_at DESC',
            $limit
        );
    }
}
