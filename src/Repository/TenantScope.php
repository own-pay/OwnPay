<?php

declare(strict_types=1);

namespace AnirbanPay\Repository;

/**
 * Provides automatic merchant/tenant scoping for repository queries.
 *
 * Usage:
 *   $repo = (new TransactionRepository())->forMerchant($merchantId);
 *   // All subsequent queries will be scoped to that merchant
 */
trait TenantScope
{
    protected ?string $merchantId = null;

    public function forMerchant(string $merchantId): static
    {
        $clone = clone $this;
        $clone->merchantId = $merchantId;
        return $clone;
    }

    protected function tenantCondition(string $alias = ''): string
    {
        $col = $alias ? "{$alias}.merchant_id" : 'merchant_id';
        return $this->merchantId !== null ? " AND {$col} = :tenant_merchant_id" : '';
    }

    protected function tenantParams(): array
    {
        return $this->merchantId !== null ? [':tenant_merchant_id' => $this->merchantId] : [];
    }
}
