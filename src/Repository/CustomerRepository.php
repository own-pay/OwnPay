<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Repository for ap_customers — customer records per merchant.
 */
class CustomerRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'ap_customers';

    /**
     * Find customer by email within a merchant scope.
     */
    public function findByEmail(int $merchantId, string $email): ?array
    {
        return $this->findOneWhere(
            '`merchant_id` = :mid AND `email` = :email',
            ['mid' => $merchantId, 'email' => $email]
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
        return $this->findWhere(
            '`merchant_id` = :mid AND `status` = :status',
            ['mid' => $merchantId, 'status' => 'active'],
            'created_at DESC',
            $limit
        );
    }
}
