<?php

declare(strict_types=1);

namespace OwnPay\Repository;

/**
 * Repository layer for merchant-defined manual payment gateways (`op_manual_gateways` table).
 *
 * Scopes CRUD operations per active tenant via the TenantScope trait.
 * Manages properties such as custom instructions, input fields configuration, SMS verification patterns,
 * and currency/limits.
 *
 * @package OwnPay\Repository
 */
final class ManualGatewayRepository extends BaseRepository
{
    use TenantScope;

    /**
     * @var string Database table name.
     */
    protected string $table = 'op_manual_gateways';

    /**
     * @var list<string> List of fields that can be mass-assigned.
     */
    protected array $fillable = [
        'merchant_id', 'slug', 'name', 'logo_path', 'qr_code_path', 'colors',
        'input_fields', 'instructions', 'admin_notes', 'sms_verification',
        'sms_sender_pattern', 'sms_regex_template', 'currency',
        'min_amount', 'max_amount', 'sort_order', 'status',
    ];

    /**
     * Finds a manual gateway record by its unique slug under the active tenant context.
     *
     * @param string $slug Unique identifier/slug of the manual gateway.
     * @return array<string, mixed>|null The manual gateway database record, or null if not found.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE slug = :s AND merchant_id = :mid LIMIT 1",
            ['s' => $slug, 'mid' => $this->requireTenant()]
        );
    }

    /**
     * Lists active manual gateway records under the active tenant context.
     *
     * @return array<int, array<string, mixed>> List of active manual gateways.
     */
    public function listActive(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid AND status = 'active' ORDER BY sort_order ASC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Lists all manual gateway records under the active tenant context.
     *
     * @return array<int, array<string, mixed>> List of all manual gateways.
     */
    public function listAll(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE merchant_id = :mid ORDER BY sort_order ASC, id DESC",
            ['mid' => $this->requireTenant()]
        );
    }

    /**
     * Resolves the manual gateways a brand's customer can pay through at checkout, merging the
     * platform-owned templates (All-Brands defaults) with the brand's own account overrides.
     *
     * Money-routing rule (Phase 2c, model A): for each gateway slug the BRAND's own active row wins -
     * its instructions/QR/account are the account the customer's funds go to. When the brand has not
     * configured that slug, the platform template is the fallback (its own default account). Slugs
     * that exist only as brand rows (legacy/brand-only gateways) are preserved. Only 'active' rows on
     * either side are offered. This is the single choke point that decides which account funds reach,
     * so it deliberately bypasses TenantScope to read both owners in one query.
     *
     * @param int $brandId    The paying brand/merchant id (the transaction's merchant_id).
     * @param int $platformId The reserved platform-owner merchant id (BrandContext::getPlatformId()).
     * @return array<int, array<string, mixed>> Effective active manual gateways, sorted by sort_order.
     */
    public function listActiveForCheckout(int $brandId, int $platformId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE status = 'active' AND merchant_id IN (:brand, :platform)
             ORDER BY sort_order ASC, id ASC",
            ['brand' => $brandId, 'platform' => $platformId]
        );

        // Collapse to one effective row per slug, preferring the brand's own account over the
        // platform template. Insertion order (sort_order ASC) is preserved by keeping the slug's
        // first position; the brand row overwrites the value in place when it wins.
        $bySlug = [];
        foreach ($rows as $row) {
            $slugVal = $row['slug'] ?? '';
            $slug = is_string($slugVal) ? $slugVal : '';
            if ($slug === '') {
                continue;
            }
            $ownerVal = $row['merchant_id'] ?? 0;
            $owner = (is_int($ownerVal) || is_string($ownerVal)) ? (int) $ownerVal : 0;
            if (!isset($bySlug[$slug]) || $owner === $brandId) {
                $bySlug[$slug] = $row;
            }
        }

        return array_values($bySlug);
    }
}

