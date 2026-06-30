<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository for op_fee_rules table.
 */
final class FeeRuleRepository extends BaseRepository
{
    use TenantScope;

    protected string $table = 'op_fee_rules';

    protected array $fillable = [
        'merchant_id',
        'gateway_slug',
        'type',
        'value',
        'min_fee',
        'max_fee',
        'currency',
        'tiers',
        'status',
    ];

    /**
     * Resolve the active fee rule prioritised by specificity.
     *
     * @param int $merchantId The merchant/brand ID.
     * @param string $gatewaySlug The payment gateway slug.
     * @param string $currency The transaction currency.
     * @return array<string, mixed>|null The active fee rule array, or null if none matches.
     */
    public function resolveActiveRule(int $merchantId, string $gatewaySlug, string $currency): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table}
              WHERE status = 'active'
                AND currency = :currency
                AND (merchant_id = :merchant_id OR merchant_id IS NULL)
                AND (gateway_slug = :gateway_slug OR gateway_slug IS NULL)
              ORDER BY
                CASE
                  WHEN merchant_id = :merchant_id_ob1 AND gateway_slug = :gateway_slug_ob1 THEN 1
                  WHEN merchant_id = :merchant_id_ob2 AND gateway_slug IS NULL THEN 2
                  WHEN merchant_id IS NULL AND gateway_slug = :gateway_slug_ob2 THEN 3
                  ELSE 4
                END ASC,
                id DESC
              LIMIT 1",
            [
                'merchant_id'         => $merchantId,
                'gateway_slug'        => $gatewaySlug,
                'currency'            => $currency,
                'merchant_id_ob1'     => $merchantId,
                'merchant_id_ob2'     => $merchantId,
                'gateway_slug_ob1'    => $gatewaySlug,
                'gateway_slug_ob2'    => $gatewaySlug,
            ]
        );
    }
}
